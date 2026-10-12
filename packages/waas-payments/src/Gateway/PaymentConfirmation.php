<?php

namespace WaaSPayments\Gateway;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SystemChargeService;
use WaaSPayments\Driver\ChargeRequest;
use WaaSPayments\Driver\DriverResult;
use WaaSPayments\Driver\RecurringGatewayDriver;
use WaaSPayments\Driver\SavePaymentMethodRequest;

/**
 * Charges the single-use card token the checkout script got from Authorize.Net, then marks
 * the FluentCart transaction paid. Modelled on the Paystack add-on's confirmation
 * (PaystackConfirmations::confirmPaymentSuccessByCharge).
 *
 * The amount always comes from the stored transaction, never from the browser.
 *
 * For an auto-charged ("system") subscription the card is also saved at Authorize.Net (CIM)
 * so FluentCart can charge the renewals (AuthorizeNetGateway::chargeRenewal). That covers
 * the first order and a renewal invoice the customer pays themselves (the new card then
 * replaces the saved one). Nothing to pay today (a trial) saves the card without a charge.
 */
class PaymentConfirmation
{
    const ACTION = 'waas_payments_authorizenet_confirm';
    const NONCE = 'waas_payments_authorizenet';
    // Longer than the 45-second Authorize.Net request timeout.
    const LOCK_WAIT = 50;

    private $gateway;

    public function __construct(AuthorizeNetGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'handleAjax']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'handleAjax']);
    }

    public function handleAjax(): void
    {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, self::NONCE)) {
            wp_send_json(['status' => 'failed', 'message' => __('Your session expired. Please reload the page and try again.', 'waas-payments')], 403);
        }

        $transactionHash = sanitize_text_field(wp_unslash($_POST['transaction_hash'] ?? ''));
        if ($transactionHash === '' || !self::lock($transactionHash)) {
            wp_send_json(['status' => 'failed', 'message' => __('This payment is already being processed. Please wait a moment.', 'waas-payments')], 409);
        }

        try {
            // Loaded after taking the lock, so a second request sees the first one's result.
            $transaction = OrderTransaction::query()
                ->where('uuid', $transactionHash)
                ->where('payment_method', AuthorizeNetGateway::ROUTE)
                ->first();

            // The token is opaque and must reach Authorize.Net unmodified; it is only ever sent on to them.
            $result = $this->confirm(
                $transaction,
                sanitize_text_field(wp_unslash($_POST['data_descriptor'] ?? '')),
                trim(wp_unslash((string) ($_POST['data_value'] ?? ''))),
                sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''))
            );
        } finally {
            self::unlock($transactionHash);
        }

        wp_send_json($result['body'], $result['status']);
    }

    /**
     * One charge attempt per transaction at a time, with a MySQL named lock: only one
     * connection can hold it, and the database drops it if the request dies mid-charge.
     * A second request waits for the first (up to LOCK_WAIT seconds) and then sees its result.
     */
    public static function lock(string $transactionHash): bool
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::lockName($transactionHash), self::LOCK_WAIT)) === '1';
    }

    public static function unlock(string $transactionHash): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lockName($transactionHash)));
    }

    private static function lockName(string $transactionHash): string
    {
        return 'waas_payments_' . md5($transactionHash);
    }

    /**
     * @param OrderTransaction|null $transaction
     * @return array{status:int, body:array}
     */
    public function confirm($transaction, string $tokenDescriptor, string $tokenValue, string $customerIp = ''): array
    {
        if (!$transaction) {
            return self::reply(404, 'failed', __('Payment not found. Please reload the page and try again.', 'waas-payments'));
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return $this->paid($transaction);
        }

        if (!in_array($transaction->status, [Status::TRANSACTION_PENDING, Status::TRANSACTION_FAILED], true)) {
            return self::reply(409, 'failed', __('This payment can no longer be completed. Please contact the store.', 'waas-payments'));
        }

        if ($tokenDescriptor !== AuthorizeNetGateway::TOKEN_DESCRIPTOR || $tokenValue === '') {
            return self::reply(400, 'failed', __('The card form did not return a payment token. Please try again.', 'waas-payments'));
        }

        $order = $transaction->order;
        $customer = $order->customer;
        $storeName = (new StoreSettings())->get('store_name');
        // FluentCart assigns invoice numbers once an order is paid, so a new order has none yet.
        $orderNumber = (string) ($order->invoice_no ?: $order->id);
        $sandbox = $this->gateway->settings->isSandbox();
        $driver = $this->gateway->driver($sandbox);

        $subscription = self::autoChargedSubscription($transaction);
        $saveCard = $subscription && $driver instanceof RecurringGatewayDriver;
        $savedCard = $subscription ? SavedCard::read($subscription) : null;
        // A saved card from the other environment can't be reached with these credentials.
        $customerRef = $savedCard && $savedCard['env'] === ($sandbox ? 'sandbox' : 'production') ? $savedCard['customer_ref'] : '';
        $customerEmail = (string) ($order->email ?: ($customer->email ?? ''));

        if ((int) $transaction->total <= 0) {
            if (!$saveCard) {
                return self::reply(422, 'failed', __('There is nothing to pay for this order.', 'waas-payments'));
            }

            $result = $driver->savePaymentMethod(new SavePaymentMethodRequest($tokenDescriptor, $tokenValue, $customerRef, [
                'customer_id'    => (string) ($order->customer_id ?? ''),
                'customer_email' => $customerEmail,
                'billing'        => self::billingAddress($order),
            ]));
        } else {
            $result = $driver->charge(new ChargeRequest(
                (int) $transaction->total,
                $tokenDescriptor,
                $tokenValue,
                [
                    'invoice_number'      => $orderNumber,
                    'description'         => trim($storeName . ' #' . $orderNumber),
                    'customer_email'      => $customerEmail,
                    'customer_id'         => (string) ($order->customer_id ?? ''),
                    'customer_ip'         => $customerIp,
                    'save_payment_method' => $saveCard,
                    'customer_ref'        => $customerRef,
                ]
            ));
        }

        if (!$result->success) {
            fluent_cart_add_log(
                __('Authorize.Net payment not completed', 'waas-payments'),
                sprintf('%s (code %s%s)', $result->message, $result->code, $result->transactionId ? ', transaction ' . $result->transactionId : ''),
                'error',
                ['module_name' => 'order', 'module_id' => $order->id]
            );

            return self::reply(422, 'failed', $result->message ?: __('Payment failed. Please try again.', 'waas-payments'));
        }

        self::recordPayment($transaction, $result, $sandbox);

        if ($subscription) {
            $this->saveCard($subscription, $result, $sandbox, $saveCard);
        }

        // Updates the order's paid total and payment/order status (app/Helpers/StatusHelper.php:164);
        // for a subscription this activates it, or renews it for a renewal invoice.
        (new StatusHelper($order))->syncOrderStatuses($transaction);

        return $this->paid($transaction);
    }

    /**
     * Marks the transaction paid with what Authorize.Net returned. Also used for renewals
     * charged without the customer (RenewalCharge). A card saved without a charge has no
     * Authorize.Net transaction.
     */
    public static function recordPayment($transaction, DriverResult $result, bool $sandbox): void
    {
        $meta = is_array($transaction->meta) ? $transaction->meta : [];
        unset($meta[RenewalCharge::IN_FLIGHT_META], $meta[RenewalCharge::HELD_META]);

        $transaction->fill([
            'status'              => Status::TRANSACTION_SUCCEEDED,
            'vendor_charge_id'    => $result->transactionId,
            'card_last_4'         => $result->data['card_last4'] ?? '',
            'card_brand'          => $result->data['card_brand'] ?? '',
            'payment_method_type' => 'card',
            'meta'                => array_merge($meta, [
                // Refunds must go to the environment that took the charge, even if sandbox mode changes later.
                'authorizenet_env' => $sandbox ? 'sandbox' : 'production',
                'auth_code'        => $result->data['auth_code'] ?? '',
                'avs_result'       => $result->data['avs_result'] ?? '',
                'cvv_result'       => $result->data['cvv_result'] ?? '',
            ]),
        ]);
        $transaction->save();

        $order = $transaction->order;
        fluent_cart_add_log(
            __('Authorize.Net payment captured', 'waas-payments'),
            $result->transactionId
                ? sprintf(__('Authorize.Net transaction %s', 'waas-payments'), $result->transactionId)
                : __('Card saved at Authorize.Net; nothing was charged.', 'waas-payments'),
            'info',
            ['module_name' => 'order', 'module_id' => $order ? $order->id : 0]
        );
    }

    /**
     * The subscription of this transaction when FluentCart auto-charges its renewals to a
     * saved card (collection_method "system"). Manual-billing subscriptions save nothing:
     * the customer pays every renewal invoice at checkout.
     *
     * @return Subscription|null
     */
    public static function autoChargedSubscription($transaction)
    {
        if (empty($transaction->subscription_id)) {
            return null;
        }

        $subscription = Subscription::query()->where('id', $transaction->subscription_id)->first();

        return $subscription && $subscription->collection_method === 'system' ? $subscription : null;
    }

    /**
     * The order's billing address in the driver's shape. With a full address Authorize.Net
     * checks a card it saves without a charge.
     */
    public static function billingAddress($order): array
    {
        $address = $order ? $order->billing_address : null;
        if (!$address) {
            return [];
        }

        return [
            'first_name' => (string) $address->first_name,
            'last_name'  => (string) $address->last_name,
            'address'    => (string) $address->address_1,
            'city'       => (string) $address->city,
            'state'      => (string) $address->state,
            'zip'        => (string) $address->postcode,
            'country'    => (string) $address->country,
        ];
    }

    /**
     * Saves the card of a subscription's payment for its renewals, or, if it couldn't be saved,
     * keeps the card saved before or has the customer pay renewals by invoice. Also used by
     * WebhookSync for a first payment confirmed late.
     */
    public function saveCard($subscription, DriverResult $result, bool $sandbox, bool $canSave): void
    {
        if ($canSave && !empty($result->data['method_ref'])) {
            $replaced = SavedCard::store($subscription, $result, $sandbox);
            $subscription->addLog(
                __('Card saved for renewals', 'waas-payments'),
                sprintf(__('%1$s ending in %2$s will be charged automatically on each renewal date.', 'waas-payments'), $result->data['card_brand'] ?? '', $result->data['card_last4'] ?? ''),
                'info'
            );

            if ($replaced) {
                self::deleteCard($this->gateway, $replaced, $subscription->id);
                // The previous card's decline no longer applies.
                SystemChargeService::clearFailureState($subscription);
            }
            return;
        }

        $reason = $result->data['save_error'] ?? __('this payment gateway cannot save cards', 'waas-payments');

        if (SavedCard::read($subscription)) {
            // A renewal paid with a card that couldn't be saved: renewals keep using the saved one.
            $subscription->addLog(
                __('New card not saved', 'waas-payments'),
                sprintf(__('The payment went through, but Authorize.Net did not save the card (%s). Renewals still use the card saved before.', 'waas-payments'), $reason),
                'warning'
            );
            return;
        }

        // Never leave an auto-charged subscription without a card: FluentCart sends renewal
        // invoices for the customer to pay instead.
        SystemChargeService::demoteToManual(
            $subscription,
            sprintf(__('Authorize.Net did not save the card for automatic renewals (%s).', 'waas-payments'), $reason)
        );
    }

    /**
     * Deletes a replaced card at Authorize.Net unless another subscription still renews on it. Best effort: a card
     * left behind is never charged.
     */
    public static function deleteCard(AuthorizeNetGateway $gateway, array $card, $subscriptionId): void
    {
        if (SavedCard::isUsedByAnotherSubscription($card, $subscriptionId)) {
            return;
        }

        $driver = $gateway->driver($card['env'] === 'sandbox');
        if ($driver instanceof RecurringGatewayDriver) {
            $driver->deleteSavedPaymentMethod($card['customer_ref'], $card['method_ref']);
        }
    }

    private function paid($transaction): array
    {
        return [
            'status' => 200,
            'body'   => [
                'status'       => 'success',
                'message'      => __('Payment confirmed. Redirecting...', 'waas-payments'),
                'redirect_url' => $transaction->getReceiptPageUrl(),
            ],
        ];
    }

    private static function reply(int $status, string $result, string $message): array
    {
        return ['status' => $status, 'body' => ['status' => $result, 'message' => $message]];
    }
}

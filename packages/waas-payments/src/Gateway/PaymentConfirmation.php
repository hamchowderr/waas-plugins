<?php

namespace WaaSPayments\Gateway;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use WaaSPayments\Driver\ChargeRequest;

/**
 * Charges the single-use card token the checkout script got from Authorize.Net, then marks
 * the FluentCart transaction paid. Modelled on the Paystack add-on's confirmation
 * (PaystackConfirmations::confirmPaymentSuccessByCharge).
 *
 * The amount always comes from the stored transaction, never from the browser.
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

        $result = $this->gateway->driver($sandbox)->charge(new ChargeRequest(
            (int) $transaction->total,
            $tokenDescriptor,
            $tokenValue,
            [
                'invoice_number' => $orderNumber,
                'description'    => trim($storeName . ' #' . $orderNumber),
                'customer_email' => (string) ($order->email ?: ($customer->email ?? '')),
                'customer_id'    => (string) ($order->customer_id ?? ''),
                'customer_ip'    => $customerIp,
            ]
        ));

        if (!$result->success) {
            fluent_cart_add_log(
                __('Authorize.Net payment not completed', 'waas-payments'),
                sprintf('%s (code %s%s)', $result->message, $result->code, $result->transactionId ? ', transaction ' . $result->transactionId : ''),
                'error',
                ['module_name' => 'order', 'module_id' => $order->id]
            );

            return self::reply(422, 'failed', $result->message ?: __('Payment failed. Please try again.', 'waas-payments'));
        }

        $meta = is_array($transaction->meta) ? $transaction->meta : [];
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

        fluent_cart_add_log(
            __('Authorize.Net payment captured', 'waas-payments'),
            sprintf(__('Authorize.Net transaction %s', 'waas-payments'), $result->transactionId),
            'info',
            ['module_name' => 'order', 'module_id' => $order->id]
        );

        // Updates the order's paid total and payment/order status (app/Helpers/StatusHelper.php:164).
        (new StatusHelper($order))->syncOrderStatuses($transaction);

        return $this->paid($transaction);
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

<?php

namespace WaaSPayments\Gateway;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Api\StoreSettings;
use WaaSPayments\Driver\DriverResult;
use WaaSPayments\Driver\RecurringGatewayDriver;
use WaaSPayments\Driver\SavedChargeRequest;

/**
 * Charges a renewal invoice of an auto-charged subscription to its saved card, for
 * FluentCart's SystemChargeService (app/Modules/Subscriptions/Services/SystemChargeService.php).
 * FluentCart owns the schedule: it creates the invoice, calls charge() on the due date, retries
 * on failure, and moves the subscription to past_due and expired when the retries run out.
 *
 * The contract (Core/AbstractPaymentGateway.php chargeRenewal / reconcileRenewalCharge):
 * true = paid (confirmed through the normal path, so FluentCart renews the subscription);
 * 'processing' = the outcome isn't known yet, FluentCart calls reconcile() daily;
 * WP_Error = not paid, FluentCart schedules a retry.
 *
 * A request Authorize.Net never answered might still have charged the card, so it is
 * 'processing', not an error: an error would make FluentCart retry and maybe charge twice.
 * reconcile() then looks the charge up by its invoice number.
 */
class RenewalCharge
{
    /** Transaction meta: a charge was sent and got no answer. */
    const IN_FLIGHT_META = 'authorizenet_renewal_in_flight';

    /** Transaction meta: Authorize.Net's fraud filters hold the charge for the merchant's review. */
    const HELD_META = 'authorizenet_held_for_review';

    private $gateway;

    public function __construct(AuthorizeNetGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * @param \FluentCart\App\Services\Payments\PaymentInstance $paymentInstance
     * @return true|string|\WP_Error
     */
    public function charge($paymentInstance, int $attempt = 1)
    {
        $order = $paymentInstance->order;
        $subscription = $paymentInstance->subscription;
        $transaction = $paymentInstance->transaction;

        if (!$order || !$subscription || !$transaction) {
            return new \WP_Error('invalid_instance', __('The renewal order is missing its subscription or transaction.', 'waas-payments'));
        }

        $card = SavedCard::read($subscription);
        if (!$card) {
            return new \WP_Error('missing_token', __('No saved card is available for this subscription.', 'waas-payments'));
        }

        $driver = $this->driverFor($card);
        if (is_wp_error($driver)) {
            return $driver;
        }

        // The same lock as the checkout confirmation: the customer may be paying this invoice
        // themselves right now. Whoever comes second sees the first one's result.
        if (!PaymentConfirmation::lock((string) $transaction->uuid)) {
            return 'processing';
        }

        try {
            $transaction = self::fresh($transaction);

            if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
                (new StatusHelper($order))->syncOrderStatuses($transaction);
                return true;
            }

            $meta = is_array($transaction->meta) ? $transaction->meta : [];
            if (!empty($meta[self::IN_FLIGHT_META]) || !empty($meta[self::HELD_META])) {
                // An earlier charge may have gone through: find out instead of charging again.
                return $this->settle($transaction, $order, $card, $driver);
            }

            $invoiceNumber = self::invoiceNumber($order);
            $meta[self::IN_FLIGHT_META] = [
                'invoice_number' => $invoiceNumber,
                'amount'         => (int) $transaction->total,
                'attempt'        => $attempt,
                'sent_at'        => gmdate('Y-m-d H:i:s'),
            ];
            $transaction->meta = $meta;
            $transaction->save();

            $result = $driver->chargeSaved(new SavedChargeRequest((int) $transaction->total, $card['customer_ref'], $card['method_ref'], [
                'invoice_number'                  => $invoiceNumber,
                'description'                     => trim((new StoreSettings())->get('store_name') . ' #' . $invoiceNumber),
                'original_network_transaction_id' => $card['network_trans_id'],
            ]));

            if (!$result->success && $result->code === 'http_error') {
                $this->log($order, __('Authorize.Net renewal charge unanswered', 'waas-payments'), $result->message . ' ' . __('The charge will be looked up before anything is charged again.', 'waas-payments'), 'warning');
                return 'processing';
            }

            unset($meta[self::IN_FLIGHT_META]);
            $transaction->meta = $meta;

            return $this->outcome($transaction, $order, $result, $card);
        } finally {
            PaymentConfirmation::unlock((string) $transaction->uuid);
        }
    }

    /**
     * Daily check of a charge that was 'processing'.
     *
     * @return true|string|\WP_Error
     */
    public function reconcile($paymentInstance)
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $card = SavedCard::read($paymentInstance->subscription);

        if (!$order || !$transaction || !$card) {
            return new \WP_Error('invalid_instance', __('The renewal order is missing its saved card or transaction.', 'waas-payments'));
        }

        $driver = $this->driverFor($card);
        if (is_wp_error($driver)) {
            return $driver;
        }

        if (!PaymentConfirmation::lock((string) $transaction->uuid)) {
            return 'processing';
        }

        try {
            $transaction = self::fresh($transaction);

            if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
                (new StatusHelper($order))->syncOrderStatuses($transaction);
                return true;
            }

            return $this->settle($transaction, $order, $card, $driver);
        } finally {
            PaymentConfirmation::unlock((string) $transaction->uuid);
        }
    }

    /**
     * Finds out what happened to a held or unanswered charge.
     *
     * @return true|string|\WP_Error
     */
    private function settle($transaction, $order, array $card, RecurringGatewayDriver $driver)
    {
        $meta = is_array($transaction->meta) ? $transaction->meta : [];

        if (!empty($meta[self::HELD_META]) && $transaction->vendor_charge_id) {
            $found = $driver->chargeStatus((string) $transaction->vendor_charge_id);
        } elseif (!empty($meta[self::IN_FLIGHT_META])) {
            $sent = $meta[self::IN_FLIGHT_META];
            $found = $driver->findSavedCharge($card['customer_ref'], (string) ($sent['invoice_number'] ?? ''), (int) ($sent['amount'] ?? $transaction->total));
        } else {
            return new \WP_Error('missing_charge', __('No Authorize.Net charge is waiting for this renewal order.', 'waas-payments'));
        }

        if (!$found->success) {
            if ($found->code === 'not_found') {
                // The unanswered request never reached Authorize.Net: safe to charge again.
                unset($meta[self::IN_FLIGHT_META]);
                $transaction->meta = $meta;
                $transaction->save();
                return new \WP_Error('charge_not_found', __('The renewal charge never reached Authorize.Net, so the card was not charged.', 'waas-payments'));
            }
            // Can't tell yet (Authorize.Net unreachable): ask again tomorrow.
            return 'processing';
        }

        $state = $found->data['state'] ?? RecurringGatewayDriver::STATE_FAILED;

        if ($state === RecurringGatewayDriver::STATE_PAID) {
            return $this->outcome($transaction, $order, $found, $card);
        }

        if ($state === RecurringGatewayDriver::STATE_PENDING) {
            unset($meta[self::IN_FLIGHT_META]);
            $meta[self::HELD_META] = 1;
            $transaction->meta = $meta;
            $transaction->vendor_charge_id = $found->transactionId;
            $transaction->save();
            return 'processing';
        }

        unset($meta[self::IN_FLIGHT_META], $meta[self::HELD_META]);
        $transaction->meta = $meta;
        $transaction->save();

        $reason = (string) ($found->data['reason'] ?? '') ?: (string) $found->message;
        $this->log($order, __('Authorize.Net renewal charge failed', 'waas-payments'), sprintf('%s (transaction %s)', $reason, $found->transactionId), 'error');

        /* translators: %s: why the charge failed */
        return new \WP_Error('charge_failed', sprintf(__('Authorize.Net did not complete the charge: %s', 'waas-payments'), $reason));
    }

    /**
     * @return true|string|\WP_Error
     */
    private function outcome($transaction, $order, DriverResult $result, array $card)
    {
        if ($result->success) {
            if (empty($result->data['card_last4'])) {
                $result->data['card_last4'] = $card['last_4'];
                $result->data['card_brand'] = $card['brand'];
            }
            PaymentConfirmation::recordPayment($transaction, $result, $card['env'] === 'sandbox');
            // Marks the invoice paid; FluentCart's renewal_paid listeners renew the subscription.
            (new StatusHelper($order))->syncOrderStatuses($transaction);
            return true;
        }

        if ($result->code === 'held_for_review') {
            $meta = is_array($transaction->meta) ? $transaction->meta : [];
            $meta[self::HELD_META] = 1;
            $transaction->meta = $meta;
            $transaction->vendor_charge_id = $result->transactionId;
            $transaction->save();
            /* translators: %s: Authorize.Net transaction ID */
            $this->log($order, __('Authorize.Net renewal charge held for review', 'waas-payments'), sprintf(__('Approve or decline transaction %s in Authorize.Net.', 'waas-payments'), $result->transactionId), 'warning');
            return 'processing';
        }

        $transaction->save();
        $this->log($order, __('Authorize.Net renewal charge declined', 'waas-payments'), sprintf('%s (code %s%s)', $result->message, $result->code, $result->transactionId ? ', transaction ' . $result->transactionId : ''), 'error');

        return new \WP_Error('charge_failed', $result->message ?: __('Authorize.Net did not approve the charge.', 'waas-payments'));
    }

    /**
     * The driver for the environment the card was saved in. A card saved in the sandbox
     * can't be charged with live credentials, nor the other way round.
     *
     * @return RecurringGatewayDriver|\WP_Error
     */
    private function driverFor(array $card)
    {
        $sandbox = $this->gateway->settings->isSandbox();
        if ($card['env'] !== '' && $card['env'] !== ($sandbox ? 'sandbox' : 'production')) {
            return new \WP_Error('environment_mismatch', sprintf(
                /* translators: 1: mode the card was saved in (live or sandbox), 2: mode the store uses */
                __('The saved card was saved in Authorize.Net %1$s mode, but the store is set to %2$s mode.', 'waas-payments'),
                $card['env'],
                $sandbox ? 'sandbox' : 'production'
            ));
        }

        $driver = $this->gateway->driver($sandbox);
        if (!$driver instanceof RecurringGatewayDriver) {
            return new \WP_Error('not_supported', __('This payment gateway cannot charge saved cards.', 'waas-payments'));
        }

        return $driver;
    }

    /** What Authorize.Net shows as the invoice number (20 characters at most). */
    public static function invoiceNumber($order): string
    {
        return substr((string) ($order->invoice_no ?: $order->id), 0, 20);
    }

    /** Re-read inside the lock, so a payment that finished meanwhile is seen. */
    private static function fresh($transaction)
    {
        $fresh = $transaction->id ? OrderTransaction::query()->where('id', $transaction->id)->first() : null;

        return $fresh ?: $transaction;
    }

    private function log($order, string $title, string $content, string $level): void
    {
        fluent_cart_add_log($title, $content, $level, ['module_name' => 'order', 'module_id' => $order->id]);
    }
}

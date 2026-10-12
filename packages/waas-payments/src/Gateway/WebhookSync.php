<?php

namespace WaaSPayments\Gateway;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\Refund;
use WaaSPayments\Driver\DriverResult;
use WaaSPayments\Driver\RecurringGatewayDriver;

/**
 * Brings one FluentCart payment in line with an Authorize.Net transaction, for Webhook.
 * The transaction is always read back from Authorize.Net (getTransactionDetails); the
 * notification only says which one to look at, so a repeated or late one changes nothing.
 *
 * - Refund (made here or in the Merchant Interface): recorded on its charge, unless FluentCart
 *   already has it (Refund::createOrRecordRefund matches by refund ID, or amount for one in flight).
 * - Voided charge: the rest of the charge recorded as refunded.
 * - Approved charge that FluentCart still shows unpaid (the answer was lost, or the merchant
 *   approved a charge held for review): marked paid, as the checkout would have.
 * - Declined held renewal: handed to FluentCart's daily renewal check, run now.
 *
 * Several stores can share one Authorize.Net account, and each gets every notification. A charge
 * found by invoice number (the order number, not unique across stores) must also match the
 * amount and the customer, or the saved card, before anything changes.
 */
class WebhookSync
{
    // FluentCart's daily check of a renewal charge that is 'processing'
    // (SystemChargeService::RECONCILE_HOOK, app/Modules/Subscriptions/Services/SystemChargeService.php).
    const RECONCILE_HOOK = 'fluent_cart/subscriptions/system_charge_reconcile';

    // getTransactionDetails transactionStatus of a refund that went through.
    const REFUNDED_STATUSES = ['refundPendingSettlement', 'refundSettledSuccessfully'];

    private $gateway;

    public function __construct(AuthorizeNetGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * @return string|\WP_Error What was done, or an error worth retrying (Authorize.Net unreachable).
     */
    public function sync(string $transactionId)
    {
        $driver = $this->gateway->driver();
        if (!$driver instanceof RecurringGatewayDriver) {
            return 'This gateway cannot look up transactions.';
        }

        $found = $driver->chargeStatus($transactionId);

        if (!$found->success) {
            if ($found->code === 'http_error') {
                return new \WP_Error('authorizenet_unreachable', $found->message);
            }
            // Not on this account (or this environment): another store's, or a forged ID.
            return 'Unknown transaction.';
        }

        if (($found->data['transaction_type'] ?? '') === 'refundTransaction') {
            return $this->syncRefund($found);
        }

        return $this->syncCharge($found);
    }

    private function syncRefund(DriverResult $refund): string
    {
        if (!in_array($refund->data['transaction_status'] ?? '', self::REFUNDED_STATUSES, true)) {
            return 'Refund not completed.';
        }

        $charge = self::chargeByVendorId((string) ($refund->data['ref_trans_id'] ?? ''));
        if (!$charge) {
            return 'Not a payment of this store.';
        }

        return $this->recordRefund($charge, $refund->transactionId, (int) ($refund->data['auth_amount'] ?? 0), __('Refunded in Authorize.Net', 'waas-payments'));
    }

    private function syncCharge(DriverResult $found): string
    {
        $transaction = self::chargeByVendorId($found->transactionId) ?: $this->chargeByInvoice($found);
        if (!$transaction) {
            return 'Not a payment of this store.';
        }

        $status = (string) ($found->data['transaction_status'] ?? '');
        $state = $found->data['state'] ?? RecurringGatewayDriver::STATE_FAILED;

        if ($status === 'voided') {
            if ($transaction->status === Status::TRANSACTION_SUCCEEDED && $transaction->vendor_charge_id === $found->transactionId) {
                return $this->recordVoid($transaction, $found);
            }
        }

        if ($state === RecurringGatewayDriver::STATE_PAID) {
            return $this->markPaid($transaction, $found);
        }

        if ($state === RecurringGatewayDriver::STATE_PENDING) {
            return 'Still held for review.';
        }

        // Declined, voided or failed before FluentCart recorded it as paid.
        if (self::isProcessingRenewal($transaction)) {
            // RenewalCharge::reconcile reads the outcome and FluentCart schedules the retry.
            do_action(self::RECONCILE_HOOK, (int) $transaction->order_id);
            return 'Renewal check run.';
        }

        if ($transaction->status !== Status::TRANSACTION_SUCCEEDED) {
            self::log($transaction, __('Authorize.Net charge not completed', 'waas-payments'), sprintf(
                __('Authorize.Net transaction %1$s is %2$s. The order stays unpaid.', 'waas-payments'),
                $found->transactionId,
                $status
            ), 'warning');
        }

        return 'Charge not completed.';
    }

    /** An approved charge: marks the FluentCart payment paid if it isn't yet. */
    private function markPaid($transaction, DriverResult $found): string
    {
        if (!PaymentConfirmation::lock((string) $transaction->uuid)) {
            return 'Payment busy.';
        }

        try {
            $transaction = self::fresh($transaction);

            if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
                if ((string) $transaction->vendor_charge_id !== $found->transactionId) {
                    // The checkout was paid by another charge, e.g. the customer tried again
                    // after this one went unanswered or was held.
                    self::log($transaction, __('Second Authorize.Net charge for this order', 'waas-payments'), sprintf(
                        __('Authorize.Net transaction %1$s also charged this order, which was already paid by transaction %2$s. Refund or void one of them in Authorize.Net if the customer was charged twice.', 'waas-payments'),
                        $found->transactionId,
                        $transaction->vendor_charge_id
                    ), 'warning');
                    return 'Already paid by another charge.';
                }
                return 'Already paid.';
            }

            if (!in_array($transaction->status, [Status::TRANSACTION_PENDING, Status::TRANSACTION_FAILED], true)) {
                return 'Payment no longer open.';
            }

            $sandbox = $this->gateway->settings->isSandbox();
            PaymentConfirmation::recordPayment($transaction, $found, $sandbox);
            $this->saveCard($transaction, $found, $sandbox);

            // Marks the order paid; for a renewal, FluentCart's renewal_paid listeners renew the
            // subscription and clear its processing charge.
            (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);

            self::log($transaction, __('Authorize.Net payment confirmed by webhook', 'waas-payments'), sprintf(
                __('Authorize.Net reported transaction %s as approved after the payment was left unconfirmed.', 'waas-payments'),
                $found->transactionId
            ), 'info');

            return 'Marked paid.';
        } finally {
            PaymentConfirmation::unlock((string) $transaction->uuid);
        }
    }

    /**
     * The first payment of an auto-charged subscription saves its card. When that payment is
     * only confirmed now, save the card from the charge, as PaymentConfirmation would have.
     */
    private function saveCard($transaction, DriverResult $found, bool $sandbox): void
    {
        $subscription = PaymentConfirmation::autoChargedSubscription($transaction);
        if (!$subscription || SavedCard::read($subscription)) {
            return;
        }

        $driver = $this->gateway->driver($sandbox);
        $saved = $driver instanceof RecurringGatewayDriver
            ? $driver->saveFromCharge($found->transactionId, '')
            : DriverResult::failure(__('this payment gateway cannot save cards', 'waas-payments'));

        $result = clone $found;
        if ($saved->success) {
            $result->data['customer_ref'] = $saved->data['customer_ref'];
            $result->data['method_ref'] = $saved->data['method_ref'];
        } else {
            $result->data['method_ref'] = '';
            $result->data['save_error'] = $saved->message;
        }

        (new PaymentConfirmation($this->gateway))->saveCard($subscription, $result, $sandbox, $driver instanceof RecurringGatewayDriver);
    }

    private function recordVoid($charge, DriverResult $found): string
    {
        $meta = is_array($charge->meta) ? $charge->meta : [];
        $remaining = (int) $charge->total - (int) ($meta['refunded_total'] ?? 0);

        if ($remaining <= 0) {
            return 'Already refunded.';
        }

        // A void keeps the charge's transaction ID, which is also what processRefund returns for one.
        return $this->recordRefund($charge, $found->transactionId, $remaining, __('Voided in Authorize.Net', 'waas-payments'));
    }

    private function recordRefund($charge, string $refundId, int $amount, string $reason): string
    {
        if ($amount <= 0) {
            return 'No refund amount.';
        }

        // The same lock as payments of this charge, so two deliveries of one refund can't both record it.
        if (!PaymentConfirmation::lock((string) $charge->uuid)) {
            return 'Payment busy.';
        }

        try {
            $charge = self::fresh($charge);
            $refund = Refund::createOrRecordRefund([
                'vendor_charge_id' => $refundId,
                'payment_method'   => AuthorizeNetGateway::ROUTE,
                'total'            => $amount,
                'reason'           => $reason,
            ], $charge);
        } finally {
            PaymentConfirmation::unlock((string) $charge->uuid);
        }

        if (is_wp_error($refund)) {
            return $refund->get_error_message();
        }

        if (!empty($refund->wasRecentlyCreated)) {
            self::log($charge, __('Authorize.Net refund recorded', 'waas-payments'), sprintf(
                __('%1$s: transaction %2$s, %3$s.', 'waas-payments'),
                $reason,
                $refundId,
                number_format($amount / 100, 2)
            ), 'info');
            return 'Refund recorded.';
        }

        return 'Refund already recorded.';
    }

    /** This gateway's charge with this Authorize.Net transaction ID. */
    private static function chargeByVendorId(string $transactionId)
    {
        if ($transactionId === '') {
            return null;
        }

        return OrderTransaction::query()
            ->where('vendor_charge_id', $transactionId)
            ->where('payment_method', AuthorizeNetGateway::ROUTE)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->first();
    }

    /**
     * The charge an Authorize.Net transaction paid, found by its invoice number: the order's
     * invoice number, or its ID for a first payment (PaymentConfirmation, RenewalCharge::invoiceNumber).
     * An unpaid one comes first.
     */
    private function chargeByInvoice(DriverResult $found)
    {
        $invoice = (string) ($found->data['invoice_number'] ?? '');
        if ($invoice === '') {
            return null;
        }

        $orders = array_filter([
            Order::query()->where('invoice_no', $invoice)->first(),
            ctype_digit($invoice) ? Order::query()->where('id', (int) $invoice)->first() : null,
        ]);

        $matches = [];
        foreach ($orders as $order) {
            $charges = OrderTransaction::query()
                ->where('order_id', $order->id)
                ->where('payment_method', AuthorizeNetGateway::ROUTE)
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->get();

            foreach ($charges as $charge) {
                if ((int) $charge->total === (int) ($found->data['auth_amount'] ?? -1) && self::sameCustomer($charge, $order, $found)) {
                    $matches[] = $charge;
                }
            }
        }

        usort($matches, fn ($a, $b) => ($a->status === Status::TRANSACTION_SUCCEEDED) <=> ($b->status === Status::TRANSACTION_SUCCEEDED));

        return $matches[0] ?? null;
    }

    /**
     * Whether the Authorize.Net transaction was made for this order's customer: the saved card
     * of its subscription (a renewal), or the customer ID and email sent with a checkout charge.
     */
    private static function sameCustomer($charge, $order, DriverResult $found): bool
    {
        $customerRef = (string) ($found->data['customer_ref'] ?? '');
        if ($customerRef !== '') {
            $subscription = PaymentConfirmation::autoChargedSubscription($charge);
            $card = $subscription ? SavedCard::read($subscription) : null;
            return $card && $card['customer_ref'] === $customerRef;
        }

        // The email PaymentConfirmation sends: FluentCart orders take it from their customer.
        $email = strtolower(trim((string) (($order->email ?? '') ?: ($order->customer->email ?? ''))));

        return $email !== ''
            && strtolower(trim((string) ($found->data['customer_email'] ?? ''))) === $email
            && (string) ($found->data['customer_id'] ?? '') === (string) ($order->customer_id ?? '');
    }

    private static function isProcessingRenewal($transaction): bool
    {
        $meta = is_array($transaction->meta) ? $transaction->meta : [];

        return !empty($meta[RenewalCharge::IN_FLIGHT_META]) || !empty($meta[RenewalCharge::HELD_META]);
    }

    private static function fresh($transaction)
    {
        $fresh = $transaction->id ? OrderTransaction::query()->where('id', $transaction->id)->first() : null;

        return $fresh ?: $transaction;
    }

    private static function log($transaction, string $title, string $content, string $level): void
    {
        fluent_cart_add_log($title, $content, $level, ['module_name' => 'order', 'module_id' => (int) $transaction->order_id]);
    }
}

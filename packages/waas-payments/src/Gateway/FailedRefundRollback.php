<?php

namespace WaaSPayments\Gateway;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentHelper;

/**
 * Undoes the refund FluentCart records before it asks the gateway to refund.
 *
 * FluentCart 1.7.0 app/Services/Payments/Refund.php:60-63 creates the refund transaction and
 * adds the amount to the charge's meta.refunded_total, then calls the gateway's processRefund
 * (line 85). When the gateway returns a WP_Error it keeps both and dispatches OrderRefund
 * anyway (line 97), so the order shows a refund that never reached the processor.
 *
 * Called from processRefund before it returns the error. OrderRefund (app/Events/Order/OrderRefund.php:59-76)
 * works out the new refund from the refund transactions in the database; with ours removed
 * it finds nothing new and leaves the order's totals, payment status and items alone.
 * The controller still shows the gateway's error to the admin (OrderController.php:403-416).
 *
 * Temporary guard (beads waas-hju.6): remove it once FluentCart ships the upstream fix and only
 * records a refund after the gateway accepts it.
 */
class FailedRefundRollback
{
    /**
     * @param OrderTransaction $charge The charge being refunded.
     * @param int $amount The refund amount in cents.
     * @return bool Whether a pending refund was found and undone.
     */
    public static function undo($charge, int $amount): bool
    {
        $refund = self::pendingRefund($charge, $amount);
        if (!$refund) {
            return false;
        }

        $refund->delete();
        PaymentHelper::updateTransactionRefundedTotal($charge, -$amount);

        return true;
    }

    /** The newest refund of this charge for this amount that the gateway hasn't confirmed. */
    private static function pendingRefund($charge, int $amount)
    {
        $refunds = OrderTransaction::query()
            ->where('order_id', $charge->order_id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->where('status', Status::TRANSACTION_REFUNDED)
            ->where('total', $amount)
            ->where('vendor_charge_id', '')
            ->orderBy('id', 'DESC')
            ->get();

        foreach ($refunds as $refund) {
            $meta = is_array($refund->meta) ? $refund->meta : [];
            if ((string) ($meta['parent_id'] ?? '') === (string) $charge->id) {
                return $refund;
            }
        }

        return null;
    }
}

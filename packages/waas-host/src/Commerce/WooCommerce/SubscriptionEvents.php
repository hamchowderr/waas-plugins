<?php

namespace WaaSHost\Commerce\WooCommerce;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\NameConflicts;

/**
 * Turns subscription status changes from either WooCommerce subscription plugin into the storefront's own events:
 *
 * - wpcs_subscription_created($subscription_id, $order): the subscription became active and has no store yet.
 * - wpcs_subscription_suspended($subscription_id): a subscription with a store went on hold (failed renewal
 *   payment, or put on hold by an admin). Fired once per hold.
 * - wpcs_subscription_reactivated($subscription_id): a held subscription with a store became active again.
 * - wpcs_subscription_expired($subscription_id): a subscription with a store was cancelled or expired.
 * - wpcs_subscription_canceled_until($subscription_id, $paid_through): a subscription with a store was cancelled with
 *   paid time left; the store stays online until $paid_through (TenantsSubscriptionManager).
 *
 * Pending-cancel fires nothing: the customer has paid until the end of the term, and the subscription plugin
 * cancels it then.
 */
final class SubscriptionEvents
{
    /** Subscription meta set while a suspension is in effect, so suspend and reactivate fire once each. */
    public const SUSPENDED_META = '_waas_store_suspended';
    /** Subscription meta set once the order that started it ended it (refunded or cancelled), so that fires once. */
    public const PARENT_ORDER_ENDED_META = '_waas_parent_order_ended';

    /**
     * @param \WC_Order|null $order the order the subscription was bought with; the subscription itself when there is none
     */
    public static function activated($subscription_id, ?\WC_Order $order): void
    {
        $commerce = Commerce::require_adapter();

        if (!self::has_store($subscription_id)) {
            if ($order !== null) {
                do_action('waas_subscription_created', $subscription_id, $order);
            }
            return;
        }

        if ($commerce->get_subscription_meta($subscription_id, self::SUSPENDED_META) === 'yes') {
            $commerce->update_subscription_meta($subscription_id, self::SUSPENDED_META, '');
            do_action('waas_subscription_reactivated', $subscription_id);
        }
    }

    public static function suspended($subscription_id): void
    {
        $commerce = Commerce::require_adapter();
        if (!self::has_store($subscription_id) || $commerce->get_subscription_meta($subscription_id, self::SUSPENDED_META) === 'yes') {
            return;
        }

        $commerce->update_subscription_meta($subscription_id, self::SUSPENDED_META, 'yes');
        do_action('waas_subscription_suspended', $subscription_id);
    }

    public static function ended($subscription_id): void
    {
        if (!self::has_store($subscription_id)) {
            return;
        }

        do_action('waas_subscription_expired', $subscription_id);
    }

    /** The order that started the subscription was refunded or cancelled. Fires once, whichever comes first. */
    public static function ended_by_parent_order($subscription_id): void
    {
        $commerce = Commerce::require_adapter();
        if (!self::has_store($subscription_id) || $commerce->get_subscription_meta($subscription_id, self::PARENT_ORDER_ENDED_META) === 'yes') {
            return;
        }

        $commerce->update_subscription_meta($subscription_id, self::PARENT_ORDER_ENDED_META, 'yes');
        do_action('waas_subscription_expired', $subscription_id);
    }

    /** Cancelled, but paid until $paid_through (unix time). */
    public static function canceled_until($subscription_id, int $paid_through): void
    {
        if (!self::has_store($subscription_id)) {
            return;
        }

        do_action('waas_subscription_canceled_until', $subscription_id, $paid_through);
    }

    /** True when the subscription has a store, or one waiting for a new name (NameConflicts). */
    private static function has_store($subscription_id): bool
    {
        return !empty(Commerce::require_adapter()->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META))
            || NameConflicts::is_waiting($subscription_id);
    }
}

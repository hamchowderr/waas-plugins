<?php

namespace WaaSHost\Commerce\WooCommerce;

/**
 * Subscriptions for WooCommerce by WP Swings (free, wordpress.org). Subscriptions are WPS_Subscription orders of type
 * wps_subscriptions. The subscription's state is the wps_subscription_status meta (the order status stays
 * wc-wps_renewal), and the parent order and customer are kept in the wps_parent_order and wps_customer_id meta.
 *
 * Its own hooks cover activation, cancellation and expiry. A cancel ends the subscription at once, though the customer
 * has paid until the next payment date, so the store stays online until then. Nothing is fired when a subscription
 * goes on hold (a failed renewal, or an admin pausing it) or is reactivated, so those are read from the status meta
 * whenever it is written: through the order object (High-Performance Order Storage) or as post meta (posts storage).
 */
class WpSwingsSubscriptions implements SubscriptionPlugin
{
    public const STATUS_META = 'wps_subscription_status';
    /** When the next renewal is due, in site-local time (current_time('timestamp')). Kept on a cancel. */
    public const NEXT_PAYMENT_META = 'wps_next_payment_date';

    /** @var array<int, true> subscription objects (by spl_object_id) whose save in progress writes the status */
    private array $saving_status = [];

    public function get_id(): string
    {
        return 'wp-swings';
    }

    public function is_active(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once(ABSPATH . '/wp-admin/includes/plugin.php');
        }

        return is_plugin_active('subscriptions-for-woocommerce/subscriptions-for-woocommerce.php');
    }

    public function get_order_type(): string
    {
        return 'wps_subscriptions';
    }

    public function register_hooks(): void
    {
        // Fired once the first payment completes and the subscription's activation meta is written.
        add_action('wps_sfw_order_status_changed', [$this, 'on_activated'], 10, 2);
        // Fired for cancellations by the customer, an admin and the payment gateways.
        add_action('wps_sfw_subscription_cancel', [$this, 'on_cancelled'], 10, 2);
        add_action('wps_sfw_expire_subscription_scheduler', [$this, 'on_ended'], 10, 1);
        // WP Swings leaves the subscription active when its order is refunded; WooCommerce Subscriptions cancels it.
        add_action('woocommerce_order_fully_refunded', [$this, 'on_order_refunded'], 10, 1);
        // Cancelling that order also cancels the subscription, by writing its status meta with no hook of its own.
        add_action('woocommerce_order_status_cancelled', [$this, 'on_order_cancelled'], 10, 1);

        add_action('woocommerce_before_order_object_save', [$this, 'on_before_order_save'], 10, 1);
        add_action('woocommerce_after_order_object_save', [$this, 'on_order_saved'], 10, 1);
        add_action('added_post_meta', [$this, 'on_post_meta_written'], 10, 4);
        add_action('updated_post_meta', [$this, 'on_post_meta_written'], 10, 4);

        // Fired by both of its My Account subscription templates (classic and "aurora").
        add_action('wps_sfw_after_subscription_details', [SubscriptionDetails::class, 'render'], 100, 1);
    }

    public function on_activated($order_id, $subscription_id): void
    {
        $subscription = $this->get_subscription($subscription_id);
        if ($subscription === null) {
            return;
        }

        $order = wc_get_order($order_id);
        SubscriptionEvents::activated($subscription->get_id(), $order instanceof \WC_Order ? $order : ($this->get_parent_order($subscription) ?? $subscription));
    }

    public function on_ended($subscription_id): void
    {
        if ($this->get_subscription($subscription_id) !== null) {
            SubscriptionEvents::ended((int) $subscription_id);
        }
    }

    /**
     * A cancel ('Cancel' from the customer, an admin or a bulk action) keeps the store until the end of the period the
     * customer paid for. Anything else, or a cancel with nothing left of the period, ends it now.
     */
    public function on_cancelled($subscription_id, $status = ''): void
    {
        $subscription = $this->get_subscription($subscription_id);
        if ($subscription === null) {
            return;
        }

        $paid_through = $status === 'Cancel' ? $this->get_paid_through($subscription) : 0;
        if ($paid_through > time()) {
            SubscriptionEvents::canceled_until((int) $subscription_id, $paid_through);
        } else {
            SubscriptionEvents::ended((int) $subscription_id);
        }
    }

    /** When the subscription's paid period ends: its next payment date as a unix time, 0 when there is none. */
    public function get_paid_through(\WC_Order $subscription): int
    {
        $local = (int) $subscription->get_meta(self::NEXT_PAYMENT_META);

        return $local > 0 ? $local - (int) round((float) get_option('gmt_offset', 0) * 3600) : 0;
    }

    /** The order that started a subscription was fully refunded: the subscription ends, as if it were cancelled. */
    public function on_order_refunded($order_id): void
    {
        $this->end_with_parent_order($order_id);
    }

    /**
     * The order that started a subscription was cancelled (by an admin, or WooCommerce's timeout for unpaid orders):
     * WP Swings cancels the subscription, and its store, if it has one, ends now.
     */
    public function on_order_cancelled($order_id): void
    {
        $this->end_with_parent_order($order_id);
    }

    private function end_with_parent_order($order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order || $order->get_type() === $this->get_order_type()) {
            return;
        }

        $subscription_id = (int) $order->get_meta('wps_subscription_id');
        $subscription = $subscription_id ? $this->get_subscription($subscription_id) : null;
        $parent = $subscription !== null ? $this->get_parent_order($subscription) : null;
        if ($parent !== null && $parent->get_id() === $order->get_id()) {
            SubscriptionEvents::ended_by_parent_order($subscription_id);
        }
    }

    /**
     * Notes whether this save writes the status meta. A save that doesn't is not a status change: the object can
     * hold an old status, e.g. one WooCommerce cached before WP Swings wrote a new one with update_post_meta.
     *
     * @param \WC_Data $order
     */
    public function on_before_order_save($order): void
    {
        if (!$order instanceof \WC_Order || $order->get_type() !== $this->get_order_type()) {
            return;
        }

        foreach ($order->get_meta_data() as $meta) {
            if ($meta->key === self::STATUS_META && (empty($meta->id) || !empty($meta->get_changes()))) {
                $this->saving_status[spl_object_id($order)] = true;
                return;
            }
        }
    }

    /**
     * @param \WC_Data $order
     */
    public function on_order_saved($order): void
    {
        if (!$order instanceof \WC_Order || !isset($this->saving_status[spl_object_id($order)])) {
            return;
        }

        unset($this->saving_status[spl_object_id($order)]);
        $this->on_status_written($order->get_id(), (string) $order->get_meta(self::STATUS_META));
    }

    public function on_post_meta_written($meta_id, $post_id, $meta_key, $meta_value): void
    {
        if ($meta_key === self::STATUS_META && get_post_type($post_id) === $this->get_order_type()) {
            $this->on_status_written((int) $post_id, (string) $meta_value);
        }
    }

    private function on_status_written(int $subscription_id, string $status): void
    {
        if ($status === 'on-hold' || $status === 'paused') {
            SubscriptionEvents::suspended($subscription_id);
        } elseif ($status === 'active') {
            // Only reactivates a suspended store; creation waits for wps_sfw_order_status_changed.
            SubscriptionEvents::activated($subscription_id, null);
        }
    }

    public function get_parent_order(\WC_Order $subscription): ?\WC_Order
    {
        $parent_id = $subscription->get_parent_id() ?: (int) $subscription->get_meta('wps_parent_order');
        $parent = $parent_id ? wc_get_order($parent_id) : null;

        return $parent instanceof \WC_Order ? $parent : null;
    }

    public function get_customer_id(\WC_Order $subscription): int
    {
        $customer_id = (int) $subscription->get_customer_id() ?: (int) $subscription->get_meta('wps_customer_id');
        if ($customer_id === 0 && ($parent = $this->get_parent_order($subscription)) !== null) {
            $customer_id = (int) $parent->get_customer_id();
        }

        return $customer_id;
    }

    public function get_subscription_url(\WC_Order $subscription): string
    {
        return (string) wc_get_endpoint_url('show-subscription', (string) $subscription->get_id(), wc_get_page_permalink('myaccount'));
    }

    public function is_subscription_active(\WC_Order $subscription): bool
    {
        return $subscription->get_meta(self::STATUS_META) === 'active';
    }

    private function get_subscription($subscription_id): ?\WC_Order
    {
        $subscription = wc_get_order($subscription_id);

        return $subscription instanceof \WC_Order && $subscription->get_type() === $this->get_order_type() ? $subscription : null;
    }
}

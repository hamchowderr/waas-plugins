<?php

namespace WaaSHost\Commerce\WooCommerce;

use WaaSHost\Core\WPCSProduct;

/**
 * WooCommerce Subscriptions (paid, by WooCommerce). Subscriptions are WC_Subscription orders of type
 * shop_subscription.
 */
class WooCommerceSubscriptions implements SubscriptionPlugin
{
    public function get_id(): string
    {
        return 'woocommerce-subscriptions';
    }

    public function is_active(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once(ABSPATH . '/wp-admin/includes/plugin.php');
        }

        return is_plugin_active('woocommerce-subscriptions/woocommerce-subscriptions.php');
    }

    public function get_order_type(): string
    {
        return 'shop_subscription';
    }

    public function register_hooks(): void
    {
        add_action('woocommerce_subscription_status_updated', [$this, 'on_status_updated'], 10, 3);
        add_action('woocommerce_subscription_details_table', [$this, 'render_details']);
        add_action('ssd_add_simple_product_before_calculate_totals', [$this, 'on_item_added'], 20, 1);
        add_action('wcs_user_removed_item', [$this, 'on_item_removed'], 20, 2);
    }

    /**
     * @param \WC_Subscription $subscription
     */
    public function on_status_updated($subscription, string $new_status, string $old_status): void
    {
        switch ($new_status) {
            case 'active':
                SubscriptionEvents::activated($subscription->get_id(), $this->get_parent_order($subscription) ?? $subscription);
                break;
            case 'on-hold':
                SubscriptionEvents::suspended($subscription->get_id());
                break;
            case 'cancelled':
            case 'expired':
                SubscriptionEvents::ended($subscription->get_id());
                break;
        }
    }

    /**
     * @param \WC_Subscription $subscription
     */
    public function render_details($subscription): void
    {
        SubscriptionDetails::render($subscription->get_id());
    }

    /**
     * Self-service Dashboard added a product to the subscription.
     *
     * @param \WC_Subscription $subscription
     */
    public function on_item_added($subscription): void
    {
        do_action('wpcs_tenant_roles_changed', $subscription->get_id(), self::roles_of($subscription->get_items()));
    }

    /**
     * @param \WC_Order_Item_Product $line_item
     * @param \WC_Subscription $subscription
     */
    public function on_item_removed($line_item, $subscription): void
    {
        $items = array_filter($subscription->get_items(), fn ($item) => $item->get_id() !== $line_item->get_id());
        do_action('wpcs_tenant_roles_changed', $subscription->get_id(), self::roles_of($items));
    }

    public function get_parent_order(\WC_Order $subscription): ?\WC_Order
    {
        $parent = $subscription->get_parent_id() ? wc_get_order($subscription->get_parent_id()) : null;

        return $parent instanceof \WC_Order ? $parent : null;
    }

    public function get_customer_id(\WC_Order $subscription): int
    {
        return (int) $subscription->get_customer_id();
    }

    public function get_subscription_url(\WC_Order $subscription): string
    {
        return (string) $subscription->get_view_order_url();
    }

    /**
     * @param \WC_Order_Item_Product[] $items
     * @return string[] the non-empty roles of the items' products
     */
    private static function roles_of(array $items): array
    {
        $roles = [];
        foreach ($items as $item) {
            $role = (string) (new WPCSProduct($item->get_product_id()))->get_role();
            if ($role !== '') {
                $roles[] = $role;
            }
        }

        return array_values(array_unique($roles));
    }
}

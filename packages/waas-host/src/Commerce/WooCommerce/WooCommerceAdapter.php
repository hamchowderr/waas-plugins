<?php

namespace WaaSHost\Commerce\WooCommerce;

use WaaSHost\Commerce\CommerceAdapter;
use WaaSHost\Commerce\PlanProduct;
use WaaSHost\Commerce\PlanPurchase;
use WaaSHost\Core\WPCSProduct;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\AddonProductCategory;
use WaaSHost\Features\PluginBootstrap;
use WaaSHost\Features\UserAccountSubscriptionsSettings;
use WaaSHost\Features\UserWcTenantsCheckout;
use WaaSHost\Features\WooCommerceCartValidator;
use WaaSHost\Features\WooCommerceProductData;
use WaaSHost\Integrations\SelfServiceDashboard\SelfServiceDashboardIntegration;
use WaaSHost\Integrations\SubscriptionsForWoocommerceIntegration;
use WaaSHost\Integrations\WoocommerceSubscriptionsIntegration;

/**
 * WooCommerce, with subscriptions from WooCommerce Subscriptions or WP Swings'
 * Subscriptions for WooCommerce. Subscriptions are posts, so their data is post meta.
 *
 * Behaviour is unchanged from release 2.2.7; the per-subscription-plugin lookups
 * still go through the wpcs_* filters the two integrations register.
 */
class WooCommerceAdapter implements CommerceAdapter
{
    public function get_id(): string
    {
        return 'woocommerce';
    }

    public function is_available(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once(ABSPATH . '/wp-admin/includes/plugin.php');
        }

        return is_plugin_active('woocommerce/woocommerce.php');
    }

    public function boot(WPCSService $wpcs_service): void
    {
        new UserAccountSubscriptionsSettings($wpcs_service);
        new UserWcTenantsCheckout($wpcs_service);
        new WooCommerceProductData($wpcs_service);
        new WooCommerceCartValidator();

        SelfServiceDashboardIntegration::init();
        SubscriptionsForWoocommerceIntegration::init();
        WoocommerceSubscriptionsIntegration::init();

        AddonProductCategory::init();
        new PluginBootstrap();
    }

    /**
     * @param \WC_Order $purchase_context the order the integrations pass with wpcs_subscription_created
     */
    public function get_plan_purchase($subscription_id, $purchase_context): PlanPurchase
    {
        $plans = [];
        foreach ($purchase_context->get_items() as $item) {
            $product = new WPCSProduct($item->get_product_id());
            if ($product->is_wpcs_product()) {
                $plans[] = new PlanProduct($item->get_product_id(), (string) $product->get_role(), (string) $product->get_tenant_snapshot_group_name());
            }
        }

        return new PlanPurchase(
            $plans,
            (string) get_post_meta($purchase_context->get_id(), WPCSTenant::WPCS_WEBSITE_NAME_META, true),
            (string) $purchase_context->get_billing_email()
        );
    }

    public function get_subscription_meta($subscription_id, string $key)
    {
        return get_post_meta($subscription_id, $key, true);
    }

    public function update_subscription_meta($subscription_id, string $key, $value): void
    {
        update_post_meta($subscription_id, $key, $value);
    }

    public function find_subscriptions_by_meta(string $key, array $excluded_values): array
    {
        global $wpdb;

        $sql = "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s";
        $params = [$key];
        if (!empty($excluded_values)) {
            $sql .= ' AND meta_value NOT IN (' . implode(',', array_fill(0, count($excluded_values), '%s')) . ')';
            $params = array_merge($params, array_values($excluded_values));
        }

        $found = [];
        foreach ($wpdb->get_results($wpdb->prepare($sql, $params)) as $row) {
            $found[$row->post_id] = $row->meta_value;
        }

        return $found;
    }

    public function get_customer_id($subscription_id): int
    {
        return (int) apply_filters('wpcs_get_customer_id_by_subscription_id_for_login_guard', '', $subscription_id);
    }

    public function get_customer_email($subscription_id): string
    {
        return (string) (new \WC_Order($subscription_id))->get_billing_email();
    }

    public function get_customer_name($subscription_id): string
    {
        return (string) apply_filters('wpcs_get_customer_username_by_subscription_id', null, $subscription_id);
    }

    public function get_subscription_url($subscription_id): string
    {
        return (string) apply_filters('wpcs_subscription_details_url', get_site_url(), $subscription_id);
    }
}

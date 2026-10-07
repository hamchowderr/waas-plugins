<?php

namespace WaaSHost\Commerce\WooCommerce;

use WaaSHost\Commerce\CommerceAdapter;
use WaaSHost\Commerce\PlanProduct;
use WaaSHost\Commerce\PlanPurchase;
use WaaSHost\Core\WPCSProduct;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\PluginBootstrap;
use WaaSHost\Features\UserAccountSubscriptionsSettings;
use WaaSHost\Features\UserWcTenantsCheckout;
use WaaSHost\Features\WooCommerceCartValidator;
use WaaSHost\Features\WooCommerceProductData;
use WaaSHost\Integrations\SelfServiceDashboard\SelfServiceDashboardIntegration;

/**
 * WooCommerce, with subscriptions from one subscription plugin (see SubscriptionPlugin). WP Swings' free
 * Subscriptions for WooCommerce is used when it is active, otherwise WooCommerce Subscriptions; the
 * wpcs_woocommerce_subscription_plugin filter can pick another.
 *
 * Subscriptions and orders are read and written with WooCommerce's order API only, so the storefront works with
 * High-Performance Order Storage (the default, with posts sync off since WooCommerce 10.7) as well as posts storage.
 */
class WooCommerceAdapter implements CommerceAdapter
{
    /** @var SubscriptionPlugin[] in order of preference */
    private array $subscription_plugins;
    private ?SubscriptionPlugin $subscription_plugin = null;
    private bool $resolved = false;

    /**
     * @param SubscriptionPlugin[]|null $subscription_plugins in order of preference
     */
    public function __construct(?array $subscription_plugins = null)
    {
        $this->subscription_plugins = $subscription_plugins ?? [new WpSwingsSubscriptions(), new WooCommerceSubscriptions()];
    }

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

    /** The subscription plugin in use, or null if neither is active. */
    public function get_subscription_plugin(): ?SubscriptionPlugin
    {
        if (!$this->resolved) {
            $active = array_values(array_filter($this->subscription_plugins, fn (SubscriptionPlugin $p) => $p->is_active()));
            $chosen = apply_filters('wpcs_woocommerce_subscription_plugin', $active[0] ?? null, $this->subscription_plugins);
            $this->subscription_plugin = $chosen instanceof SubscriptionPlugin ? $chosen : null;
            $this->resolved = true;
        }

        return $this->subscription_plugin;
    }

    public function boot(WPCSService $wpcs_service): void
    {
        new UserAccountSubscriptionsSettings($wpcs_service);
        new UserWcTenantsCheckout($wpcs_service, $this);
        new WooCommerceProductData($wpcs_service);
        new WooCommerceCartValidator();

        $subscription_plugin = $this->get_subscription_plugin();
        if ($subscription_plugin !== null) {
            $subscription_plugin->register_hooks();
        }
        if ($subscription_plugin instanceof WooCommerceSubscriptions) {
            // Self-service Dashboard is an add-on for WooCommerce Subscriptions.
            SelfServiceDashboardIntegration::init();
        }

        new PluginBootstrap();

        add_action('before_woocommerce_init', [$this, 'declare_woocommerce_compatibility']);
    }

    public function declare_woocommerce_compatibility(): void
    {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            $plugin_file = WP_PLUGIN_DIR . '/' . WPCS_WAAS_HOST_BASENAME;
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $plugin_file, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', $plugin_file, true);
        }
    }

    /**
     * @param \WC_Order $purchase_context the order passed with wpcs_subscription_created: the order the subscription
     *                                   was bought with, or the subscription itself when it has none
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
            (string) $purchase_context->get_meta(WPCSTenant::WPCS_WEBSITE_NAME_META),
            (string) $purchase_context->get_billing_email()
        );
    }

    /** The subscription, or null if there is none with that id or no subscription plugin is active. */
    public function get_subscription($subscription_id): ?\WC_Order
    {
        $plugin = $this->get_subscription_plugin();
        if ($plugin === null || !$subscription_id || !is_numeric($subscription_id)) {
            return null;
        }

        $subscription = wc_get_order((int) $subscription_id);

        return $subscription instanceof \WC_Order && $subscription->get_type() === $plugin->get_order_type() ? $subscription : null;
    }

    public function get_subscription_meta($subscription_id, string $key)
    {
        $subscription = $this->get_subscription($subscription_id);

        return $subscription === null ? '' : $subscription->get_meta($key, true);
    }

    public function update_subscription_meta($subscription_id, string $key, $value): void
    {
        $subscription = $this->get_subscription($subscription_id);
        if ($subscription === null) {
            return;
        }

        $subscription->update_meta_data($key, $value);
        $subscription->save();
    }

    public function find_subscriptions_by_meta(string $key, array $excluded_values): array
    {
        $plugin = $this->get_subscription_plugin();
        if ($plugin === null) {
            return [];
        }

        $meta_query = [['key' => $key, 'compare' => 'EXISTS']];
        if (!empty($excluded_values)) {
            $meta_query[] = ['key' => $key, 'value' => array_values($excluded_values), 'compare' => 'NOT IN'];
        }

        $found = [];
        foreach ($this->query_subscriptions($meta_query) as $subscription) {
            $found[$subscription->get_id()] = $subscription->get_meta($key, true);
        }

        return $found;
    }

    /**
     * True if a subscription already has (or is getting) a store on $domain_name, other than stores that were deleted.
     */
    public function is_domain_taken(string $domain_name): bool
    {
        if ($this->get_subscription_plugin() === null) {
            return false;
        }

        return !empty($this->query_subscriptions([
            'relation' => 'AND',
            ['key' => WPCSTenant::WPCS_DOMAIN_NAME_META, 'value' => $domain_name],
            [
                'relation' => 'OR',
                ['key' => WPCSTenant::WPCS_TENANT_STATE, 'compare' => 'NOT EXISTS'],
                ['key' => WPCSTenant::WPCS_TENANT_STATE, 'value' => WPCSTenant::DELETED, 'compare' => '!='],
            ],
        ], 1));
    }

    public function get_customer_id($subscription_id): int
    {
        $subscription = $this->get_subscription($subscription_id);

        return $subscription === null ? 0 : $this->get_subscription_plugin()->get_customer_id($subscription);
    }

    public function get_customer_email($subscription_id): string
    {
        $subscription = $this->get_subscription($subscription_id);
        if ($subscription === null) {
            return '';
        }

        $parent = $this->get_subscription_plugin()->get_parent_order($subscription);

        return (string) ($subscription->get_billing_email() ?: ($parent ? $parent->get_billing_email() : ''));
    }

    /**
     * The store's WordPress username is derived from this, both when the store is created and on every one-click
     * login, so it comes from the order the subscription was bought with, which doesn't change. The subscription's
     * own billing name, which the customer can edit, is only used when there is no such order.
     */
    public function get_customer_name($subscription_id): string
    {
        $subscription = $this->get_subscription($subscription_id);
        if ($subscription === null) {
            return '';
        }

        $parent = $this->get_subscription_plugin()->get_parent_order($subscription);

        return (string) ($parent ?? $subscription)->get_formatted_billing_full_name();
    }

    public function get_subscription_url($subscription_id): string
    {
        $subscription = $this->get_subscription($subscription_id);

        return $subscription === null ? get_site_url() : $this->get_subscription_plugin()->get_subscription_url($subscription);
    }

    public function is_subscription_active($subscription_id): bool
    {
        $subscription = $this->get_subscription($subscription_id);

        return $subscription !== null && $this->get_subscription_plugin()->is_subscription_active($subscription);
    }

    /**
     * @return \WC_Order[]
     */
    private function query_subscriptions(array $meta_query, int $limit = -1): array
    {
        // The order tables understand meta_query; with posts storage WooCommerce drops it unless it is passed
        // through to WP_Query with this filter.
        $pass_meta_query = function ($wp_query_args, $query_vars) {
            if (!empty($query_vars['meta_query'])) {
                $wp_query_args['meta_query'] = $query_vars['meta_query'];
            }
            return $wp_query_args;
        };

        add_filter('woocommerce_order_data_store_cpt_get_orders_query', $pass_meta_query, 10, 2);
        try {
            $subscriptions = wc_get_orders([
                'type' => $this->get_subscription_plugin()->get_order_type(),
                'status' => 'any',
                'limit' => $limit,
                'meta_query' => $meta_query,
            ]);
        } finally {
            remove_filter('woocommerce_order_data_store_cpt_get_orders_query', $pass_meta_query, 10);
        }

        return is_array($subscriptions) ? $subscriptions : [];
    }
}

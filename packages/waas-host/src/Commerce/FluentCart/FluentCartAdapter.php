<?php

namespace WaaSHost\Commerce\FluentCart;

use WaaSHost\Commerce\CommerceAdapter;
use WaaSHost\Commerce\PlanProduct;
use WaaSHost\Commerce\PlanPurchase;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\UserAccountSubscriptionsSettings;
use WaaSHost\Migrations\FluentCartFeedTriggers;

/**
 * FluentCart (1.7+), which has subscriptions built in. Subscription ids are
 * fct_subscriptions ids and their data lives in fct_subscription_meta. A product is a
 * website plan when the "WaaS Provisioning" integration is enabled on it; the feed
 * holds the role and snapshot group, and its triggers create and remove the store.
 */
class FluentCartAdapter implements CommerceAdapter
{
    private FluentCartStore $store;

    public function __construct(?FluentCartStore $store = null)
    {
        $this->store = $store ?? new FluentCartStore();
    }

    public function get_id(): string
    {
        return 'fluentcart';
    }

    public function is_available(): bool
    {
        if (defined('FLUENTCART_VERSION')) {
            return true;
        }

        if (!function_exists('is_plugin_active')) {
            require_once(ABSPATH . '/wp-admin/includes/plugin.php');
        }

        return is_plugin_active('fluent-cart/fluent-cart.php');
    }

    public function boot(WPCSService $wpcs_service): void
    {
        $store = $this->store;
        $domain_settings = new UserAccountSubscriptionsSettings($wpcs_service);

        add_action('fluent_cart/init', function () use ($wpcs_service, $store) {
            (new WaasProvisioningIntegration($wpcs_service, $store))->register();
            (new FluentCartFeedTriggers($store))->maybe_run();
        });

        new FluentCartCheckout($wpcs_service, $this, $store);
        new FluentCartPortal($this, $store, $domain_settings);

        add_filter('wpcs_getting_started_checklist', [$this, 'mark_plan_product_checklist_item'], 20);
    }

    public function is_plan_product($product_id): bool
    {
        return $this->store->get_plan_feed($product_id) !== null;
    }

    /**
     * @param \FluentCart\App\Models\Order $purchase_context the order the integration passes with wpcs_subscription_created
     */
    public function get_plan_purchase($subscription_id, $purchase_context): PlanPurchase
    {
        $plans = [];
        foreach ($this->store->get_order_product_ids($purchase_context) as $product_id) {
            $feed = $this->store->get_plan_feed($product_id);
            if ($feed !== null) {
                $plans[] = new PlanProduct($product_id, (string) ($feed['role'] ?? ''), (string) ($feed['group_name'] ?? ''));
            }
        }

        return new PlanPurchase(
            $plans,
            $this->store->get_order_meta($purchase_context, WPCSTenant::WPCS_WEBSITE_NAME_META),
            $this->get_customer_email($subscription_id)
        );
    }

    public function get_subscription_meta($subscription_id, string $key)
    {
        return $this->store->get_subscription_meta($subscription_id, $key);
    }

    public function update_subscription_meta($subscription_id, string $key, $value): void
    {
        $this->store->update_subscription_meta($subscription_id, $key, $value);
    }

    public function find_subscriptions_by_meta(string $key, array $excluded_values): array
    {
        return $this->store->find_subscription_meta($key, $excluded_values);
    }

    public function is_domain_taken(string $domain_name): bool
    {
        foreach ($this->store->find_subscription_ids_with_meta(WPCSTenant::WPCS_DOMAIN_NAME_META, $domain_name) as $subscription_id) {
            if ($this->store->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_STATE) !== WPCSTenant::DELETED) {
                return true;
            }
        }

        return false;
    }

    public function get_customer_id($subscription_id): int
    {
        $customer = $this->store->get_subscription_customer($subscription_id);
        if ($customer === null) {
            return 0;
        }

        if ($customer['user_id'] > 0) {
            return $customer['user_id'];
        }

        // FluentCart leaves the customer unlinked when their email already had an account.
        $user = $customer['email'] !== '' ? get_user_by('email', $customer['email']) : false;

        return $user ? (int) $user->ID : 0;
    }

    public function get_customer_email($subscription_id): string
    {
        $customer = $this->store->get_subscription_customer($subscription_id);

        return $customer['email'] ?? '';
    }

    public function get_customer_name($subscription_id): string
    {
        $customer = $this->store->get_subscription_customer($subscription_id);
        if ($customer === null) {
            return '';
        }

        // Digital checkouts can skip the name; the store's username is derived from this.
        return $customer['name'] !== '' ? $customer['name'] : (string) strstr($customer['email'], '@', true);
    }

    public function get_subscription_url($subscription_id): string
    {
        return $this->store->get_subscription_url($subscription_id);
    }

    public function is_subscription_active($subscription_id): bool
    {
        return in_array($this->store->get_subscription_status($subscription_id), FluentCartStore::ACTIVE_STATUSES, true);
    }

    public function mark_plan_product_checklist_item($checklist_items)
    {
        if (isset($checklist_items['create_woo_wpcs_product'])) {
            $checklist_items['create_woo_wpcs_product']['is_done'] = $this->store->has_plan_feed();
        }

        return $checklist_items;
    }
}

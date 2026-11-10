<?php

namespace AnyPressHost\Features;

use AnyPressHost\Core\ConfigService;
use AnyPressHost\Core\StoreProduct;

/**
 * The setup steps on the Overview until the storefront is ready (filter: anypress_getting_started_checklist), and
 * the checks that mark each step done.
 */
class GettingStartedChecklist
{
    private ConfigService $config_service;

    public function __construct(ConfigService $config_service)
    {
        $this->config_service = $config_service;

        add_filter('anypress_getting_started_checklist', [$this, 'check_api_creds']);
        add_filter('anypress_getting_started_checklist', [$this, 'check_required_plugins']);
        add_filter('anypress_getting_started_checklist', [$this, 'check_tenant_roles']);
        add_filter('anypress_getting_started_checklist', [$this, 'check_woo_anypress_product']);
    }

    public static function default_checklist(): array
    {
        return [
            "api_credentials" => [
                "label" => __('Connect to AnyPress', 'anypress-host'),
                "description" => __('Paste the API key and secret for this storefront. They are on the API keys page of your AnyPress dashboard.', 'anypress-host'),
                "action_label" => __('Connect', 'anypress-host'),
                "action_url" => AdminSettings::url('connection'),
                "is_done" => false,
            ],
            "required_plugins_installed" => [
                "label" => __('Install a cart', 'anypress-host'),
                "description" => __('FluentCart, or WooCommerce with WooCommerce Subscriptions (or Subscriptions for WooCommerce), takes the orders and the renewals.', 'anypress-host'),
                "action_label" => __('Add FluentCart', 'anypress-host'),
                "action_url" => admin_url('plugin-install.php?s=fluentcart&tab=search&type=term'),
                "is_done" => false,
            ],
            "setup_tenant_roles" => [
                "label" => __('Load your plans', 'anypress-host'),
                "description" => __('Each plan decides which plugins a website gets. The plans are set up on your template website and loaded from there.', 'anypress-host'),
                "action_label" => __('Load plans', 'anypress-host'),
                "action_url" => \AnyPressHost\Api\RolesController::get_refresh_url(),
                "is_done" => false,
            ],
            "create_woo_anypress_product" => [
                "label" => __('Create your website plans', 'anypress-host'),
                "description" => defined('FLUENTCART_VERSION')
                    ? __('Each plan is a FluentCart subscription product. Buying one creates the customer\'s website.', 'anypress-host')
                    : __('A subscription product marked "AnyPress product". Buying it creates the customer\'s website.', 'anypress-host'),
                "action_label" => __('Add a plan', 'anypress-host'),
                "action_url" => self::new_plan_product_url(),
                "is_done" => false,
            ],
        ];
    }

    public function check_api_creds($checklist_items)
    {
        $checklist_items['api_credentials']['is_done'] = $this->config_service->check_credentials();
        if (ConfigService::has_constant('API_KEY') && ConfigService::has_constant('API_SECRET')) {
            $checklist_items['api_credentials']['description'] = __('Connected for you when this storefront was made.', 'anypress-host');
        }
        return $checklist_items;
    }

    public function check_required_plugins($checklist_items)
    {
        // Is WooCommerce installed and active?
        $woocommerce_active = is_plugin_active('woocommerce/woocommerce.php');

        $subs_for_wc_active = is_plugin_active('subscriptions-for-woocommerce/subscriptions-for-woocommerce.php');
        $wc_subs_active = is_plugin_active('woocommerce-subscriptions/woocommerce-subscriptions.php');

        // FluentCart has subscriptions built in; WooCommerce needs one of the two subscription plugins.
        $fluentcart_active = defined('FLUENTCART_VERSION');

        $checklist_items['required_plugins_installed']['is_done'] = $fluentcart_active || ($woocommerce_active && ($subs_for_wc_active || $wc_subs_active));

        return $checklist_items;
    }

    public function check_tenant_roles($checklist_items)
    {
        $roles = get_option(PluginBootstrap::ROLES_WP_OPTION);
        $checklist_items['setup_tenant_roles']['is_done'] = $roles && count(array_keys((array) $roles)) > 0;

        return $checklist_items;
    }

    public function check_woo_anypress_product($checklist_items)
    {
        // WooCommerce products only; other carts' adapters mark this item done through the same filter.
        if (!function_exists('wc_get_product')) {
            return $checklist_items;
        }

        $products = get_posts([
            'post_type' => 'product',
            'meta_query' => [StoreProduct::get_anypress_product_meta_query()],
        ]);

        $checklist_items['create_woo_anypress_product']['is_done'] = count($products) > 0;

        return $checklist_items;
    }

    /** Where the store owner creates a plan product: the active cart's product editor. */
    public static function new_plan_product_url(): string
    {
        if (defined('FLUENTCART_VERSION')) {
            return admin_url('admin.php?page=fluent-cart#/products');
        }

        return admin_url('post-new.php?post_type=product');
    }
}

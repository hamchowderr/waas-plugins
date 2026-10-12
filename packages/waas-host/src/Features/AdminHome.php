<?php

namespace WaaSHost\Features;

use WaaSHost\Core\ConfigService;
use WaaSHost\Core\StoreProduct;

class AdminHome
{
    private ConfigService $config_service;

    public function __construct($config_service)
    {
        $this->config_service = $config_service;

        add_action('admin_menu', [$this, 'add_waas_admin_page'], 11);

        add_filter('waas_getting_started_checklist', [$this, 'check_api_creds']);
        add_filter('waas_getting_started_checklist', [$this, 'check_required_plugins']);
        add_filter('waas_getting_started_checklist', [$this, 'check_tenant_roles']);
        add_filter('waas_getting_started_checklist', [$this, 'check_woo_waas_product']);
    }

    public function check_api_creds($checklist_items)
    {
        $checklist_items['api_credentials']['is_done'] = $this->config_service->check_credentials();
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
        $checklist_items['setup_tenant_roles']['is_done'] = $roles && count(array_keys((array)$roles)) > 0;

        return $checklist_items;
    }

    public function check_woo_waas_product($checklist_items)
    {
        // WooCommerce products only; other carts' adapters mark this item done through the same filter.
        if (!function_exists('wc_get_product')) {
            return $checklist_items;
        }

        $products = get_posts([
            'post_type' => 'product',
            'meta_query' => [StoreProduct::get_waas_product_meta_query()],
        ]);

        $checklist_items['create_woo_waas_product']['is_done'] = count($products) > 0;

        return $checklist_items;
    }

    public function add_waas_admin_page()
    {
        $cap = 'manage_options';
        $slug = 'waas-admin';
        add_menu_page(
            __('WaaS', WAAS_HOST_TEXTDOMAIN),
            __('WaaS', WAAS_HOST_TEXTDOMAIN),
            $cap,
            $slug,
            [$this, 'render_waas_admin_page'],
            'dashicons-networking',
            10
        );

        add_submenu_page(
            $slug, // parent menu slug
            __('WaaS', WAAS_HOST_TEXTDOMAIN), // page title
            __('Getting Started', WAAS_HOST_TEXTDOMAIN), // menu title
            $cap, // capability
            $slug, // menu slug
            [$this, 'render_waas_admin_page'] // callback function
        );
    }

    public function render_waas_admin_page()
    {
?>
        <div style="max-width:50vw" class="waas-container">
            <h1><?php _e('WaaS', WAAS_HOST_TEXTDOMAIN); ?></h1>
            <section>
                <h2><?php _e('Getting started', WAAS_HOST_TEXTDOMAIN); ?></h2>
                <p>
                    <?php _e('This storefront sells websites: each purchase creates a store through the WaaS provisioning API. Finish the steps below before you take orders.', WAAS_HOST_TEXTDOMAIN); ?>
                </p>
            </section>
            <?php

            $default_checklist = [
                "api_credentials" => [
                    "label" => __('Fill out your WaaS API credentials', WAAS_HOST_TEXTDOMAIN),
                    "is_done" => false,
                ],
                "required_plugins_installed" => [
                    "label" => __('Install the required plugins', WAAS_HOST_TEXTDOMAIN),
                    "is_done" => false,
                ],
                "create_woo_waas_product" => [
                    "label" => __('Create a website plan product', WAAS_HOST_TEXTDOMAIN),
                    "is_done" => false,
                ],
                "setup_tenant_roles" => [
                    "label" => __('Create some tenant roles', WAAS_HOST_TEXTDOMAIN),
                    "is_done" => false,
                ],
            ];
            $checklist_items = apply_filters('waas_getting_started_checklist', $default_checklist);

            ?>
            <section>
                <ul class="ticks">
                    <?php foreach ($checklist_items as $id => $checklist_item) : ?>
                        <li class="<?php echo $checklist_item['is_done'] ? "checked" : "unchecked"; ?>">
                            <span><?php echo $checklist_item['label'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </div>
<?php
    }
}

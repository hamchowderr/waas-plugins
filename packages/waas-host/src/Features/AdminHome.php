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
            __('WaaS Host', WAAS_HOST_TEXTDOMAIN),
            __('WaaS', WAAS_HOST_TEXTDOMAIN),
            $cap,
            $slug,
            [$this, 'render_waas_admin_page'],
            'dashicons-networking',
            10
        );

        add_submenu_page(
            $slug,
            __('WaaS Host', WAAS_HOST_TEXTDOMAIN),
            __('Getting started', WAAS_HOST_TEXTDOMAIN),
            $cap,
            $slug,
            [$this, 'render_waas_admin_page']
        );
    }

    /** Where the store owner creates a plan product: the active cart's product editor. */
    private static function new_plan_product_url(): string
    {
        if (defined('FLUENTCART_VERSION')) {
            return admin_url('admin.php?page=fluent-cart#/products');
        }

        return admin_url('post-new.php?post_type=product');
    }

    public static function default_checklist(): array
    {
        return [
            "api_credentials" => [
                "label" => __('Connect to the WaaS API', WAAS_HOST_TEXTDOMAIN),
                "description" => __('Enter the API URL, key and secret this storefront uses to create websites.', WAAS_HOST_TEXTDOMAIN),
                "action_label" => __('Open connection settings', WAAS_HOST_TEXTDOMAIN),
                "action_url" => AdminSettings::url('connection'),
                "is_done" => false,
            ],
            "required_plugins_installed" => [
                "label" => __('Install a cart', WAAS_HOST_TEXTDOMAIN),
                "description" => __('FluentCart, or WooCommerce with WooCommerce Subscriptions (or Subscriptions for WooCommerce), takes the orders and the recurring payments.', WAAS_HOST_TEXTDOMAIN),
                "action_label" => __('Add plugins', WAAS_HOST_TEXTDOMAIN),
                "action_url" => admin_url('plugin-install.php?s=fluentcart&tab=search&type=term'),
                "is_done" => false,
            ],
            "setup_tenant_roles" => [
                "label" => __('Load your plan roles', WAAS_HOST_TEXTDOMAIN),
                "description" => __('Roles decide which plugins a website gets. They are set up with WaaS Client on your template website and loaded from there.', WAAS_HOST_TEXTDOMAIN),
                "action_label" => __('Load roles now', WAAS_HOST_TEXTDOMAIN),
                "action_url" => \WaaSHost\Api\RolesController::get_refresh_url(),
                "is_done" => false,
            ],
            "create_woo_waas_product" => [
                "label" => __('Create a website plan', WAAS_HOST_TEXTDOMAIN),
                "description" => defined('FLUENTCART_VERSION')
                    ? __('A subscription product with the WaaS Provisioning integration. Buying it creates the customer\'s website.', WAAS_HOST_TEXTDOMAIN)
                    : __('A subscription product marked "WaaS product". Buying it creates the customer\'s website.', WAAS_HOST_TEXTDOMAIN),
                "action_label" => __('Add a product', WAAS_HOST_TEXTDOMAIN),
                "action_url" => self::new_plan_product_url(),
                "is_done" => false,
            ],
        ];
    }

    public function render_waas_admin_page()
    {
        $checklist_items = apply_filters('waas_getting_started_checklist', self::default_checklist());
        $done = count(array_filter($checklist_items, fn ($item) => !empty($item['is_done'])));
        $total = count($checklist_items);

        $has_credentials = $this->config_service->check_credentials();
        $reachable = $has_credentials && get_option(\WaaSHost\Core\ProvisioningApi::CAN_REACH_API_OPTION_NAME, false);
        $root_domain = (string) get_option('waas_root_domain', '');
?>
        <div class="wrap waas-admin waas-home">
            <h1><?php esc_html_e('WaaS Host', WAAS_HOST_TEXTDOMAIN); ?></h1>
            <p class="waas-lead">
                <?php esc_html_e('This storefront sells websites. Each purchase creates the customer\'s website through the WaaS provisioning API, and the subscription keeps it online.', WAAS_HOST_TEXTDOMAIN); ?>
            </p>

            <div class="waas-columns">
                <div class="waas-card waas-checklist">
                    <h2>
                        <?php esc_html_e('Getting started', WAAS_HOST_TEXTDOMAIN); ?>
                        <span class="waas-count"><?php
                            /* translators: 1: steps done, 2: all steps */
                            echo esc_html(sprintf(__('%1$d of %2$d done', WAAS_HOST_TEXTDOMAIN), $done, $total));
                        ?></span>
                    </h2>
                    <?php if ($done === $total) : ?>
                        <p><?php esc_html_e('All set. This storefront is ready to take orders.', WAAS_HOST_TEXTDOMAIN); ?></p>
                    <?php endif; ?>
                    <ol>
                        <?php foreach ($checklist_items as $item) :
                            $is_done = !empty($item['is_done']); ?>
                            <li class="<?php echo $is_done ? 'is-done' : 'is-todo'; ?>">
                                <span class="dashicons <?php echo $is_done ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span>
                                <div>
                                    <strong><?php echo esc_html($item['label'] ?? ''); ?></strong>
                                    <span class="screen-reader-text"><?php echo $is_done ? esc_html__('(done)', WAAS_HOST_TEXTDOMAIN) : esc_html__('(to do)', WAAS_HOST_TEXTDOMAIN); ?></span>
                                    <?php if (!empty($item['description'])) : ?>
                                        <p><?php echo esc_html($item['description']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!$is_done && !empty($item['action_url'])) : ?>
                                        <a class="button" href="<?php echo esc_url($item['action_url']); ?>"><?php echo esc_html($item['action_label'] ?? __('Set up', WAAS_HOST_TEXTDOMAIN)); ?></a>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </div>

                <div class="waas-side">
                    <div class="waas-card">
                        <h2><?php esc_html_e('Status', WAAS_HOST_TEXTDOMAIN); ?></h2>
                        <table class="waas-facts">
                            <tr>
                                <th scope="row"><?php esc_html_e('WaaS API', WAAS_HOST_TEXTDOMAIN); ?></th>
                                <td>
                                    <?php if (!$has_credentials) : ?>
                                        <span class="waas-badge is-warning"><?php esc_html_e('Not set up', WAAS_HOST_TEXTDOMAIN); ?></span>
                                    <?php elseif ($reachable) : ?>
                                        <span class="waas-badge is-ok"><?php esc_html_e('Connected', WAAS_HOST_TEXTDOMAIN); ?></span>
                                    <?php else : ?>
                                        <span class="waas-badge is-error"><?php esc_html_e('Can\'t connect', WAAS_HOST_TEXTDOMAIN); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Website domain', WAAS_HOST_TEXTDOMAIN); ?></th>
                                <td><?php echo $root_domain !== '' ? '<code>' . esc_html($root_domain) . '</code>' : esc_html__('Not set', WAAS_HOST_TEXTDOMAIN); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('WaaS Host', WAAS_HOST_TEXTDOMAIN); ?></th>
                                <td><?php echo esc_html(defined('WAAS_HOST_VERSION') ? WAAS_HOST_VERSION : ''); ?></td>
                            </tr>
                            <?php if (defined('WAAS_PAYMENTS_VERSION')) : ?>
                                <tr>
                                    <th scope="row"><?php esc_html_e('WaaS Payments', WAAS_HOST_TEXTDOMAIN); ?></th>
                                    <td><?php echo esc_html(WAAS_PAYMENTS_VERSION); ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div>

                    <div class="waas-card">
                        <h2><?php esc_html_e('Settings', WAAS_HOST_TEXTDOMAIN); ?></h2>
                        <ul class="waas-links">
                            <?php foreach (AdminSettings::tabs() as $slug => [$title]) : ?>
                                <li><a href="<?php echo esc_url(AdminSettings::url($slug)); ?>"><?php echo esc_html($title); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
<?php
    }
}

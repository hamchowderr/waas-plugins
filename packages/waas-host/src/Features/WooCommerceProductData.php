<?php

namespace WaaSHost\Features;

use WaaSHost\Core\StoreProduct;
use WaaSHost\Core\ProvisioningApi;

class WooCommerceProductData
{
    const PRODUCT_DATA_TAB_TARGET = 'waas-product-settings';
    // Form field names. The values are stored under StoreProduct's meta keys.
    const ROLE_FIELD = 'waas_product_role';
    const GROUPNAME_FIELD = 'waas_product_group_name';

    private ProvisioningApi $provisioningApi;

    public function __construct(ProvisioningApi $provisioningApi)
    {
        $this->provisioningApi = $provisioningApi;

        add_filter('woocommerce_product_data_tabs', [$this, 'register_product_tabs']);
        add_action('woocommerce_product_data_panels', [$this, 'display_product_tab_content']);
        add_filter('product_type_options', [$this, 'display_product_type']);
        // Hands over the product object; WooCommerce saves it right after.
        add_action('woocommerce_admin_process_product_object', [$this, 'save_product_settings']);
    }

    public function register_product_tabs($tabs)
    {
        $tabs['waas'] = [
            'label' => __('WaaS', 'waas-host'),
            'priority' => 50,
            'target' => self::PRODUCT_DATA_TAB_TARGET,
            'class'    => ['show_if_simple', 'show_if_subscription'],
        ];

        return $tabs;
    }

    public function display_product_tab_content()
    {
        if (!$this->provisioningApi->is_reachable()) {
?>
            <div id="<?php echo esc_attr(self::PRODUCT_DATA_TAB_TARGET); ?>" class="panel woocommerce_options_panel">
                <div>
                    <p>
                        <?php esc_html_e("Looks like your API credentials are not set up yet, or are incorrect. Use the link below to connect this storefront to the WaaS provisioning API.", 'waas-host') ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url(admin_url('/admin.php?page=waas-admin-settings')); ?>">
                            <?php esc_html_e('Change API Credentials', 'waas-host'); ?>
                        </a>
                    </p>
                </div>
            </div>
        <?php
            return;
        }

        $store_product = new StoreProduct(self::current_product());

        $role_options = [];
        foreach (self::roles() as $role_slug => $role_title) {
            $role_options[$role_slug] = $role_title;
        }

        $available_groupnames = [
            '' => _x('Default', 'Option name when no snapshot group is chosen', 'waas-host'),
        ];
        // Cached, and empty when the API is down: the product editor must still open.
        foreach ($this->provisioningApi->get_available_groupnames_cached() as $groupname) {
            $available_groupnames[$groupname] = $groupname;
        }
        // Keep the saved group selectable, so saving the product while the API is down doesn't clear it.
        $current_groupname = (string) $store_product->get_tenant_snapshot_group_name();
        if ($current_groupname !== '' && !isset($available_groupnames[$current_groupname])) {
            $available_groupnames[$current_groupname] = $current_groupname;
        }

        ?>
        <div id="<?php echo esc_attr(self::PRODUCT_DATA_TAB_TARGET); ?>" class="panel woocommerce_options_panel">
            <div>
                <?php \woocommerce_wp_select([
                    'id'            => self::ROLE_FIELD,
                    'value'         => (string) $store_product->get_role(),
                    'label'         => __('Role', 'waas-host'),
                    'description'   => __('The role the store is set up with after purchase.', 'waas-host'),
                    'options'          => $role_options,
                    'desc_tip'        => false,
                ]); ?>
            </div>
            <div>
                <p>
                    <a href="<?php echo esc_url(\WaaSHost\Api\RolesController::get_refresh_url()) ?>">
                        <?php esc_html_e('Refresh roles', 'waas-host'); ?>
                    </a>
                </p>
            </div>
            <div>
                <?php \woocommerce_wp_select([
                    'id'            => self::GROUPNAME_FIELD,
                    'value'         => (string) $store_product->get_tenant_snapshot_group_name(),
                    'label'         => __('Snapshot group', 'waas-host'),
                    'description'   => __('The template group the store is created from after purchase.', 'waas-host'),
                    'options'          => $available_groupnames,
                    'desc_tip'        => false,
                ]); ?>
            </div>
        </div>
<?php
    }

    /** @param \WC_Product $product the product being saved on the product editor */
    public function save_product_settings($product)
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checked its product editor nonce before woocommerce_admin_process_product_object.
        $store_product = new StoreProduct($product);
        $store_product->store_is_waas_product(isset($_POST[StoreProduct::IS_STORE_PRODUCT_META]));

        if (isset($_POST[self::ROLE_FIELD])) {
            $role = sanitize_text_field(wp_unslash($_POST[self::ROLE_FIELD]));
            // Only a role the template defines (or none); anything else would give the store no plugins.
            if ($role === '' || array_key_exists($role, self::roles())) {
                $store_product->update_role($role);
            }
        }

        if (isset($_POST[self::GROUPNAME_FIELD])) {
            $store_product->update_tenant_snapshot_group_name(sanitize_text_field(wp_unslash($_POST[self::GROUPNAME_FIELD])));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /** The product open in the editor: WooCommerce's global while it renders the panels. */
    private static function current_product()
    {
        global $product_object;
        if (is_object($product_object)) {
            return $product_object;
        }

        return function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null;
    }

    /** @return array<string, string> role slug => title, from the roles the template published */
    private static function roles(): array
    {
        $roles = [];
        foreach ((array) get_option(PluginBootstrap::ROLES_WP_OPTION, []) as $slug => $role) {
            $roles[(string) $slug] = is_object($role) && isset($role->title) ? (string) $role->title : (string) $slug;
        }

        return $roles;
    }

    public function display_product_type($product_type_options)
    {
        $product_type_options[StoreProduct::IS_STORE_PRODUCT_META] = [
            "id"            => StoreProduct::IS_STORE_PRODUCT_META,
            "wrapper_class" => "show_if_simple show_if_subscription",
            "label"         => __("WaaS product", 'waas-host'),
            "description"   => __("Buying a WaaS product creates a website (store) for the customer.", 'waas-host'),
            "default"       => "no",
        ];

        return $product_type_options;
    }
}

<?php

namespace WaaSHost\Features;

use WaaSHost\Core\WPCSProduct;
use WaaSHost\Core\WPCSService;

class WooCommerceProductData
{
    const WPCS_PRODUCT_DATA_TAB_TARGET = 'wpcs-product-settings';

    private WPCSService $wpcsService;

    public function __construct(WPCSService $wpcsService)
    {
        $this->wpcsService = $wpcsService;

        add_filter('woocommerce_product_data_tabs', [$this, 'register_product_tabs']);
        add_action('woocommerce_product_data_panels', [$this, 'display_product_tab_content']);
        add_action('woocommerce_process_product_meta', [$this, 'store_product_tab_data']);
        add_filter('product_type_options', [$this, 'display_product_type']);
        add_action('woocommerce_process_product_meta', [$this, 'store_product_type']);
    }

    public function register_product_tabs($tabs)
    {
        $tabs['wpcs'] = [
            'label' => __('WPCS', WPCS_WAAS_HOST_TEXTDOMAIN),
            'priority' => 50,
            'target' => self::WPCS_PRODUCT_DATA_TAB_TARGET,
            'class'    => ['show_if_simple', 'show_if_subscription'],
        ];

        return $tabs;
    }

    public function display_product_tab_content()
    {
        if (!$this->wpcsService->is_reachable()) {
?>
            <div id="<?php echo self::WPCS_PRODUCT_DATA_TAB_TARGET; ?>" class="panel woocommerce_options_panel">
                <div>
                    <p>
                        <?php _e("Looks like your API Credentials are not yet setup or incorrect. Please click the link below to connect this storefont to an Application on WPCS!", WPCS_WAAS_HOST_TEXTDOMAIN) ?>
                    </p>
                    <p>
                        <a href="<?php echo admin_url('/admin.php?page=wpcs-admin-settings') ?>">
                            <?php _e('Change API Credentials', WPCS_WAAS_HOST_TEXTDOMAIN); ?>
                        </a>
                    </p>
                </div>
            </div>
        <?php
            return;
        }

        $role_options = [];
        foreach (self::roles() as $role_slug => $role_title) {
            $role_options[$role_slug] = $role_title;
        }

        $available_groupnames = [
            '' => _x('None', 'Option name when no tenant snapshot chosen', WPCS_WAAS_HOST_TEXTDOMAIN),
        ];
        // Cached, and empty when the API is down: the product editor must still open.
        foreach ($this->wpcsService->get_available_groupnames_cached() as $groupname) {
            $available_groupnames[$groupname] = $groupname;
        }

        ?>
        <div id="<?php echo self::WPCS_PRODUCT_DATA_TAB_TARGET; ?>" class="panel woocommerce_options_panel">
            <div>
                <?php \woocommerce_wp_select([
                    'id'            => WPCSProduct::WPCS_PRODUCT_ROLE_META,
                    'label'         => __('Role', WPCS_WAAS_HOST_TEXTDOMAIN),
                    'description'   => __('The role used to configure the WPCS Tenant with after purchase.', WPCS_WAAS_HOST_TEXTDOMAIN),
                    'options'          => $role_options,
                    'desc_tip'        => false,
                ]); ?>
            </div>
            <div>
                <p>
                    <a href="<?php echo esc_url(\WaaSHost\Api\RolesController::get_refresh_url()) ?>">
                        <?php _e('Refresh Roles', WPCS_WAAS_HOST_TEXTDOMAIN); ?>
                    </a>
                </p>
            </div>
            <div>
                <?php \woocommerce_wp_select([
                    'id'            => WPCSProduct::WPCS_PRODUCT_GROUPNAME_META,
                    'label'         => __('Tenant Snapshot Groupname', WPCS_WAAS_HOST_TEXTDOMAIN),
                    'description'   => __('The tenant snapshot groupname used to configure the WPCS Tenant with after purchase.', WPCS_WAAS_HOST_TEXTDOMAIN),
                    'options'          => $available_groupnames,
                    'desc_tip'        => false,
                ]); ?>
            </div>
        </div>
<?php
    }

    public function store_product_tab_data($post_id)
    {
        $product = new WPCSProduct($post_id);

        if (isset($_POST[WPCSProduct::WPCS_PRODUCT_ROLE_META])) {
            $role = sanitize_text_field(wp_unslash($_POST[WPCSProduct::WPCS_PRODUCT_ROLE_META]));
            // Only a role the template defines (or none); anything else would give the store no plugins.
            if ($role === '' || array_key_exists($role, self::roles())) {
                $product->update_role($role);
            }
        }

        if (isset($_POST[WPCSProduct::WPCS_PRODUCT_GROUPNAME_META])) {
            $product->update_tenant_snapshot_group_name(sanitize_text_field(wp_unslash($_POST[WPCSProduct::WPCS_PRODUCT_GROUPNAME_META])));
        }
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
        $product_type_options[WPCSProduct::IS_WPCS_PRODUCT_META] = [
            "id"            => WPCSProduct::IS_WPCS_PRODUCT_META,
            "wrapper_class" => "show_if_simple show_if_subscription",
            "label"         => __("WPCS Product", WPCS_WAAS_HOST_TEXTDOMAIN),
            "description"   => __("WPCS Products use WPCS to provision and update Tenants running on WPCS.", WPCS_WAAS_HOST_TEXTDOMAIN),
            "default"       => "no",
        ];

        return $product_type_options;
    }

    public function store_product_type($post_id)
    {
        $wpcs_product = new WPCSProduct($post_id);
        $wpcs_product->store_is_wpcs_product(isset($_POST[WPCSProduct::IS_WPCS_PRODUCT_META]));
    }
}

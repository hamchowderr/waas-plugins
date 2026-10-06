<?php

namespace WaaSHost\Api;

use WaaSHost\Core\WPCSService;
use WaaSHost\Features\AdminNotices;
use WaaSHost\Features\PluginBootstrap;

class RolesController
{
    public const REFRESH_NONCE_ACTION = 'wpcs_refresh_roles';
    public const REFRESH_LOCK_TRANSIENT = 'wpcs_roles_refresh_lock';
    public const REFRESH_LOCK_SECONDS = 60;

    private WPCSService $wpcsService;

    public function __construct(WPCSService $wpcsService)
    {
        $this->wpcsService = $wpcsService;

        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_post_wpcs_refresh_roles', [$this, 'admin_post_update_tenant_roles_list']);
    }

    public function register_rest_routes()
    {
        // Public on purpose: the store plugin in the production version pings this, without credentials, after
        // its roles change. The roles are only ever read from the production version's own roles.json, so a
        // caller can't choose what gets stored; refreshes are rate-limited so the route can't tie up PHP workers.
        register_rest_route(PluginBootstrap::API_V1_NAMESPACE, '/user-role-plan/update', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'update_tenant_roles_list_callback'],
        ]);
    }

    public static function get_refresh_url(): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action=wpcs_refresh_roles'), self::REFRESH_NONCE_ACTION);
    }

    public function admin_post_update_tenant_roles_list()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to refresh roles.'), 403);
        }

        check_admin_referer(self::REFRESH_NONCE_ACTION);

        try {
            $this->update_tenant_roles_list();
        } catch (\Exception $error) {
            // Set a transient notice message
            set_transient(AdminNotices::REFRESH_ROLES_FAILED_NOTICE, 'Roles could not be refreshed. Please check that the state of the version labelled "Production" is "Running", has the WaaS-Client installed and has actual roles.', 5);
        }

        wp_safe_redirect(wp_get_referer());
    }

    public function update_tenant_roles_list()
    {
        $wpcs_production_version = $this->wpcsService->get_production_version();

        $wpcs_production_version_domain_name = $wpcs_production_version->domain;
        if (strpos($wpcs_production_version_domain_name, 'http') !== 0) {
            $wpcs_production_version_domain_name = 'https://' . $wpcs_production_version_domain_name;
        }

        $response = wp_remote_get(trailingslashit($wpcs_production_version_domain_name) . 'wp-content/plugins/waas-client-data/roles.json');
        $response_code = wp_remote_retrieve_response_code($response);

        if(200 !== $response_code) {
            throw new \Exception('Refreshing roles failed with code '.$response_code);
        }

        $body = json_decode($response['body']);
        update_option(PluginBootstrap::ROLES_WP_OPTION, $body);
    }

    public function update_tenant_roles_list_callback(): \WP_REST_Response
    {
        if (get_transient(self::REFRESH_LOCK_TRANSIENT)) {
            return new \WP_REST_Response(false, 429);
        }
        set_transient(self::REFRESH_LOCK_TRANSIENT, 1, self::REFRESH_LOCK_SECONDS);

        try {
            $this->update_tenant_roles_list();

            return new \WP_REST_Response(true, 200);
        } catch (\Exception $error) {
            return new \WP_REST_Response(false, 404);
        }
    }
}

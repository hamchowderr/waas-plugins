<?php

namespace WaaSHost\Api;

use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Features\AdminNotices;
use WaaSHost\Features\PluginBootstrap;

class RolesController
{
    public const REFRESH_NONCE_ACTION = 'waas_refresh_roles';
    public const REFRESH_LOCK_TRANSIENT = 'waas_roles_refresh_lock';
    public const REFRESH_LOCK_SECONDS = 60;

    private ProvisioningApi $provisioningApi;

    public function __construct(ProvisioningApi $provisioningApi)
    {
        $this->provisioningApi = $provisioningApi;

        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_post_waas_refresh_roles', [$this, 'admin_post_update_tenant_roles_list']);
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
        return wp_nonce_url(admin_url('admin-post.php?action=waas_refresh_roles'), self::REFRESH_NONCE_ACTION);
    }

    public function admin_post_update_tenant_roles_list()
    {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to refresh roles.', 'waas-host'), 403);
        }

        check_admin_referer(self::REFRESH_NONCE_ACTION);

        try {
            $this->update_tenant_roles_list();
        } catch (\Exception $error) {
            // Set a transient notice message
            set_transient(AdminNotices::REFRESH_ROLES_FAILED_NOTICE, __('Roles could not be loaded. Check that your template version labelled "Production" is running, has WaaS Client active, and has at least one role.', 'waas-host'), 5);
        }

        wp_safe_redirect(wp_get_referer());
    }

    public function update_tenant_roles_list()
    {
        self::load($this->provisioningApi);
    }

    /** Reads the roles from the production version's roles.json and stores them. Throws when they can't be read. */
    public static function load(ProvisioningApi $provisioningApi): void
    {
        $production_version = $provisioningApi->get_production_version();

        $production_version_domain_name = $production_version->domain;
        if (strpos($production_version_domain_name, 'http') !== 0) {
            $production_version_domain_name = 'https://' . $production_version_domain_name;
        }

        $response = wp_remote_get(trailingslashit($production_version_domain_name) . 'wp-content/plugins/waas-client-data/roles.json');
        $response_code = wp_remote_retrieve_response_code($response);

        if(200 !== $response_code) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not printed here; every place that shows the message escapes it.
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

<?php

namespace WaaSHost\Features;

use WaaSHost\Core\ConfigService;

class AdminNotices
{
    const REFRESH_ROLES_FAILED_NOTICE = 'refresh_roles_failed_notice';
    /** The plugin's own admin pages, the only places the DNS settings warning shows. */
    const PLUGIN_PAGES = ['wpcs-admin', 'wpcs-admin-settings'];

    private static ConfigService $config;

    public static function init(ConfigService $config)
    {
        static::$config = $config;
        add_action('admin_notices', [__CLASS__, 'show_refresh_roles_failed_notice']);
        add_action('admin_notices', [__CLASS__, 'show_dns_settings_missing_notice']);
    }

    /** The customer's domain page leaves out any DNS record whose setting is empty. */
    public static function show_dns_settings_missing_notice()
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (!in_array($page, static::PLUGIN_PAGES, true) || !current_user_can('manage_options')) {
            return;
        }

        $missing = [];
        if (!static::$config->get_dns_a_records()) {
            $missing[] = 'A record IPs';
        }
        if (static::$config->get_dns_cname_target() === '') {
            $missing[] = 'CNAME target';
        }
        if (!$missing) {
            return;
        }

        $settings_url = admin_url('admin.php?page=wpcs-admin-settings');
        echo '<div class="notice notice-warning"><p>'
            . esc_html('Custom domain DNS settings missing: ' . implode(', ', $missing) . '. Customers get no DNS instructions for these on their domain page; with neither set, they are told to contact support. ')
            . '<a href="' . esc_url($settings_url) . '">' . esc_html('Set them in Settings') . '</a>.</p></div>';
    }

    public static function show_refresh_roles_failed_notice()
    {
        $notice = get_transient(static::REFRESH_ROLES_FAILED_NOTICE);
        
        if ($notice) 
        {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';

            // Delete the transient after displaying the notice
            delete_transient(static::REFRESH_ROLES_FAILED_NOTICE);
        }
    }
}

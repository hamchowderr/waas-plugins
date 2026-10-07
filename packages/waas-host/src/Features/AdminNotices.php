<?php

namespace WaaSHost\Features;

use WaaSHost\Core\ConfigService;

class AdminNotices
{
    const REFRESH_ROLES_FAILED_NOTICE = 'refresh_roles_failed_notice';

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
        if (!current_user_can('manage_options')) {
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
            . esc_html('Custom domain DNS settings missing: ' . implode(', ', $missing) . '. Customers get no DNS instructions for these on their domain page. ')
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

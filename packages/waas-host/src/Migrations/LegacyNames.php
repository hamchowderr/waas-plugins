<?php

namespace WaaSHost\Migrations;

// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

/**
 * Moves what WaaS Host saved under the old platform's names (settings, order, subscription and product meta, and
 * scheduled jobs) to the waas_ names it uses since 2.3.0. Runs once, before anything reads them; running it again
 * changes nothing.
 */
class LegacyNames
{
    const DONE_OPTION = 'waas_legacy_names_moved';

    /** Settings: old option name => new. */
    const OPTIONS = [
        'wpcs_credentials_api_base_url_setting' => 'waas_api_base_url',
        'wpcs_credentials_api_key_setting' => 'waas_api_key',
        'wpcs_credentials_api_secret_setting' => 'waas_api_secret',
        'wpcs_credentials_region_setting' => 'waas_region',
        'wpcs_dns_a_records_setting' => 'waas_dns_a_records',
        'wpcs_dns_cname_target_setting' => 'waas_dns_cname_target',
        'wpcs_host_settings_root_domain' => 'waas_root_domain',
        'wpcs_host_settings_default_user_role' => 'waas_default_user_role',
        'wpcs_host_settings_deletion_grace_days' => 'waas_deletion_grace_days',
        'wpcs_host_setting_remove_admininistrator_plugin_capabilities_on_tenant' => 'waas_remove_administrator_plugin_caps',
        'wpcs_notification_settings_send_tenant_ready_email' => 'waas_notification_settings_send_tenant_ready_email',
        'wpcs_notification_settings_tenant_ready_email_subject' => 'waas_notification_settings_tenant_ready_email_subject',
        'wpcs_notification_settings_tenant_ready_email_body' => 'waas_notification_settings_tenant_ready_email_body',
        'wpcs_notification_settings_send_suspend_scheduled_email' => 'waas_notification_settings_send_suspend_scheduled_email',
        'wpcs_notification_settings_suspend_scheduled_email_subject' => 'waas_notification_settings_suspend_scheduled_email_subject',
        'wpcs_notification_settings_suspend_scheduled_email_body' => 'waas_notification_settings_suspend_scheduled_email_body',
        'wpcs-plugin-version' => 'waas_host_db_version',
        'WPCS_CAN_REACH_API' => 'waas_can_reach_api',
    ];

    /** Meta keys on orders, subscriptions and products, in every cart's meta tables: old => new. */
    const META_KEYS = [
        'WPCS_WEBSITE_NAME_META' => 'waas_website_name',
        'WPCS_WEBSITE_NAME_UNVERIFIED_META' => 'waas_website_name_unverified',
        'WPCS_DOMAIN_NAME_META' => 'waas_domain_name',
        'WPCS_BASE_DOMAIN_NAME_META' => 'waas_base_domain_name',
        'WPCS_SUBSCRIPTION_USER_ROLES' => 'waas_subscription_user_roles',
        'WPCS_TENANT_EXTERNAL_ID_META' => 'waas_tenant_external_id',
        'WPCS_TENANT_PUBLIC_KEY_META' => 'waas_tenant_public_key',
        'WPCS_TENANT_DELETE_AT_META' => 'waas_tenant_delete_at',
        'WPCS_TENANT_SUSPEND_AT_META' => 'waas_tenant_suspend_at',
        'WPCS_TENANT_SUSPEND_REASON_META' => 'waas_tenant_suspend_reason',
        'WPCS_NAME_CONFLICT_META' => 'waas_name_conflict',
        'WPCS_TENANT_STATE' => 'waas_tenant_state',
        'WPCS_STORE_JOBS' => 'waas_store_jobs',
        'WPCS_STORE_JOB_FAILURE' => 'waas_store_job_failure',
        '_is_wpcs_product' => '_is_waas_product',
    ];

    /** Scheduled jobs (WP-Cron and Action Scheduler): old hook => new. */
    const HOOKS = [
        'wpcs_cron_poll_tenant_status' => 'waas_poll_store_status',
        'wpcs_run_store_jobs' => 'waas_run_store_jobs',
        'wpcs_sweep_store_jobs' => 'waas_sweep_store_jobs',
        'wpcs_delete_suspended_tenant' => 'waas_delete_suspended_store',
        'wpcs_suspend_at_period_end' => 'waas_suspend_at_period_end',
    ];

    public static function maybe_run(): void
    {
        if (get_option(self::DONE_OPTION) === '1') {
            return;
        }
        self::run();
        update_option(self::DONE_OPTION, '1');
    }

    public static function run(): void
    {
        self::move_options();
        self::move_meta();
        self::move_cron();
        self::move_scheduled_actions();
        wp_cache_flush();
    }

    private static function move_options(): void
    {
        $missing = new \stdClass();
        foreach (self::OPTIONS as $old => $new) {
            $value = get_option($old, $missing);
            if ($value === $missing) {
                continue;
            }
            // A value already saved under the new name (set after an earlier run, or by the API) wins.
            if (get_option($new, $missing) === $missing) {
                add_option($new, $value);
            }
            delete_option($old);
        }
    }

    /** Every table with a meta_key column: WordPress's, WooCommerce's orders and FluentCart's. */
    private static function move_meta(): void
    {
        global $wpdb;

        $tables = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = %s AND TABLE_NAME LIKE %s',
            'meta_key',
            $wpdb->esc_like($wpdb->prefix) . '%'
        ));
        foreach ((array) $tables as $table) {
            foreach (self::META_KEYS as $old => $new) {
                $wpdb->query($wpdb->prepare("UPDATE `" . esc_sql($table) . "` SET meta_key = %s WHERE meta_key = %s", $new, $old));
            }
        }
    }

    /** One-off jobs keep their time and arguments under the new hook; repeating ones are scheduled again by their owner. */
    private static function move_cron(): void
    {
        $crons = _get_cron_array();
        if (!is_array($crons)) {
            return;
        }
        foreach ($crons as $timestamp => $hooks) {
            foreach (self::HOOKS as $old => $new) {
                foreach ($hooks[$old] ?? [] as $event) {
                    if (empty($event['schedule'])) {
                        wp_schedule_single_event((int) $timestamp, $new, $event['args'] ?? []);
                    }
                }
            }
        }
        foreach (array_keys(self::HOOKS) as $old) {
            wp_clear_scheduled_hook($old);
        }
    }

    private static function move_scheduled_actions(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'actionscheduler_actions';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            return;
        }
        foreach (self::HOOKS as $old => $new) {
            $wpdb->query($wpdb->prepare("UPDATE `$table` SET hook = %s WHERE hook = %s AND status = 'pending'", $new, $old));
        }
    }
}

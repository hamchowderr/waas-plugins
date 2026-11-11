<?php

namespace AnyPressHost\Migrations;

use AnyPressHost\Core\SecretBox;
use AnyPressHost\Core\Tenant;
use AnyPressHost\Features\Webhooks;

// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a one-time rename of AnyPress's own old option, meta and scheduled-task names. WordPress has no function that finds options or meta by name prefix, and Action Scheduler has none that renames a hook; nothing here is worth caching.

/**
 * Moves what the plugin saved before it was called AnyPress Host (2.11.0) to its anypress_ names: settings, order,
 * subscription and product meta, and scheduled jobs. Secrets sealed under the old names are opened and sealed again
 * under the new ones. Runs once, before anything reads them; running it again changes nothing.
 */
class OldNames
{
    public const DONE_OPTION = 'anypress_old_names_moved';

    /** The prefixes the plugin used before 2.11.0, and the ones it uses now. */
    public const OLD = 'waas_';
    public const NEW = 'anypress_';

    /** What secrets were sealed with before 2.11.0: their prefix, key constant and purpose names. */
    public const OLD_SEAL_PREFIX = 'waasenc:v1:';
    public const OLD_KEY_CONSTANT = 'WAAS_HOST_KEY_ENCRYPTION_KEY';
    public const OLD_PURPOSES = [
        'private-key' => ['waas-host-private-key-encryption', 'anypress-host-private-key-encryption'],
        'webhook' => ['waas-host-webhook-secret', 'anypress-host-webhook-secret'],
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
        self::reseal();
    }

    /** The new name for an old one ("waas_x" => "anypress_x", "_waas_x" => "_anypress_x", "_is_waas_product" too). */
    public static function rename(string $name): string
    {
        return str_replace(self::OLD, self::NEW, $name);
    }

    private static function move_options(): void
    {
        global $wpdb;

        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like(self::OLD) . '%',
            '%' . $wpdb->esc_like('_' . self::OLD) . '%'
        ));
        $missing = new \stdClass();
        foreach ((array) $names as $old) {
            $new = self::rename((string) $old);
            if ($new === $old) {
                continue;
            }
            $value = get_option($old, $missing);
            // A value already saved under the new name (set after an earlier run, or by the API) wins.
            if ($value !== $missing && get_option($new, $missing) === $missing) {
                add_option($new, $value);
            }
            delete_option($old);
        }
    }

    /** Every table with a meta_key column: WordPress's, WooCommerce's orders and FluentCart's. */
    private static function move_meta(): void
    {
        global $wpdb;

        foreach (self::meta_tables() as $table) {
            $wpdb->query($wpdb->prepare(
                'UPDATE %i SET meta_key = REPLACE(meta_key, %s, %s) WHERE meta_key LIKE %s',
                $table,
                self::OLD,
                self::NEW,
                '%' . $wpdb->esc_like(self::OLD) . '%'
            ));
        }
    }

    /** One-off jobs keep their time and arguments under the new hook; repeating ones are scheduled again by their owner. */
    private static function move_cron(): void
    {
        $crons = _get_cron_array();
        if (!is_array($crons)) {
            return;
        }
        $old_hooks = [];
        foreach ($crons as $timestamp => $hooks) {
            foreach ((array) $hooks as $hook => $events) {
                if (strpos((string) $hook, self::OLD) !== 0) {
                    continue;
                }
                $old_hooks[$hook] = true;
                foreach ((array) $events as $event) {
                    if (empty($event['schedule'])) {
                        wp_schedule_single_event((int) $timestamp, self::rename((string) $hook), $event['args'] ?? []);
                    }
                }
            }
        }
        // wp_unschedule_hook, not wp_clear_scheduled_hook: the latter only removes events scheduled without arguments.
        foreach (array_keys($old_hooks) as $hook) {
            wp_unschedule_hook($hook);
        }
    }

    private static function move_scheduled_actions(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'actionscheduler_actions';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            return;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET hook = REPLACE(hook, %s, %s) WHERE hook LIKE %s AND status = 'pending'",
            $table,
            self::OLD,
            self::NEW,
            $wpdb->esc_like(self::OLD) . '%'
        ));
    }

    /** The webhook secret and every store's login key, sealed again with the new names. */
    private static function reseal(): void
    {
        global $wpdb;

        $record = get_option(Webhooks::OPTION);
        if (is_array($record) && is_string($record['secret'] ?? null) && strpos($record['secret'], self::OLD_SEAL_PREFIX) === 0) {
            $record['secret'] = self::reseal_value($record['secret'], 'webhook');
            update_option(Webhooks::OPTION, $record);
        }

        foreach (self::meta_tables() as $table) {
            $id = $wpdb->get_var($wpdb->prepare("SHOW KEYS FROM %i WHERE Key_name = 'PRIMARY'", $table), 4);
            if (!$id) {
                continue;
            }
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT %i AS id, meta_value FROM %i WHERE meta_key = %s AND meta_value LIKE %s',
                $id,
                $table,
                Tenant::TENANT_PRIVATE_KEY_META,
                $wpdb->esc_like(self::OLD_SEAL_PREFIX) . '%'
            ));
            foreach ((array) $rows as $row) {
                $wpdb->update($table, ['meta_value' => self::reseal_value((string) $row->meta_value, 'private-key')], [$id => $row->id]);
            }
        }
        wp_cache_flush();
    }

    /** Opens a value sealed before 2.11.0 and seals it under the new names; '' when it can't be opened. */
    public static function reseal_value(string $stored, string $kind): string
    {
        [$old_purpose, $new_purpose] = self::OLD_PURPOSES[$kind];
        $secret = defined(self::OLD_KEY_CONSTANT) ? (string) constant(self::OLD_KEY_CONSTANT) : wp_salt('auth');
        $key = sodium_crypto_generichash($old_purpose . '|' . $secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $raw = base64_decode(substr($stored, strlen(self::OLD_SEAL_PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $plaintext = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);

        return $plaintext === false ? '' : SecretBox::seal($plaintext, $new_purpose);
    }

    /** @return string[] */
    private static function meta_tables(): array
    {
        global $wpdb;

        return (array) $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = %s AND TABLE_NAME LIKE %s',
            'meta_key',
            $wpdb->esc_like($wpdb->prefix) . '%'
        ));
    }
}

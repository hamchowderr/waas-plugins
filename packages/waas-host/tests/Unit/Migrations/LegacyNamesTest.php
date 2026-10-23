<?php

namespace Tests\Unit\Migrations;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSHost\Migrations\LegacyNames;

class LegacyNamesTest extends TestCase
{
    private array $options;
    private array $queries;
    private array $scheduled;
    private array $cleared;

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = [];
        $this->queries = [];
        $this->scheduled = [];
        $this->cleared = [];

        Functions\when('get_option')->alias(fn ($name, $default = false) => array_key_exists($name, $this->options) ? $this->options[$name] : $default);
        Functions\when('add_option')->alias(function ($name, $value) {
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('update_option')->alias(function ($name, $value) {
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($name) {
            unset($this->options[$name]);
            return true;
        });
        Functions\when('esc_sql')->returnArg();
        Functions\when('wp_cache_flush')->justReturn(true);
        Functions\when('_get_cron_array')->justReturn([]);
        Functions\when('wp_schedule_single_event')->alias(function ($timestamp, $hook, $args) {
            $this->scheduled[] = [$timestamp, $hook, $args];
            return true;
        });
        Functions\when('wp_unschedule_hook')->alias(function ($hook) {
            $this->cleared[] = $hook;
            return 0;
        });

        $test = $this;
        $GLOBALS['wpdb'] = new class($test) {
            public string $prefix = 'wp_';
            public function __construct(private $test) {}
            public function esc_like($text) { return addcslashes($text, '_%\\'); }
            public function prepare($query, ...$args) { return vsprintf(str_replace('%s', "'%s'", $query), $args); }
            public function get_col($query) { return ['wp_postmeta', 'wp_fct_subscription_meta']; }
            public function get_var($query) { return null; }
            public function query($query) { $this->test->record($query); return 1; }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function record(string $query): void
    {
        $this->queries[] = $query;
    }

    public function test_settings_move_to_the_new_names(): void
    {
        $this->options['wpcs_credentials_api_key_setting'] = 'key-1';
        $this->options['wpcs_host_settings_root_domain'] = 'anypress.site';

        LegacyNames::run();

        $this->assertSame('key-1', $this->options['waas_api_key']);
        $this->assertSame('anypress.site', $this->options['waas_root_domain']);
        $this->assertArrayNotHasKey('wpcs_credentials_api_key_setting', $this->options);
        $this->assertArrayNotHasKey('wpcs_host_settings_root_domain', $this->options);
    }

    public function test_a_value_already_under_the_new_name_wins(): void
    {
        $this->options['wpcs_host_settings_root_domain'] = 'old.example';
        $this->options['waas_root_domain'] = 'anypress.site';

        LegacyNames::run();

        $this->assertSame('anypress.site', $this->options['waas_root_domain']);
        $this->assertArrayNotHasKey('wpcs_host_settings_root_domain', $this->options);
    }

    public function test_a_setting_saved_as_empty_or_false_still_moves(): void
    {
        $this->options['wpcs_notification_settings_send_tenant_ready_email'] = '';

        LegacyNames::run();

        $this->assertSame('', $this->options['waas_notification_settings_send_tenant_ready_email']);
    }

    public function test_meta_keys_are_renamed_in_every_meta_table(): void
    {
        LegacyNames::run();

        $this->assertContains("UPDATE `wp_postmeta` SET meta_key = 'waas_website_name' WHERE meta_key = 'WPCS_WEBSITE_NAME_META'", $this->queries);
        $this->assertContains("UPDATE `wp_fct_subscription_meta` SET meta_key = 'waas_tenant_state' WHERE meta_key = 'WPCS_TENANT_STATE'", $this->queries);
        $this->assertContains("UPDATE `wp_postmeta` SET meta_key = '_is_waas_product' WHERE meta_key = '_is_wpcs_product'", $this->queries);
        $this->assertCount(2 * count(LegacyNames::META_KEYS), $this->queries);
    }

    public function test_one_off_jobs_keep_their_time_and_arguments_and_repeating_ones_are_dropped(): void
    {
        Functions\when('_get_cron_array')->justReturn([
            1800000000 => [
                'wpcs_delete_suspended_tenant' => ['k1' => ['schedule' => false, 'args' => [42]]],
                'something_else' => ['k2' => ['schedule' => false, 'args' => []]],
            ],
            1700000000 => [
                'wpcs_cron_poll_tenant_status' => ['k3' => ['schedule' => 'five_seconds', 'args' => [], 'interval' => 5]],
            ],
        ]);

        LegacyNames::run();

        $this->assertSame([[1800000000, 'waas_delete_suspended_store', [42]]], $this->scheduled);
        $this->assertContains('wpcs_delete_suspended_tenant', $this->cleared);
        $this->assertContains('wpcs_cron_poll_tenant_status', $this->cleared);
    }

    public function test_it_runs_once(): void
    {
        $this->options['wpcs_credentials_api_key_setting'] = 'key-1';

        LegacyNames::maybe_run();
        $this->options['wpcs_credentials_api_key_setting'] = 'written-later';
        LegacyNames::maybe_run();

        $this->assertSame('1', $this->options[LegacyNames::DONE_OPTION]);
        $this->assertSame('key-1', $this->options['waas_api_key']);
    }
}

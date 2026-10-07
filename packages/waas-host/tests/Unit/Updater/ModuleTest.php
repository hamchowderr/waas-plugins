<?php

namespace Tests\Unit\Updater;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSHost\Core\ConfigService;
use WaaSHost\Updater\Module;
use WaaSHost\Updater\Updater;

if (!defined('WPCS_WAAS_HOST_SLUG')) {
    define('WPCS_WAAS_HOST_SLUG', 'wpcs-waas-host');
    define('WPCS_WAAS_HOST_BASENAME', 'waas-host/index.php');
    define('WPCS_WAAS_HOST_UPDATE_URI', 'wpcs-waas-host');
    define('WPCS_WAAS_HOST_VERSION', '2.2.7');
}

/**
 * WaaS Host's updates come only from our channel (the shared Updater, tested in
 * packages/waas-updater), and it lends the storefront's API credentials to waas-payments.
 */
class ModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_parse_url')->alias('parse_url');
        Functions\when('sanitize_url')->alias(function ($url) { return strpos($url, '://') === false ? 'http://' . $url : $url; });
    }

    private function config($base_url, $key, $secret): ConfigService
    {
        $config = \Mockery::mock(ConfigService::class);
        $config->shouldReceive('get_api_base_url')->andReturn($base_url);
        $config->shouldReceive('get_api_key')->andReturn($key);
        $config->shouldReceive('get_api_secret')->andReturn($secret);
        return $config;
    }

    public function test_credentials_come_from_the_api_settings(): void
    {
        $this->assertSame(
            ['base_url' => 'https://waas-api.example.test', 'key' => 'key-id', 'secret' => 'not-a-secret'],
            Module::credentials($this->config('https://waas-api.example.test', 'key-id', 'not-a-secret'))
        );
        $this->assertNull(Module::credentials($this->config(false, 'key-id', 'not-a-secret')));
        $this->assertNull(Module::credentials($this->config('https://waas-api.example.test', '', 'not-a-secret')));
        $this->assertNull(Module::credentials($this->config('https://waas-api.example.test', 'key-id', false)));
    }

    public function test_it_lends_the_credentials_to_our_other_plugins(): void
    {
        $captured = null;
        Functions\when('add_filter')->alias(function ($hook, $callback) use (&$captured) {
            if ($hook === 'waas_update_credentials') {
                $captured = $callback;
            }
            return true;
        });

        Module::init($this->config('https://waas-api.example.test', 'key-id', 'not-a-secret'));

        $this->assertIsCallable($captured);
        $this->assertSame('key-id', $captured(null)['key']);
        $other = ['base_url' => 'https://elsewhere.test', 'key' => 'k', 'secret' => 's'];
        $this->assertSame($other, $captured($other), 'Credentials another filter already supplied are kept.');
    }

    public function test_update_hooks_are_registered_only_once_a_signing_key_is_built_in(): void
    {
        Functions\expect('wp_remote_get')->never();
        $this->assertInstanceOf(Updater::class, Module::init($this->config('https://waas-api.example.test', 'key-id', 'not-a-secret')));

        if (empty(Updater::PUBLIC_KEYS)) {
            $this->assertFalse(has_filter('upgrader_pre_download'));
            $this->assertFalse(has_filter('update_plugins_wpcs-waas-host'));
        } else {
            $this->assertNotFalse(has_filter('upgrader_pre_download'));
            $this->assertNotFalse(has_filter('update_plugins_wpcs-waas-host'));
        }
    }

    public function test_the_copy_of_the_shared_updater_is_in_sync(): void
    {
        exec(escapeshellarg(dirname(__DIR__, 5) . '/scripts/sync-updater.sh') . ' --check 2>&1', $out, $status);
        $this->assertSame(0, $status, implode("\n", $out));
    }
}

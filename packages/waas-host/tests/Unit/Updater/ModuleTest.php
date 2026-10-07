<?php

namespace Tests\Unit\Updater;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSHost\Updater\Module;

if (!defined('WPCS_WAAS_HOST_SLUG')) {
    define('WPCS_WAAS_HOST_SLUG', 'wpcs-waas-host');
    define('WPCS_WAAS_HOST_BASENAME', 'waas-host/index.php');
    define('WPCS_WAAS_HOST_UPDATE_URI', 'wpcs-waas-host');
    define('WPCS_WAAS_HOST_VERSION', '2.2.7');
}

/** The self-updater stays off until we have our own update channel, and a failed fetch never fatals. */
class ModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('WP_Error')) {
            eval('class WP_Error { public $code; public function __construct($code = "", $message = "") { $this->code = $code; } }');
        }
        Functions\when('is_wp_error')->alias(fn ($value) => $value instanceof \WP_Error);
        (new \ReflectionProperty(Module::class, 'remote_data'))->setValue(null, null);
    }

    public function test_without_an_info_url_no_hooks_are_registered_and_no_update_request_is_made(): void
    {
        $this->assertFalse(defined('WAAS_UPDATE_INFO_URL'));
        Functions\expect('wp_remote_get')->never();

        Module::init();

        $this->assertFalse(has_filter('plugins_api', [Module::class, 'modify_plugin_details']));

        $this->assertFalse(Module::maybe_show_update_available(false, [], WPCS_WAAS_HOST_BASENAME));
        $args = (object) ['slug' => WPCS_WAAS_HOST_SLUG];
        $this->assertFalse(Module::modify_plugin_details(false, 'plugin_information', $args));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_failed_fetch_returns_the_error_and_is_cached(): void
    {
        define('WAAS_UPDATE_INFO_URL', 'https://updates.example.test/wpcs-waas-host-info.json');
        $error = new \WP_Error('http_request_failed', 'cURL error 28');
        Functions\expect('wp_remote_get')->once()->with(WAAS_UPDATE_INFO_URL)->andReturn($error);

        Module::init();
        $this->assertNotFalse(has_filter('plugins_api', [Module::class, 'modify_plugin_details']));

        $this->assertSame($error, Module::get_remote_data());
        $this->assertSame($error, Module::get_remote_data());
        $this->assertFalse(Module::maybe_show_update_available(false, [], WPCS_WAAS_HOST_BASENAME));
    }
}

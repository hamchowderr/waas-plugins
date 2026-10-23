<?php

namespace Tests\Unit\Features;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WaaSClient\Features\PluginBootstrap;

/**
 * Stores built before WaaS Client 2.4.0 were given their flag and constants under the old platform's prefix.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PluginBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        putenv('WAAS_IS_TENANT');
        putenv('WPCS_IS_TENANT');
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_a_store_is_recognised_by_either_flag(): void
    {
        $this->assertFalse(PluginBootstrap::is_store());
        putenv('WPCS_IS_TENANT=true');
        $this->assertTrue(PluginBootstrap::is_store(), 'old flag');
        putenv('WPCS_IS_TENANT');
        putenv('WAAS_IS_TENANT=true');
        $this->assertTrue(PluginBootstrap::is_store());
    }

    public function test_the_old_constant_spelling_is_read_when_the_new_one_is_missing(): void
    {
        $this->assertFalse(PluginBootstrap::has_constant('WAAS_TENANT_ROLES'));
        define('WPCS_TENANT_ROLES', 'website');
        $this->assertSame('website', PluginBootstrap::constant('WAAS_TENANT_ROLES'));
        define('WAAS_TENANT_ROLES', 'store');
        $this->assertSame('store', PluginBootstrap::constant('WAAS_TENANT_ROLES'), 'the new spelling wins');
    }

    public function test_saved_options_move_to_the_new_names(): void
    {
        $options = ['WPCS_REMOVED_ADMINISTRATOR_PLUGIN_CAPS' => true];
        Functions\when('get_option')->alias(fn ($name, $default = false) => array_key_exists($name, $options) ? $options[$name] : $default);
        Functions\when('add_option')->alias(function ($name, $value) use (&$options) { $options[$name] = $value; return true; });
        Functions\when('delete_option')->alias(function ($name) use (&$options) { unset($options[$name]); return true; });

        PluginBootstrap::move_legacy_options();

        $this->assertSame(['waas_removed_administrator_plugin_caps' => true], $options);
    }
}

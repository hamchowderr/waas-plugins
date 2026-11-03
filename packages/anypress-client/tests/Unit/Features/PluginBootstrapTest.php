<?php

namespace Tests\Unit\Features;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use AnyPressClient\Features\PluginBootstrap;

/**
 * The store flag (an environment variable the API sets) and the wp-config constants a store is given.
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
        putenv('ANYPRESS_IS_TENANT');
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_a_store_is_recognised_by_its_flag(): void
    {
        $this->assertFalse(PluginBootstrap::is_store());
        putenv('ANYPRESS_IS_TENANT=true');
        $this->assertTrue(PluginBootstrap::is_store());
    }

    public function test_a_constant_is_read_once_defined(): void
    {
        $this->assertFalse(PluginBootstrap::has_constant('ANYPRESS_TENANT_ROLES'));
        $this->assertNull(PluginBootstrap::constant('ANYPRESS_TENANT_ROLES'));
        define('ANYPRESS_TENANT_ROLES', 'website');
        $this->assertTrue(PluginBootstrap::has_constant('ANYPRESS_TENANT_ROLES'));
        $this->assertSame('website', PluginBootstrap::constant('ANYPRESS_TENANT_ROLES'));
    }
}

<?php

namespace Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base class for unit tests: sets up Brain Monkey so WordPress functions
 * (get_option, apply_filters, wp_remote_*) can be stubbed per test.
 */
abstract class TestCase extends PHPUnitTestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // The account panel's styles go through WordPress's style queue; tests that render it don't check them.
        Monkey\Functions\when('plugins_url')->alias(fn ($path = '') => 'https://store.example/wp-content/plugins/waas-host/' . $path);
        Monkey\Functions\when('wp_register_style')->justReturn(true);
        Monkey\Functions\when('wp_add_inline_style')->justReturn(true);
        Monkey\Functions\when('wp_print_styles')->justReturn([]);
        \WaaSHost\Features\AccountStyles::reset();
        // Core's wrappers behave like PHP's own functions here.
        Monkey\Functions\when('wp_parse_url')->alias(fn ($url, $component = -1) => parse_url($url, $component));
        Monkey\Functions\when('wp_json_encode')->alias(fn ($data, $flags = 0, $depth = 512) => json_encode($data, $flags, $depth));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}

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
        // Core's input and output helpers, close enough to their real behaviour for these tests; a test can override them.
        Monkey\Functions\when('wp_unslash')->alias(fn ($value) => is_string($value) ? stripslashes($value) : $value);
        Monkey\Functions\when('sanitize_text_field')->alias(fn ($value) => trim(strip_tags((string) $value)));
        Monkey\Functions\when('wp_strip_all_tags')->alias(fn ($text) => trim(strip_tags((string) $text)));
        Monkey\Functions\when('wp_json_encode')->alias(fn ($data, $flags = 0, $depth = 512) => json_encode($data, $flags, $depth));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}

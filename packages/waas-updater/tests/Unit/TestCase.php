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
        // WordPress's wp_delete_file() is unlink() behind a filter.
        Monkey\Functions\when('wp_delete_file')->alias(function ($file) {
            @unlink($file);
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}

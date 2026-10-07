<?php

namespace Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base class for unit tests: Brain Monkey stands in for WordPress functions.
 */
abstract class TestCase extends PHPUnitTestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubTranslationFunctions();
        Functions\when('is_wp_error')->alias(fn ($value) => $value instanceof \WP_Error);
        Functions\when('wp_json_encode')->alias(fn ($value) => json_encode($value));
        Functions\when('wp_remote_retrieve_body')->alias(fn ($response) => $response['body']);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn ($response) => $response['response']['code']);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('add_action')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** A wp_remote_post() response carrying a fixture from tests/fixtures/authorizenet. */
    protected static function fixture(string $name): array
    {
        return [
            'response' => ['code' => 200],
            'body'     => file_get_contents(dirname(__DIR__) . '/fixtures/authorizenet/' . $name . '.json'),
        ];
    }
}

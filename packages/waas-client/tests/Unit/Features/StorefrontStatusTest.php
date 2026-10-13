<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSClient\Core\HttpService;
use WaaSClient\Features\AdminRolesSettings;
use WaaSClient\Features\StorefrontStatus;

/**
 * The storefront's answer to the roles ping (and to the connection test) is reported, not swallowed.
 */
class StorefrontStatusTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('update_option')->alias(function ($name, $value) {
            $this->saved[$name] = $value;
            return true;
        });
        Functions\when('is_wp_error')->alias(fn ($thing) => $thing instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn ($response) => $response['response']['code'] ?? '');
        Functions\when('wp_remote_retrieve_body')->alias(fn ($response) => $response['body'] ?? '');
    }

    public static function setUpBeforeClass(): void
    {
        if (!class_exists('WP_Error')) {
            eval('class WP_Error { public $code; public $message; public $data; public function __construct($code = "", $message = "", $data = "") { $this->code = $code; $this->message = $message; $this->data = $data; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }');
        }
    }

    /** @dataProvider pings */
    public function test_ping_result_is_reported(int $code, string $body, string $type, string $phrase): void
    {
        $this->storefrontAnswers($code, $body);

        $outcome = StorefrontStatus::notify_storefront(new HttpService('https://shop.example.com/wp-json/waas-host/v1'), 'https://shop.example.com');

        $this->assertSame($type, $outcome['type']);
        $this->assertStringContainsString($phrase, $outcome['message']);
        $this->assertSame($code, $outcome['code']);
        $this->assertSame($outcome, $this->saved[StorefrontStatus::PING_OPTION]);
    }

    public function pings(): array
    {
        return [
            'reloaded' => [200, 'true', 'success', 'reloaded'],
            'rate limited' => [429, 'false', 'warning', 'less than a minute ago'],
            'production version failed' => [404, 'false', 'error', 'labelled Production'],
            'no waas host' => [404, '{"code":"rest_no_route","message":"No route"}', 'error', 'WaaS Host'],
            'server error' => [500, '', 'error', 'HTTP 500'],
            '200 but false' => [200, 'false', 'error', 'HTTP 200'],
        ];
    }

    public function test_unreachable_storefront_is_an_error_with_the_reason(): void
    {
        // Another test may have defined a WP_Error stub without get_error_message().
        $error = new class('http_request_failed', 'cURL error 60: SSL certificate problem') extends \WP_Error {
            public function get_error_message() { return $this->message; }
        };
        Functions\when('wp_remote_get')->justReturn($error);

        $outcome = StorefrontStatus::notify_storefront(new HttpService('https://shop.example.com/wp-json/waas-host/v1'), 'https://shop.example.com');

        $this->assertSame('error', $outcome['type']);
        $this->assertSame(0, $outcome['code']);
        $this->assertStringContainsString('SSL certificate problem', $outcome['message']);
    }

    public function test_no_storefront_url_sends_nothing(): void
    {
        Functions\expect('wp_remote_get')->never();

        $outcome = StorefrontStatus::notify_storefront(new HttpService('/wp-json/waas-host/v1'), '');

        $this->assertSame('error', $outcome['type']);
        $this->assertStringContainsString('no storefront URL', $outcome['message']);
    }

    public function test_ping_goes_to_the_update_route(): void
    {
        Functions\expect('wp_remote_get')->once()
            ->with('https://shop.example.com/wp-json/waas-host/v1/user-role-plan/update', \Mockery::type('array'))
            ->andReturn(['response' => ['code' => 200], 'body' => 'true']);

        StorefrontStatus::notify_storefront(new HttpService('https://shop.example.com/wp-json/waas-host/v1'), 'https://shop.example.com');
    }

    public function test_connection_test_reads_the_namespace_index(): void
    {
        $index = json_encode(['namespace' => 'waas-host/v1', 'routes' => ['/waas-host/v1' => [], '/waas-host/v1/user-role-plan/update' => []]]);
        Functions\expect('wp_remote_get')->once()
            ->with('https://shop.example.com/wp-json/waas-host/v1', \Mockery::type('array'))
            ->andReturn(['response' => ['code' => 200], 'body' => $index]);

        $outcome = StorefrontStatus::test_connection(new HttpService('https://shop.example.com/wp-json/waas-host/v1'), 'https://shop.example.com');

        $this->assertSame('success', $outcome['type']);
        $this->assertSame($outcome, $this->saved[StorefrontStatus::TEST_OPTION]);
    }

    public function test_connection_test_without_waas_host_is_an_error(): void
    {
        $this->storefrontAnswers(404, '{"code":"rest_no_route"}');

        $outcome = StorefrontStatus::test_connection(new HttpService('https://shop.example.com/wp-json/waas-host/v1'), 'https://shop.example.com');

        $this->assertSame('error', $outcome['type']);
        $this->assertStringContainsString('not active', $outcome['message']);
    }

    public function test_get_still_returns_the_decoded_body_whatever_the_status(): void
    {
        $this->storefrontAnswers(429, 'false');

        $this->assertFalse((new HttpService('https://shop.example.com'))->get('/x'));
    }

    public function test_check_roles_flags_inactive_and_uninstalled_plugins(): void
    {
        $roles = [
            'basic' => ['title' => 'Basic', 'plugins' => ['shop/shop.php', 'gone/gone.php']],
            'pro' => ['title' => 'Pro', 'plugins' => ['shop/shop.php', 'seo/seo.php', 'waas-client/index.php']],
        ];
        $installed = ['shop/shop.php' => [], 'seo/seo.php' => [], 'forms/forms.php' => []];

        $health = AdminRolesSettings::check_roles($roles, $installed, fn ($file) => $file === 'shop/shop.php');

        $this->assertSame(['basic' => 1, 'pro' => 2], $health['counts']);
        $this->assertSame(['seo/seo.php' => ['pro']], $health['inactive']);
        $this->assertSame(['gone/gone.php' => ['basic']], $health['missing']);
        $this->assertSame(['forms/forms.php'], $health['unassigned']);
    }

    private function storefrontAnswers(int $code, string $body): void
    {
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => $code], 'body' => $body]);
    }
}

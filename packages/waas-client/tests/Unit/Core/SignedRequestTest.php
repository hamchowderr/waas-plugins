<?php

namespace Tests\Unit\Core;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSClient\Core\SignedRequest;

class SignedRequestTest extends TestCase
{
    private const KEY = 'k3y-k3y-k3y-k3y-k3y-k3y-k3y-k3y-k3y';
    private const ROUTE = '/waas-client/v1/site/pages';

    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->transients = [];
        Functions\when('get_transient')->alias(fn ($key) => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function ($key, $value) {
            $this->transients[$key] = $value;
            return true;
        });
    }

    public function test_a_signed_request_passes_once(): void
    {
        $header = $this->sign('POST', '{"title":"About"}');

        $this->assertTrue(SignedRequest::verify(self::KEY, $header, 'POST', self::ROUTE, '{"title":"About"}'));
        $this->assertFalse(SignedRequest::verify(self::KEY, $header, 'POST', self::ROUTE, '{"title":"About"}'), 'replayed');
    }

    /**
     * @dataProvider tampered
     */
    public function test_anything_changed_after_signing_fails(string $method, string $route, string $body, string $key): void
    {
        $header = $this->sign('POST', '{"title":"About"}');

        $this->assertFalse(SignedRequest::verify($key, $header, $method, $route, $body));
    }

    public function tampered(): array
    {
        return [
            'method' => ['DELETE', self::ROUTE, '{"title":"About"}', self::KEY],
            'route' => ['POST', '/waas-client/v1/site/menus', '{"title":"About"}', self::KEY],
            'body' => ['POST', self::ROUTE, '{"title":"Hacked"}', self::KEY],
            'key' => ['POST', self::ROUTE, '{"title":"About"}', 'another-key-another-key-another-key'],
        ];
    }

    public function test_old_or_future_signatures_fail(): void
    {
        $now = time();
        $this->assertFalse(SignedRequest::verify(self::KEY, $this->sign('GET', '', $now - 301), 'GET', self::ROUTE, '', $now));
        $this->assertFalse(SignedRequest::verify(self::KEY, $this->sign('GET', '', $now + 301), 'GET', self::ROUTE, '', $now));
        $this->assertTrue(SignedRequest::verify(self::KEY, $this->sign('GET', '', $now - 299), 'GET', self::ROUTE, '', $now));
    }

    public function test_missing_key_or_malformed_headers_fail(): void
    {
        $this->assertFalse(SignedRequest::verify('', $this->sign('GET', ''), 'GET', self::ROUTE, ''));
        foreach ([null, '', 'garbage', 't=1,n=2,s=3', 't=' . time() . ',n=' . str_repeat('a', 32)] as $header) {
            $this->assertFalse(SignedRequest::verify(self::KEY, $header, 'GET', self::ROUTE, ''));
        }
    }

    public function test_a_short_environment_key_counts_as_none(): void
    {
        putenv(SignedRequest::KEY_ENV . '=short');
        $this->assertSame('', SignedRequest::key());
        putenv(SignedRequest::KEY_ENV . '=' . self::KEY);
        $this->assertSame(self::KEY, SignedRequest::key());
        putenv(SignedRequest::KEY_ENV);
    }

    public function test_the_query_is_signed_sorted_and_encoded(): void
    {
        $this->assertSame(self::ROUTE, SignedRequest::route(self::ROUTE, []));
        $this->assertSame(
            self::ROUTE . "?category=hero&search=big%20text%20%28dark%29%21",
            SignedRequest::route(self::ROUTE, ['search' => 'big text (dark)!', 'category' => 'hero', 'nested' => ['x']])
        );
    }

    private function sign(string $method, string $body, ?int $time = null): string
    {
        $time = (string) ($time ?? time());
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', SignedRequest::payload($time, $nonce, $method, self::ROUTE, $body), self::KEY);

        return "t=$time,n=$nonce,s=$signature";
    }

    public function test_a_request_the_store_signs_itself_checks_out_the_same_way(): void
    {
        $header = SignedRequest::sign(self::KEY, 'POST', '/oauth/site/store-handoff', '{"site":"bakery.example"}');

        $this->assertMatchesRegularExpression('/^t=\d+,n=[0-9a-f]{32},s=[0-9a-f]{64}$/', $header);
        $this->assertTrue(SignedRequest::verify(self::KEY, $header, 'POST', '/oauth/site/store-handoff', '{"site":"bakery.example"}'));
        $this->assertFalse(SignedRequest::verify(self::KEY, SignedRequest::sign(self::KEY, 'POST', '/oauth/site/store-handoff', '{}'), 'POST', '/oauth/site/store-handoff', '{"site":"other"}'));
    }
}

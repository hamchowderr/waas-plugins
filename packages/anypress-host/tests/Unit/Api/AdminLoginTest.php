<?php

namespace Tests\Unit\Api;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use AnyPressHost\Api\AdminLogin;

class AdminLoginTest extends TestCase
{
    private const KEY = 'k3y-k3y-k3y-k3y-k3y-k3y-k3y-k3y-k3y';
    private const TIME = '1700000000';
    private const NONCE = '0123456789abcdef0123456789abcdef';
    /** Made by the API (the AnyPress API, src/sitekeys.ts adminLoginLink) for the key, time and nonce above. */
    private const SIGNATURE = '650d365b04699cb21cc734e115f86f9430f0abd19bc85a1c36475e7417584248';

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

    public function test_the_api_link_works_once(): void
    {
        $now = (int) self::TIME + 10;

        $this->assertTrue(AdminLogin::link_is_valid(self::KEY, self::TIME, self::NONCE, self::SIGNATURE, $now));
        $this->assertFalse(AdminLogin::link_is_valid(self::KEY, self::TIME, self::NONCE, self::SIGNATURE, $now), 'used twice');
    }

    public function test_an_old_link_fails(): void
    {
        $this->assertFalse(AdminLogin::link_is_valid(self::KEY, self::TIME, self::NONCE, self::SIGNATURE, (int) self::TIME + 301));
    }

    /**
     * @dataProvider tampered
     */
    public function test_a_changed_or_missing_part_fails(string $key, $time, $nonce, $signature): void
    {
        $this->assertFalse(AdminLogin::link_is_valid($key, $time, $nonce, $signature, (int) self::TIME));
    }

    public function tampered(): array
    {
        return [
            'another store\'s key' => ['an0ther-an0ther-an0ther-an0ther-an0ther', self::TIME, self::NONCE, self::SIGNATURE],
            'no site key' => ['', self::TIME, self::NONCE, self::SIGNATURE],
            'time' => [self::KEY, '1700000001', self::NONCE, self::SIGNATURE],
            'nonce' => [self::KEY, self::TIME, str_repeat('f', 32), self::SIGNATURE],
            'signature' => [self::KEY, self::TIME, self::NONCE, str_repeat('0', 64)],
            'missing' => [self::KEY, null, self::NONCE, self::SIGNATURE],
            'array' => [self::KEY, self::TIME, [self::NONCE], self::SIGNATURE],
        ];
    }

    public function test_a_signed_site_request_is_not_a_login(): void
    {
        // The same key, time and nonce signed as a GET to the same route (a site request) must not log in.
        $payload = implode("\n", [self::TIME, self::NONCE, 'GET', '/anypress-host/v1/admin-login', hash('sha256', '')]);
        $signature = hash_hmac('sha256', $payload, self::KEY);

        $this->assertFalse(AdminLogin::link_is_valid(self::KEY, self::TIME, self::NONCE, $signature, (int) self::TIME));
    }
}

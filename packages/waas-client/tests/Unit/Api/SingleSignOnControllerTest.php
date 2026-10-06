<?php

namespace Tests\Unit\Api;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSClient\Api\SingleSignOnController;
use WaaSClient\Core\DecryptionService;

class SingleSignOnControllerTest extends TestCase
{
    private const HOST = 'shop.example.com';

    private static array $keys;
    private array $transients = [];
    private SingleSignOnController $controller;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists('WP_Error')) {
            eval('class WP_Error { public $code; public $message; public $data; public function __construct($code = "", $message = "", $data = "") { $this->code = $code; $this->message = $message; $this->data = $data; } public function get_error_code() { return $this->code; } }');
        }
        if (!class_exists('WP_REST_Request')) {
            eval('class WP_REST_Request { private $params; public function __construct(array $params = []) { $this->params = $params; } public function get_param($key) { return $this->params[$key] ?? null; } }');
        }

        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $private_key);
        self::$keys = ['private' => $private_key, 'public' => openssl_pkey_get_details($resource)['key']];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['HTTP_HOST'] = 'Shop.Example.com:443';
        $this->transients = [];
        $this->controller = new SingleSignOnController(new DecryptionService());

        Functions\when('get_option')->alias(fn ($name) => $name === SingleSignOnController::TENANT_PUBLIC_KEY ? self::$keys['public'] : false);
        Functions\when('get_transient')->alias(fn ($key) => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function ($key, $value, $ttl) {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('get_user_by')->alias(fn ($field, $value) => $value === 'owner' ? (object) ['ID' => 5, 'user_login' => 'owner'] : false);
        Functions\when('admin_url')->justReturn('https://shop.example.com/wp-admin/');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST']);
        parent::tearDown();
    }

    public function test_valid_token_logs_the_owner_in(): void
    {
        $this->expectLogin();

        $this->expectExceptionMessage('redirected');
        $this->controller->verify_single_login($this->request($this->claims()));
    }

    public function test_a_token_works_only_once(): void
    {
        $token = $this->request($this->claims());
        $this->expectLogin();

        try {
            $this->controller->verify_single_login($token);
            $this->fail('first use should log in');
        } catch (\RuntimeException $redirected) {
            $this->assertSame('redirected', $redirected->getMessage());
        }

        $this->assertRejected($this->controller->verify_single_login($token));
    }

    public function test_used_token_ids_are_remembered_until_expiry(): void
    {
        $this->expectLogin();
        $claims = $this->claims();

        try {
            $this->controller->verify_single_login($this->request($claims));
        } catch (\RuntimeException $redirected) {
        }

        $this->assertArrayHasKey(SingleSignOnController::USED_TOKEN_TRANSIENT_PREFIX . $claims['jti'], $this->transients);
    }

    /**
     * @dataProvider invalid_claims
     */
    public function test_invalid_claims_are_rejected(array $overrides): void
    {
        Functions\expect('wp_set_auth_cookie')->never();

        $this->assertRejected($this->controller->verify_single_login($this->request($this->claims($overrides))));
    }

    public function invalid_claims(): array
    {
        $now = time();

        return [
            'expired' => [['iat' => $now - 600, 'exp' => $now - 1]],
            'lifetime over 15 minutes (old 48h email token)' => [['iat' => $now, 'exp' => $now + 172800]],
            'issued in the future' => [['iat' => $now + 600, 'exp' => $now + 900]],
            'other store' => [['aud' => 'other.example.com']],
            'wrong purpose' => [['purpose' => 'reset']],
            'malformed jti' => [['jti' => 'abc']],
            'string timestamps' => [['iat' => (string) $now, 'exp' => (string) ($now + 300)]],
            'no jti (pre-hardening storefront)' => [['jti' => null]],
            'no aud' => [['aud' => null]],
            'no iat' => [['iat' => null]],
        ];
    }

    public function test_token_signed_with_another_key_is_rejected(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $other_private);
        openssl_private_encrypt(json_encode($this->claims()), $signed, $other_private);

        $this->assertRejected($this->controller->verify_single_login(new \WP_REST_Request(['token' => base64_encode($signed)])));
    }

    public function test_missing_or_garbage_tokens_are_rejected(): void
    {
        $this->assertRejected($this->controller->verify_single_login(new \WP_REST_Request([])));
        $this->assertRejected($this->controller->verify_single_login(new \WP_REST_Request(['token' => ['array']])));
        $this->assertRejected($this->controller->verify_single_login(new \WP_REST_Request(['token' => '!!not base64!!'])));
    }

    public function test_unknown_user_is_rejected(): void
    {
        $this->assertRejected($this->controller->verify_single_login($this->request($this->claims(['username' => 'nobody']))));
    }

    public function test_token_whose_plus_signs_became_spaces_still_works(): void
    {
        $this->expectLogin();
        // Retry until the base64 form contains a '+', to exercise the decoding fix.
        do {
            $claims = $this->claims();
            openssl_private_encrypt(json_encode($claims), $signed, self::$keys['private']);
            $encoded = base64_encode($signed);
        } while (strpos($encoded, '+') === false);

        $this->expectExceptionMessage('redirected');
        $this->controller->verify_single_login(new \WP_REST_Request(['token' => str_replace('+', ' ', $encoded)]));
    }

    public function test_route_is_explicitly_public(): void
    {
        Functions\expect('register_rest_route')
            ->once()
            ->with('waas-client/v1', '/single_login/verify', Mockery::on(fn ($args) => $args['permission_callback'] === '__return_true'));

        $this->controller->register_rest_routes();
    }

    private function claims(array $overrides = []): array
    {
        $now = time();
        $claims = array_merge([
            'username' => 'owner',
            'purpose' => 'login',
            'jti' => bin2hex(random_bytes(16)),
            'aud' => self::HOST,
            'iat' => $now,
            'exp' => $now + 300,
        ], $overrides);
        $claims['expires'] = $claims['exp'];

        return array_filter($claims, fn ($value) => $value !== null);
    }

    private function request(array $claims): \WP_REST_Request
    {
        openssl_private_encrypt(json_encode($claims), $signed, self::$keys['private']);

        return new \WP_REST_Request(['token' => base64_encode($signed)]);
    }

    private function expectLogin(): void
    {
        Functions\when('nocache_headers')->justReturn(null);
        Functions\when('wp_clear_auth_cookie')->justReturn(null);
        Functions\when('wp_set_current_user')->justReturn(null);
        Functions\expect('wp_set_auth_cookie')->with(5);
        Functions\when('wp_safe_redirect')->alias(function () {
            throw new \RuntimeException('redirected');
        });
    }

    private function assertRejected($result): void
    {
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('waas_single_login_failed', $result->get_error_code());
        $this->assertSame(['status' => 403], $result->data);
    }
}

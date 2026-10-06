<?php

namespace Tests\Unit\Api;

use Brain\Monkey\Functions;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Api\SingleLogin;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\EncryptionService;
use WaaSHost\Core\PrivateKeyStore;
use WaaSHost\Core\WPCSTenant;

class SingleLoginTest extends TestCase
{
    private static array $keys;
    private FakeCommerceAdapter $cart;

    public static function setUpBeforeClass(): void
    {
        // One key pair for the whole class: production uses 4096-bit keys, which are slow to generate. 2048 bits still
        // fits the token payload (up to 245 bytes with PKCS#1 padding).
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $private_key);
        self::$keys = ['private' => $private_key, 'public' => openssl_pkey_get_details($resource)['key']];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_salt')->justReturn('auth-salt');
        Functions\when('sanitize_title_with_dashes')->alias(fn ($value) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $value)), '-'));
        Functions\when('remove_accents')->returnArg();
        Commerce::reset();
        Commerce::register($this->cart = new FakeCommerceAdapter());
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    public function test_login_data_has_single_use_id_audience_and_issue_time(): void
    {
        $data = SingleLogin::build_login_data('owner', 'Shop.Example.com', 300);

        $this->assertSame('owner', $data['username']);
        $this->assertSame('login', $data['purpose']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $data['jti']);
        $this->assertSame('shop.example.com', $data['aud']);
        $this->assertEqualsWithDelta(time(), $data['iat'], 2);
        $this->assertSame($data['iat'] + 300, $data['exp']);
        $this->assertSame($data['exp'], $data['expires'], 'expires is kept for store plugins that only read it');
    }

    public function test_every_token_gets_a_different_id(): void
    {
        $this->assertNotSame(
            SingleLogin::build_login_data('owner', 'shop.example.com', 300)['jti'],
            SingleLogin::build_login_data('owner', 'shop.example.com', 300)['jti']
        );
    }

    public function test_lifetime_is_capped_at_fifteen_minutes(): void
    {
        // The old "site ready" email asked for 48 hours.
        $data = SingleLogin::build_login_data('owner', 'shop.example.com', 172800);

        $this->assertSame(SingleLogin::MAX_TOKEN_LIFETIME, $data['exp'] - $data['iat']);
        $this->assertSame(900, SingleLogin::MAX_TOKEN_LIFETIME);
    }

    public function test_login_link_carries_a_token_the_store_can_verify(): void
    {
        $this->cart->meta[42][WPCSTenant::WPCS_DOMAIN_NAME_META] = 'shop.example.com';
        PrivateKeyStore::save(42, self::$keys['private']);

        $link = SingleLogin::get_login_link(42, SingleLogin::PORTAL_TOKEN_LIFETIME);

        $prefix = 'https://shop.example.com/wp-json/waas-client/v1/single_login/verify?token=';
        $this->assertStringStartsWith($prefix, $link);

        $token = base64_decode(urldecode(substr($link, strlen($prefix))));
        $this->assertTrue(openssl_public_decrypt($token, $json, self::$keys['public']));
        $data = json_decode($json, true);
        $this->assertSame('jordan_reyes', $data['username']);
        $this->assertSame('shop.example.com', $data['aud']);
        $this->assertSame(SingleLogin::PORTAL_TOKEN_LIFETIME, $data['exp'] - $data['iat']);
    }

    public function test_login_link_falls_back_to_the_base_domain(): void
    {
        $this->cart->meta[42][WPCSTenant::WPCS_BASE_DOMAIN_NAME_META] = 'tenant-1.platform.test';
        PrivateKeyStore::save(42, self::$keys['private']);

        $this->assertStringStartsWith('https://tenant-1.platform.test/', SingleLogin::get_login_link(42, 300));
    }

    public function test_no_link_without_a_readable_private_key(): void
    {
        $this->cart->meta[42][WPCSTenant::WPCS_DOMAIN_NAME_META] = 'shop.example.com';

        $this->assertSame('', SingleLogin::get_login_link(42, 300));

        PrivateKeyStore::save(42, self::$keys['private']);
        Functions\when('wp_salt')->justReturn('rotated-salt');
        $this->assertSame('', SingleLogin::get_login_link(42, 300));
    }

    public function test_store_plugin_decrypts_tokens_signed_by_encryption_service(): void
    {
        // Guards the format the store plugin relies on: openssl_private_encrypt, read with the public key.
        $signed = EncryptionService::encrypt(self::$keys['private'], '{"purpose":"login"}');

        $this->assertTrue(openssl_public_decrypt($signed, $plain, self::$keys['public']));
        $this->assertSame('{"purpose":"login"}', $plain);
    }
}

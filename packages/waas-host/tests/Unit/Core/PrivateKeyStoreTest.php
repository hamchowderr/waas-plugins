<?php

namespace Tests\Unit\Core;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSHost\Core\PrivateKeyStore;
use WaaSHost\Core\WPCSTenant;

class PrivateKeyStoreTest extends TestCase
{
    private const PEM = "-----BEGIN PRIVATE KEY-----\nMIIBVQIBADANBgkqhkiG9w0BAQEFAASCAT8wggE7AgEAAkEA\n-----END PRIVATE KEY-----\n";

    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->meta = [];
        Functions\when('wp_salt')->justReturn('auth-salt-from-wp-config');
        Functions\when('get_post_meta')->alias(fn ($id, $key) => $this->meta[$id][$key] ?? '');
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->meta[$id][$key] = $value;
            return true;
        });
    }

    public function test_saved_keys_are_encrypted_at_rest_and_load_back(): void
    {
        PrivateKeyStore::save(42, self::PEM);

        $stored = $this->meta[42][WPCSTenant::WPCS_TENANT_PRIVATE_KEY_META];
        $this->assertStringStartsWith(PrivateKeyStore::PREFIX, $stored);
        $this->assertStringNotContainsString('PRIVATE KEY', $stored);
        $this->assertSame(self::PEM, PrivateKeyStore::load(42));
    }

    public function test_each_save_uses_a_fresh_nonce(): void
    {
        $this->assertNotSame(PrivateKeyStore::seal(self::PEM), PrivateKeyStore::seal(self::PEM));
    }

    public function test_legacy_plaintext_key_is_returned_and_migrated(): void
    {
        $this->meta[42][WPCSTenant::WPCS_TENANT_PRIVATE_KEY_META] = self::PEM;

        $this->assertSame(self::PEM, PrivateKeyStore::load(42));
        $this->assertTrue(PrivateKeyStore::is_sealed($this->meta[42][WPCSTenant::WPCS_TENANT_PRIVATE_KEY_META]));
        $this->assertSame(self::PEM, PrivateKeyStore::load(42));
    }

    public function test_missing_key_loads_as_empty(): void
    {
        $this->assertSame('', PrivateKeyStore::load(99));
    }

    public function test_a_different_secret_cannot_decrypt(): void
    {
        $sealed = PrivateKeyStore::seal(self::PEM);
        Functions\when('wp_salt')->justReturn('rotated-salt');

        $this->assertSame('', PrivateKeyStore::open($sealed));
    }

    public function test_tampered_or_truncated_values_decrypt_to_empty(): void
    {
        $sealed = PrivateKeyStore::seal(self::PEM);
        $tampered = substr($sealed, 0, -4) . (substr($sealed, -4) === 'AAAA' ? 'BBBB' : 'AAAA');

        $this->assertSame('', PrivateKeyStore::open($tampered));
        $this->assertSame('', PrivateKeyStore::open(PrivateKeyStore::PREFIX . 'not base64!'));
        $this->assertSame('', PrivateKeyStore::open(PrivateKeyStore::PREFIX . base64_encode('short')));
    }
}

<?php

namespace Tests\Unit\Core;

use Brain\Monkey\Functions;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use AnyPressHost\Commerce\Commerce;
use AnyPressHost\Core\PrivateKeyStore;
use AnyPressHost\Core\SecretBox;
use AnyPressHost\Core\Tenant;

class PrivateKeyStoreTest extends TestCase
{
    private const PEM = "-----BEGIN PRIVATE KEY-----\nMIIBVQIBADANBgkqhkiG9w0BAQEFAASCAT8wggE7AgEAAkEA\n-----END PRIVATE KEY-----\n";

    private FakeCommerceAdapter $cart;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_salt')->justReturn('auth-salt-from-wp-config');
        Commerce::reset();
        Commerce::register($this->cart = new FakeCommerceAdapter());
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    public function test_saved_keys_are_encrypted_in_the_carts_subscription_meta(): void
    {
        PrivateKeyStore::save(42, self::PEM);

        $stored = $this->cart->meta[42][Tenant::TENANT_PRIVATE_KEY_META];
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
        $this->cart->meta[42][Tenant::TENANT_PRIVATE_KEY_META] = self::PEM;

        $this->assertSame(self::PEM, PrivateKeyStore::load(42));
        $this->assertTrue(PrivateKeyStore::is_sealed($this->cart->meta[42][Tenant::TENANT_PRIVATE_KEY_META]));
        $this->assertSame(self::PEM, PrivateKeyStore::load(42));
    }

    public function test_the_new_meta_name_is_hidden(): void
    {
        $this->assertStringStartsWith('_', Tenant::TENANT_PRIVATE_KEY_META);
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

    /** SecretBox is shared with the webhook secret; keys sealed before that still open, and each purpose has its own key. */
    public function test_keys_sealed_before_secretbox_was_shared_still_open(): void
    {
        $key = sodium_crypto_generichash('anypress-host-private-key-encryption|auth-salt-from-wp-config', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = PrivateKeyStore::PREFIX . base64_encode($nonce . sodium_crypto_secretbox(self::PEM, $nonce, $key));

        $this->assertSame(self::PEM, PrivateKeyStore::open($sealed));
        $this->assertSame('', SecretBox::open($sealed, 'anypress-host-webhook-secret'));
    }
}

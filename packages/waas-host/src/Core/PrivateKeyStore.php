<?php

namespace WaaSHost\Core;

/**
 * Stores each tenant's one-click-login private key encrypted at rest (libsodium secretbox).
 *
 * The encryption key comes from wp-config: the WAAS_HOST_KEY_ENCRYPTION_KEY constant if defined, otherwise
 * WordPress's auth salt. Either way a database dump alone no longer yields working login keys. Rotating that
 * secret makes the stored keys unreadable, so define the constant and keep it stable.
 *
 * Keys saved before encryption (plain PEM) are still read, and re-saved encrypted on first read.
 */
class PrivateKeyStore
{
    public const PREFIX = 'waasenc:v1:';
    public const KEY_CONSTANT = 'WAAS_HOST_KEY_ENCRYPTION_KEY';

    public static function save($subscription_id, string $private_key): void
    {
        update_post_meta($subscription_id, WPCSTenant::WPCS_TENANT_PRIVATE_KEY_META, self::seal($private_key));
    }

    /**
     * Returns the plaintext private key, or '' if there is none or it can't be decrypted.
     */
    public static function load($subscription_id): string
    {
        $stored = (string) get_post_meta($subscription_id, WPCSTenant::WPCS_TENANT_PRIVATE_KEY_META, true);
        if ($stored === '') {
            return '';
        }

        if (!self::is_sealed($stored)) {
            // Saved before encryption: migrate it now.
            self::save($subscription_id, $stored);
            return $stored;
        }

        return self::open($stored);
    }

    public static function is_sealed(string $stored): bool
    {
        return strpos($stored, self::PREFIX) === 0;
    }

    public static function seal(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, self::encryption_key());

        return self::PREFIX . base64_encode($nonce . $ciphertext);
    }

    public static function open(string $stored): string
    {
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, self::encryption_key());

        return $plaintext === false ? '' : $plaintext;
    }

    private static function encryption_key(): string
    {
        $secret = defined(self::KEY_CONSTANT) ? (string) constant(self::KEY_CONSTANT) : wp_salt('auth');

        return sodium_crypto_generichash('waas-host-private-key-encryption|' . $secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}

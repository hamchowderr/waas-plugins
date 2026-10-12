<?php

namespace WaaSHost\Core;

use WaaSHost\Commerce\Commerce;

/**
 * Stores each tenant's one-click-login private key encrypted at rest (libsodium secretbox), in the active cart's
 * subscription meta.
 *
 * The encryption key comes from wp-config: the WAAS_HOST_KEY_ENCRYPTION_KEY constant if defined, otherwise
 * WordPress's auth salt. Either way a database dump alone no longer yields working login keys. Rotating that
 * secret makes the stored keys unreadable, so define the constant and keep it stable.
 *
 * Keys saved before encryption (plain PEM) are still read, and re-saved encrypted on first read. So are keys under
 * the old meta name (Tenant::LEGACY_TENANT_PRIVATE_KEY_META): they move to Tenant::TENANT_PRIVATE_KEY_META and the
 * old value is cleared.
 */
class PrivateKeyStore
{
    public const PREFIX = 'waasenc:v1:';
    public const KEY_CONSTANT = 'WAAS_HOST_KEY_ENCRYPTION_KEY';

    public static function save($subscription_id, string $private_key): void
    {
        Commerce::require_adapter()->update_subscription_meta($subscription_id, Tenant::TENANT_PRIVATE_KEY_META, self::seal($private_key));
    }

    /**
     * Returns the plaintext private key, or '' if there is none or it can't be decrypted.
     */
    public static function load($subscription_id): string
    {
        $commerce = Commerce::require_adapter();
        $stored = (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_PRIVATE_KEY_META);
        if ($stored === '') {
            $legacy = (string) $commerce->get_subscription_meta($subscription_id, Tenant::LEGACY_TENANT_PRIVATE_KEY_META);
            if ($legacy === '') {
                return '';
            }

            $plaintext = self::is_sealed($legacy) ? self::open($legacy) : $legacy;
            if ($plaintext !== '') {
                self::save($subscription_id, $plaintext);
                $commerce->update_subscription_meta($subscription_id, Tenant::LEGACY_TENANT_PRIVATE_KEY_META, '');
            }
            return $plaintext;
        }

        if (!self::is_sealed($stored)) {
            // Saved before encryption: migrate it now.
            self::save($subscription_id, $stored);
            return $stored;
        }

        return self::open($stored);
    }

    /**
     * Moves every key still under the old meta name. Returns how many subscriptions were looked at.
     */
    public static function migrate_legacy_keys(): int
    {
        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return 0;
        }

        $legacy = $commerce->find_subscriptions_by_meta(Tenant::LEGACY_TENANT_PRIVATE_KEY_META, ['']);
        foreach (array_keys($legacy) as $subscription_id) {
            self::load($subscription_id);
        }
        return count($legacy);
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

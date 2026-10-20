<?php

namespace WaaSHost\Core;

use WaaSHost\Commerce\Commerce;

/**
 * Stores each tenant's one-click-login private key encrypted at rest (SecretBox), in the active cart's
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
    public const PREFIX = SecretBox::PREFIX;
    public const KEY_CONSTANT = SecretBox::KEY_CONSTANT;
    /** Keys are derived per purpose; this one is what the keys were sealed with before SecretBox was shared. */
    private const PURPOSE = 'waas-host-private-key-encryption';

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
        return SecretBox::is_sealed($stored);
    }

    public static function seal(string $plaintext): string
    {
        return SecretBox::seal($plaintext, self::PURPOSE);
    }

    public static function open(string $stored): string
    {
        return SecretBox::open($stored, self::PURPOSE);
    }
}

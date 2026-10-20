<?php

namespace WaaSHost\Core;

/**
 * Seals a secret for storage (libsodium secretbox), so a database dump alone doesn't give it away.
 *
 * The key comes from wp-config: the WAAS_HOST_KEY_ENCRYPTION_KEY constant if defined, otherwise WordPress's auth
 * salt. Each kind of secret ($purpose) gets its own key derived from it. Rotating that secret makes everything sealed
 * with it unreadable, so define the constant and keep it stable.
 */
class SecretBox
{
    public const PREFIX = 'waasenc:v1:';
    public const KEY_CONSTANT = 'WAAS_HOST_KEY_ENCRYPTION_KEY';

    public static function is_sealed(string $stored): bool
    {
        return strpos($stored, self::PREFIX) === 0;
    }

    public static function seal(string $plaintext, string $purpose): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, self::key($purpose));

        return self::PREFIX . base64_encode($nonce . $ciphertext);
    }

    /** The plaintext, or '' when $stored can't be opened (damaged, or sealed under another key). */
    public static function open(string $stored, string $purpose): string
    {
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, self::key($purpose));

        return $plaintext === false ? '' : $plaintext;
    }

    private static function key(string $purpose): string
    {
        $secret = defined(self::KEY_CONSTANT) ? (string) constant(self::KEY_CONSTANT) : wp_salt('auth');

        return sodium_crypto_generichash($purpose . '|' . $secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}

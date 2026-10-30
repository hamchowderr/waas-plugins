<?php

namespace WaaSHost\Core;

class EncryptionService
{
    /** @throws \RuntimeException when OpenSSL can't make the key (the store job then retries) */
    public static function generate_key_pair(): array
    {
        $key_resource = openssl_pkey_new([
            "digest_alg" => 'sha512',
            "private_key_bits" => 4096,
            "private_key_type" => OPENSSL_KEYTYPE_RSA
        ]);
        $details = $key_resource === false ? false : openssl_pkey_get_details($key_resource);
        if ($details === false || !openssl_pkey_export($key_resource, $private_key)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not printed here; every place that shows the message escapes it.
            throw new \RuntimeException('Could not generate the store login key pair: ' . (string) openssl_error_string());
        }
        $public_key = $details['key'];

        return [
            'public_key' => $public_key,
            'private_key' => $private_key
        ];
    }

    public static function encrypt($private_key, $data)
    {
        openssl_private_encrypt($data, $encrypted, $private_key);

        return $encrypted;
    }
}

<?php

namespace WaaSHost\Api;

use InvalidArgumentException;

/**
 * The front ends allowed to sign customers in through the storefront (see AccountSignIn): each has an id, a secret
 * kept only as a hash, and the exact redirect URIs it may send customers back to. Kept in a non-autoloaded option and
 * managed with `wp waas-host account-client`.
 */
class AccountClients
{
    public const OPTION = 'waas_host_account_clients';

    /** @return array<string, array{name: string, secret_hash: string, redirect_uris: string[], created: int}> */
    public static function all(): array
    {
        $clients = get_option(self::OPTION, []);

        return is_array($clients) ? $clients : [];
    }

    public static function find(string $client_id): ?array
    {
        $client = self::all()[$client_id] ?? null;

        return is_array($client) ? ['id' => $client_id] + $client : null;
    }

    /**
     * Registers a client. The secret is returned once and only its hash is kept.
     *
     * @param string[] $redirect_uris
     * @return array{client_id: string, client_secret: string}
     * @throws InvalidArgumentException when a redirect URI isn't allowed or there is none
     */
    public static function create(string $name, array $redirect_uris): array
    {
        $redirect_uris = array_values(array_unique(array_filter(array_map('trim', $redirect_uris))));
        if (!$redirect_uris) {
            throw new InvalidArgumentException('A client needs at least one redirect URI.');
        }
        foreach ($redirect_uris as $uri) {
            if (!self::is_valid_redirect_uri($uri)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not printed here; every place that shows the message escapes it.
                throw new InvalidArgumentException("Not an allowed redirect URI: $uri (use https, or http only for localhost, and no #fragment).");
            }
        }

        $client_id = bin2hex(random_bytes(12));
        $secret = AccountTokens::base64url(random_bytes(32));
        $clients = self::all();
        $clients[$client_id] = [
            'name' => trim($name),
            'secret_hash' => self::hash_secret($secret),
            'redirect_uris' => $redirect_uris,
            'created' => time(),
        ];
        update_option(self::OPTION, $clients, false);

        return ['client_id' => $client_id, 'client_secret' => $secret];
    }

    public static function remove(string $client_id): bool
    {
        $clients = self::all();
        if (!isset($clients[$client_id])) {
            return false;
        }
        unset($clients[$client_id]);
        update_option(self::OPTION, $clients, false);

        return true;
    }

    /** The client, when the id and secret match a registered one; else null. */
    public static function authenticate(string $client_id, string $secret): ?array
    {
        $client = $client_id === '' || $secret === '' ? null : self::find($client_id);
        if ($client === null || !hash_equals((string) ($client['secret_hash'] ?? ''), self::hash_secret($secret))) {
            return null;
        }

        return $client;
    }

    /** Exact match only: no prefixes, no wildcards. */
    public static function allows_redirect_uri(array $client, string $uri): bool
    {
        return in_array($uri, (array) ($client['redirect_uris'] ?? []), true);
    }

    /** https with a host and no fragment; http only for localhost, for development. */
    public static function is_valid_redirect_uri(string $uri): bool
    {
        $parts = wp_parse_url($uri);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['fragment']) || isset($parts['user'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');

        return $scheme === 'https' || ($scheme === 'http' && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true));
    }

    /** Secrets are 256 random bits, so a plain SHA-256 is enough (no password stretching needed). */
    private static function hash_secret(string $secret): string
    {
        return hash('sha256', $secret);
    }
}

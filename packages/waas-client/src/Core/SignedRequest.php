<?php

namespace WaaSClient\Core;

/**
 * Checks requests the AnyPress API signs with the store's site key (the WAAS_SITE_KEY environment variable,
 * which the API sets on the store's container). The X-Waas-Signature header is "t=<unix time>,n=<32 hex
 * nonce>,s=<hex HMAC-SHA256>" over "<t>\n<n>\n<METHOD>\n<route>\n<hex SHA-256 of the body>", where route is the
 * REST route plus, when there is one, "?" and the query sorted by name with names and values rawurlencode()d.
 * A signature is good for MAX_AGE seconds either way, and each nonce only once.
 */
class SignedRequest
{
    public const HEADER = 'x-waas-signature';
    public const KEY_ENV = 'WAAS_SITE_KEY';
    public const MAX_AGE = 300;
    public const USED_NONCE_TRANSIENT_PREFIX = 'waas_site_nonce_';

    /** The store's site key, or '' when the API hasn't given it one. */
    public static function key(): string
    {
        $key = getenv(self::KEY_ENV);

        return is_string($key) && strlen($key) >= 32 ? $key : '';
    }

    /** The route as signed: the REST route and its query, sorted by name ("/x?a=1&b=two%20words"). */
    public static function route(string $route, array $query): string
    {
        ksort($query, SORT_STRING);
        $pairs = [];
        foreach ($query as $name => $value) {
            if (is_scalar($value)) {
                $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
            }
        }

        return $pairs ? $route . '?' . implode('&', $pairs) : $route;
    }

    public static function payload(string $time, string $nonce, string $method, string $route, string $body): string
    {
        return implode("\n", [$time, $nonce, strtoupper($method), $route, hash('sha256', $body)]);
    }

    /**
     * The header for a request this store signs itself (to the API), in the same form: "t=…,n=…,s=…".
     */
    public static function sign(string $key, string $method, string $route, string $body, ?int $now = null): string
    {
        $time = (string) ($now ?? time());
        $nonce = bin2hex(random_bytes(16));

        return 't=' . $time . ',n=' . $nonce . ',s=' . hash_hmac('sha256', self::payload($time, $nonce, $method, $route, $body), $key);
    }

    /** True when the header is a current, unused signature of this request by $key. Marks the nonce used. */
    public static function verify(string $key, ?string $header, string $method, string $route, string $body, ?int $now = null): bool
    {
        if ($key === '' || !is_string($header) || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$name] = $value;
        }
        $time = $parts['t'] ?? '';
        $nonce = $parts['n'] ?? '';
        $signature = $parts['s'] ?? '';
        if (!preg_match('/^\d{1,12}$/', $time) || !preg_match('/^[0-9a-f]{32}$/', $nonce) || !preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return false;
        }

        $now = $now ?? time();
        if (abs($now - (int) $time) > self::MAX_AGE) {
            return false;
        }

        $expected = hash_hmac('sha256', self::payload($time, $nonce, $method, $route, $body), $key);
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        $used = self::USED_NONCE_TRANSIENT_PREFIX . $nonce;
        if (get_transient($used)) {
            return false;
        }
        set_transient($used, 1, 2 * self::MAX_AGE);

        return true;
    }
}

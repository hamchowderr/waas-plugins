<?php

namespace WaaSHost\Api;

use WaaSHost\Features\PluginBootstrap;
use WP_REST_Request;

/**
 * "Log in to WordPress" from the AnyPress dashboard, for a ready-made storefront: the API signs a link to this route
 * with the storefront's site key (WAAS_SITE_KEY, which the API gives every site it builds), and opening it signs the
 * browser in as the site's first administrator and goes to wp-admin.
 *
 * The link carries t (Unix time), n (a random nonce) and s, an HMAC-SHA256 with the site key of
 * "t\nn\nLOGIN\n/waas-host/v1/admin-login\n" followed by the SHA-256 of an empty body: the same form as WaaS Client's
 * signed site requests. It works once and for MAX_AGE seconds. Sites without a site key (storefronts not built by
 * AnyPress) refuse every link.
 */
class AdminLogin
{
    public const ROUTE = '/admin-login';
    public const METHOD = 'LOGIN';
    public const KEY_ENV = 'WAAS_SITE_KEY';
    public const MAX_AGE = 300;
    public const USED_NONCE_TRANSIENT_PREFIX = 'waas_admin_login_';

    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    public static function register_rest_routes(): void
    {
        register_rest_route(PluginBootstrap::API_V1_NAMESPACE, self::ROUTE, [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'login'],
            // Public on purpose: the signed link is the credential.
            'permission_callback' => '__return_true',
        ]);
    }

    /** The site key, or '' when the API hasn't given this site one. */
    public static function key(): string
    {
        $key = getenv(self::KEY_ENV);

        return is_string($key) && strlen($key) >= 32 ? $key : '';
    }

    /** What the API signs for a link made at $time with $nonce. */
    public static function payload(string $time, string $nonce): string
    {
        $route = '/' . PluginBootstrap::API_V1_NAMESPACE . self::ROUTE;

        return implode("\n", [$time, $nonce, self::METHOD, $route, hash('sha256', '')]);
    }

    /** True when t, n and s are a current, unused signature of a login by $key. Marks the nonce used. */
    public static function link_is_valid(string $key, $time, $nonce, $signature, ?int $now = null): bool
    {
        if ($key === '' || !is_string($time) || !is_string($nonce) || !is_string($signature)) {
            return false;
        }
        if (!preg_match('/^\d{1,12}$/', $time) || !preg_match('/^[0-9a-f]{32}$/', $nonce) || !preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $time) > self::MAX_AGE) {
            return false;
        }
        if (!hash_equals(hash_hmac('sha256', self::payload($time, $nonce), $key), $signature)) {
            return false;
        }

        $used = self::USED_NONCE_TRANSIENT_PREFIX . $nonce;
        if (get_transient($used)) {
            return false;
        }
        set_transient($used, 1, 2 * self::MAX_AGE);

        return true;
    }

    public static function login(WP_REST_Request $request)
    {
        nocache_headers();
        if (!self::link_is_valid(self::key(), $request->get_param('t'), $request->get_param('n'), $request->get_param('s'))) {
            self::refuse();
        }

        $admins = get_users(['role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1]);
        if (!$admins) {
            self::refuse();
        }
        $user = $admins[0];

        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
        do_action('wp_login', $user->user_login, $user);
        wp_safe_redirect(admin_url());
        exit();
    }

    /** One plain answer for every failure: don't tell a caller which check failed. */
    private static function refuse(): void
    {
        wp_die(
            esc_html__('This sign-in link has expired or was already used. Go back to the AnyPress dashboard and press "Log in to WordPress" again.', 'waas-host')
                . ' <a href="' . esc_url(wp_login_url()) . '">' . esc_html__('Or log in with your password.', 'waas-host') . '</a>',
            esc_html__('Sign-in link expired', 'waas-host'),
            ['response' => 403]
        );
    }
}

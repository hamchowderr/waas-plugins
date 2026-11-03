<?php

namespace WaaSClient\Api;

use WaaSClient\Core\SignedRequest;
use WaaSClient\Features\PluginBootstrap;
use WP_REST_Request;

/**
 * "Log in to WordPress" from the AnyPress dashboard: the API signs a link to this route with the store's site key,
 * and opening it signs the browser in as the store's first administrator and goes to wp-admin.
 *
 * The link carries the signature in its query (t, n, s): SignedRequest's form, over the method LOGIN (which no real
 * request uses, so a signed site request can't be turned into a login) and this route, with an empty body. It works
 * once (the nonce is remembered) and for SignedRequest::MAX_AGE seconds.
 */
class AdminLogin
{
    public const ROUTE = '/admin-login';
    public const METHOD = 'LOGIN';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public function register_rest_routes(): void
    {
        register_rest_route(PluginBootstrap::API_V1_NAMESPACE, self::ROUTE, [
            'methods' => 'GET',
            'callback' => [$this, 'login'],
            // Public on purpose: the signed link is the credential.
            'permission_callback' => '__return_true',
        ]);
    }

    /** The route as the API signs it. */
    public static function signed_route(): string
    {
        return '/' . PluginBootstrap::API_V1_NAMESPACE . self::ROUTE;
    }

    /** True when t, n and s are a current, unused signature of a login by $key. Marks the nonce used. */
    public static function link_is_valid(string $key, $time, $nonce, $signature, ?int $now = null): bool
    {
        if (!is_string($time) || !is_string($nonce) || !is_string($signature)) {
            return false;
        }
        $header = 't=' . $time . ',n=' . $nonce . ',s=' . $signature;

        return SignedRequest::verify($key, $header, self::METHOD, self::signed_route(), '', $now);
    }

    public function login(WP_REST_Request $request)
    {
        nocache_headers();
        if (!self::link_is_valid(SignedRequest::key(), $request->get_param('t'), $request->get_param('n'), $request->get_param('s'))) {
            $this->refuse();
        }

        $admins = get_users(['role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1]);
        if (!$admins) {
            $this->refuse();
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
    private function refuse(): void
    {
        wp_die(
            esc_html__('This sign-in link has expired or was already used. Go back to your dashboard and press "Log in to WordPress" again.', 'waas-client')
                . ' <a href="' . esc_url(wp_login_url()) . '">' . esc_html__('Or log in with your password.', 'waas-client') . '</a>',
            esc_html__('Sign-in link expired', 'waas-client'),
            ['response' => 403]
        );
    }
}

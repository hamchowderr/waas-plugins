<?php

namespace WaaSHost\Api;

use WaaSHost\Features\PluginBootstrap;
use WaaSHost\Core\EncryptionService;
use WaaSHost\Core\PrivateKeyStore;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\SingleLoginService;
use WP_REST_Request;

class SingleLogin
{
    /**
     * Tokens are minted on click and used right away, so they only need to live long enough for the redirect.
     */
    public const PORTAL_TOKEN_LIFETIME = 300;
    public const MAX_TOKEN_LIFETIME = 900;

    public static function init()
    {
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    public static function register_rest_routes()
    {
        register_rest_route(PluginBootstrap::API_V1_NAMESPACE, '/tenant/single_login', array(
            'methods' => 'GET',
            'callback' => [__CLASS__, 'handle_api_one_click_login'],
            'permission_callback' => [__CLASS__, 'guard_generate_single_login_link']
        ));
    }

    public static function guard_generate_single_login_link(WP_REST_Request $request)
    {
        $current_user = wp_get_current_user();
        if (!$current_user->exists()) {
            return false;
        }

        $current_user_id = $current_user->ID;
        $subscription_id = sanitize_text_field($request->get_param('subscription_id'));

        $order_customer_id = apply_filters('wpcs_get_customer_id_by_subscription_id_for_login_guard', '', $subscription_id);

        return $current_user_id === $order_customer_id;
    }

    /**
     * Returns the one-click login URL for the store, or '' if the store's private key is missing or unreadable.
     */
    public static function get_login_link($subscription_id, $expiry_delay_in_seconds)
    {
        $domain = get_post_meta($subscription_id, WPCSTenant::WPCS_DOMAIN_NAME_META, true);
        $base_domain = get_post_meta($subscription_id, WPCSTenant::WPCS_BASE_DOMAIN_NAME_META, true);

        $domain = $domain ?: $base_domain;

        $private_key = PrivateKeyStore::load($subscription_id);
        if ($private_key === '' || !$domain) {
            return '';
        }

        $login_data = self::build_login_data(SingleLoginService::get_formatted_username($subscription_id), $domain, (int) $expiry_delay_in_seconds);

        $token = EncryptionService::encrypt($private_key, json_encode($login_data));
        $token_encoded = urlencode(base64_encode($token));

        return 'https://' . $domain . "/wp-json/waas-client/v1/single_login/verify?token=" . $token_encoded;
    }

    /**
     * jti lets the store reject a token it has already used; aud ties the token to this store's host;
     * the lifetime is capped at MAX_TOKEN_LIFETIME. 'expires' is kept for store plugins that only read it.
     */
    public static function build_login_data(string $username, string $domain, int $lifetime_seconds): array
    {
        $issued_at = (int) gmdate('U');
        $expires = $issued_at + max(1, min($lifetime_seconds, self::MAX_TOKEN_LIFETIME));

        return [
            'username' => $username,
            'purpose' => 'login',
            'jti' => bin2hex(random_bytes(16)),
            'aud' => strtolower($domain),
            'iat' => $issued_at,
            'exp' => $expires,
            'expires' => $expires,
        ];
    }

    public static function handle_api_one_click_login(WP_REST_Request $request)
    {
        $subscription_id = $request->get_param('subscription_id');
        $login_link = self::get_login_link($subscription_id, self::PORTAL_TOKEN_LIFETIME);
        if ($login_link === '') {
            wp_die(esc_html__('One-click login is not available for this website. Please contact support.'), 500);
        }

        wp_redirect($login_link);
        exit();
    }
}

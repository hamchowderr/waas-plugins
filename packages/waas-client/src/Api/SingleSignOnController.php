<?php

namespace WaaSClient\Api;

use WaaSClient\Core\DecryptionService;
use WaaSClient\Features\PluginBootstrap;
use WP_Error;
use WP_REST_Request;

class SingleSignOnController
{
    public const TENANT_PUBLIC_KEY = 'TENANT_PUBLIC_KEY';
    public const PUBLIC_KEY_CONST_NAME = 'WAAS_PUBLIC_KEY';
    private DecryptionService $decryptionService;

    public function __construct(DecryptionService $decryptionService)
    {
        $this->decryptionService = $decryptionService;

        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Matches the storefront's cap on token lifetime, plus a little clock skew.
     */
    public const MAX_TOKEN_LIFETIME = 900;
    public const CLOCK_SKEW = 60;
    public const USED_TOKEN_TRANSIENT_PREFIX = 'waas_sso_used_';

    public function register_rest_routes()
    {
        register_rest_route(PluginBootstrap::API_V1_NAMESPACE, '/single_login/verify', array(
            'methods' => 'GET',
            'callback' => [$this, 'verify_single_login'],
            // Public on purpose: the signed token is the credential.
            'permission_callback' => '__return_true',
        ));
    }

    public function verify_single_login(WP_REST_Request $request)
    {
        $token = $request->get_param('token');
        if (!is_string($token) || $token === '') {
            return $this->reject();
        }
        // A '+' in base64 arrives as a space if the token wasn't URL-encoded.
        $token_decoded = base64_decode(strtr($token, ' ', '+'), true);
        if ($token_decoded === false) {
            return $this->reject();
        }

        $public_key = '';
        if (!PluginBootstrap::has_constant(static::PUBLIC_KEY_CONST_NAME)) {
            $public_key = get_option(static::TENANT_PUBLIC_KEY);

            if ($public_key === false || empty($public_key)) {
                return $this->reject();
            }
        } else {
            $public_key = base64_decode((string) PluginBootstrap::constant(static::PUBLIC_KEY_CONST_NAME));
        }

        $data = $this->decryptionService->decrypt($public_key, $token_decoded);

        if (!$data) {
            return $this->reject();
        }

        $data = json_decode($data);
        if (!$this->claims_are_valid($data, $this->request_host())) {
            return $this->reject();
        }

        // Single use: remember the token's ID until it would have expired anyway.
        $used_key = static::USED_TOKEN_TRANSIENT_PREFIX . $data->jti;
        if (get_transient($used_key)) {
            return $this->reject();
        }
        set_transient($used_key, 1, max(1, (int) $data->exp - (int) gmdate('U')) + static::CLOCK_SKEW);

        $user_username = $data->username;
        $user = get_user_by('login', $user_username);

        if (!$user) {
            return $this->reject();
        }

        nocache_headers();
        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
        do_action('wp_login', $user->user_login, $user);
        $redirect_to = apply_filters('waas_sso_admin_url', admin_url(), $user, $data);
        wp_safe_redirect($redirect_to);
        exit();
    }

    /**
     * Checks a decrypted token's claims: purpose, a single-use ID, an audience matching this site, and a lifetime
     * that is current and no longer than MAX_TOKEN_LIFETIME. Tokens from storefronts that predate jti/aud/iat fail.
     */
    public function claims_are_valid($data, string $host): bool
    {
        if (!is_object($data) || ($data->purpose ?? null) !== 'login' || !is_string($data->username ?? null)) {
            return false;
        }

        if (!is_string($data->jti ?? null) || !preg_match('/^[0-9a-f]{32}$/', $data->jti)) {
            return false;
        }

        if (!is_string($data->aud ?? null) || $host === '' || strtolower($data->aud) !== $host) {
            return false;
        }

        if (!is_int($data->iat ?? null) || !is_int($data->exp ?? null)) {
            return false;
        }

        $now = (int) gmdate('U');
        return $data->iat <= $now + static::CLOCK_SKEW
            && $data->exp >= $now
            && $data->exp - $data->iat <= static::MAX_TOKEN_LIFETIME;
    }

    private function request_host(): string
    {
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']))) : '';

        return (string) preg_replace('/:\d+$/', '', $host);
    }

    private function reject(): WP_Error
    {
        // One generic answer: don't tell a caller which check failed.
        return new WP_Error('waas_single_login_failed', 'This login link is invalid or has expired.', ['status' => 403]);
    }
}

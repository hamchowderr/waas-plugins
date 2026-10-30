<?php

namespace WaaSHost\Api;

use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * Token sign-in for a front end on another domain (the OAuth 2.0 authorization-code flow with PKCE), so it can call
 * the account API as the customer without sharing the storefront's login cookie.
 *
 * 1. The front end sends the customer to wp-login.php?action=waas-account-authorize with its client_id, an exact
 *    registered redirect_uri, response_type=code, a state and a S256 code_challenge.
 * 2. The customer signs in to the storefront as usual and is sent back to redirect_uri with a one-time code.
 * 3. The front end's server swaps the code for tokens at POST waas-host/v1/account/token, with its client secret and
 *    the PKCE verifier, and calls the account routes with "Authorization: Bearer <access token>".
 *
 * Clients are registered with `wp waas-host account-client` (see AccountClients). Tokens: AccountTokens.
 */
class AccountSignIn
{
    public const AUTHORIZE_ACTION = 'waas-account-authorize';

    /** Failed token requests (bad client or grant) per IP per window, before it is told to wait. */
    public const FAILURE_LIMIT = 20;
    public const FAILURE_WINDOW = 60;
    public const FAILURE_TRANSIENT = 'waas_acct_fail_';

    private AccountTokens $tokens;

    public function __construct(AccountTokens $tokens)
    {
        $this->tokens = $tokens;

        add_action('login_form_' . self::AUTHORIZE_ACTION, [$this, 'handle_authorize']);
        add_action('rest_api_init', [$this, 'register_routes']);
        // A new password signs the customer out of every front end.
        add_action('after_password_reset', [$this, 'revoke_on_password_reset']);
        add_action('profile_update', [$this, 'revoke_on_password_change'], 10, 2);
    }

    public function register_routes(): void
    {
        foreach (['token' => 'handle_token', 'revoke' => 'handle_revoke'] as $path => $callback) {
            register_rest_route(AccountController::NAMESPACE, "/$path", [
                'methods' => 'POST',
                'callback' => [$this, $callback],
                // The client signs in with its own secret, checked in the callback.
                'permission_callback' => '__return_true',
            ]);
        }
    }

    /** wp-login.php?action=waas-account-authorize: signs the customer in, then sends them back with a code. */
    public function handle_authorize(): void
    {
        if (!is_user_logged_in()) {
            // Back here after signing in.
            auth_redirect();
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an OAuth authorize request from another site; checked against the registered client and its redirect URI.
        $result = $this->authorize(wp_unslash($_GET), get_current_user_id());
        if (isset($result['error'])) {
            wp_die(esc_html($result['error']), esc_html__('Sign-in error', 'waas-host'), ['response' => 400]);
        }

        // An exact registered redirect URI on another host, so not wp_safe_redirect.
        wp_redirect($result['redirect']); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }

    /**
     * Checks an authorize request for a signed-in user.
     *
     * @return array{redirect: string}|array{error: string} where to send the customer, or why the request is refused
     *                                                    (shown on the storefront, never sent to an unchecked URI)
     */
    public function authorize(array $params, int $user_id): array
    {
        $client = AccountClients::find(self::param($params, 'client_id'));
        if ($client === null) {
            return ['error' => 'This sign-in link is not valid: the app asking to sign you in is not registered with this shop.'];
        }
        $redirect_uri = self::param($params, 'redirect_uri');
        if (!AccountClients::allows_redirect_uri($client, $redirect_uri)) {
            return ['error' => 'This sign-in link is not valid: it would send you somewhere the app has not registered.'];
        }

        // From here the redirect URI is the client's own, so errors go back to it, OAuth-style.
        $state = substr(self::param($params, 'state'), 0, 512);
        $back = fn (array $query) => ['redirect' => self::with_query($redirect_uri, $query + ($state !== '' ? ['state' => $state] : []))];

        if (self::param($params, 'response_type') !== 'code') {
            return $back(['error' => 'unsupported_response_type']);
        }
        $challenge = self::param($params, 'code_challenge');
        if (self::param($params, 'code_challenge_method') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
            return $back(['error' => 'invalid_request', 'error_description' => 'A S256 code_challenge is required.']);
        }

        return $back(['code' => $this->tokens->issue_code($client['id'], $redirect_uri, $user_id, $challenge)]);
    }

    /** POST /token: grant_type authorization_code or refresh_token. */
    public function handle_token(WP_REST_Request $request): WP_REST_Response
    {
        $retry_after = $this->failure_wait();
        if ($retry_after > 0) {
            $response = self::oauth_error('slow_down', 'Too many failed requests. Try again shortly.', 429);
            $response->header('Retry-After', (string) $retry_after);
            return $response;
        }

        $client = $this->client($request);
        if ($client === null) {
            return $this->failed(self::oauth_error('invalid_client', 'Unknown client or wrong secret.', 401));
        }

        $grant_type = (string) $request->get_param('grant_type');
        if ($grant_type === 'authorization_code') {
            $tokens = $this->exchange_code($request, $client['id']);
        } elseif ($grant_type === 'refresh_token') {
            $tokens = $this->tokens->refresh((string) $request->get_param('refresh_token'), $client['id']);
        } else {
            return self::oauth_error('unsupported_grant_type', 'Use authorization_code or refresh_token.', 400);
        }

        if ($tokens === null) {
            return $this->failed(self::oauth_error('invalid_grant', 'The code or refresh token is not valid. Please sign in again.', 400));
        }

        return self::no_store(new WP_REST_Response($tokens, 200));
    }

    /** POST /revoke: signs the customer out (RFC 7009: answers 200 whether or not the token was known). */
    public function handle_revoke(WP_REST_Request $request): WP_REST_Response
    {
        $client = $this->client($request);
        if ($client === null) {
            return $this->failed(self::oauth_error('invalid_client', 'Unknown client or wrong secret.', 401));
        }
        $this->tokens->revoke((string) $request->get_param('token'), $client['id']);

        return self::no_store(new WP_REST_Response(new \stdClass(), 200));
    }

    /** @param WP_User $user */
    public function revoke_on_password_reset($user): void
    {
        if ($user instanceof WP_User) {
            $this->tokens->revoke_all((int) $user->ID);
        }
    }

    /** @param WP_User $old_user the user before the update */
    public function revoke_on_password_change($user_id, $old_user): void
    {
        $user = get_userdata($user_id);
        if ($old_user instanceof WP_User && $user instanceof WP_User && $old_user->user_pass !== $user->user_pass) {
            $this->tokens->revoke_all((int) $user_id);
        }
    }

    private function exchange_code(WP_REST_Request $request, string $client_id): ?array
    {
        // The code is used up even when the rest doesn't match, so it can't be tried again.
        $code = $this->tokens->consume_code((string) $request->get_param('code'));
        $verifier = (string) $request->get_param('code_verifier');
        if ($code === null
            || $code['client_id'] !== $client_id
            || $code['redirect_uri'] !== (string) $request->get_param('redirect_uri')
            || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)
            || !hash_equals($code['code_challenge'], AccountTokens::base64url(hash('sha256', $verifier, true)))) {
            return null;
        }

        return $this->tokens->issue_for_code($code, $client_id);
    }

    /** The client from HTTP Basic auth (client_secret_basic) or the body (client_secret_post). */
    private function client(WP_REST_Request $request): ?array
    {
        $id = (string) $request->get_param('client_id');
        $secret = (string) $request->get_param('client_secret');
        $authorization = (string) $request->get_header('authorization');
        if (stripos($authorization, 'basic ') === 0) {
            $decoded = base64_decode(substr($authorization, 6), true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                [$id, $secret] = array_map('urldecode', explode(':', $decoded, 2));
            }
        }

        return AccountClients::authenticate($id, $secret);
    }

    /** Counts a failed request against the caller's IP. */
    private function failed(WP_REST_Response $response): WP_REST_Response
    {
        $key = self::failure_key();
        $window = get_transient($key);
        $now = time();
        if (!is_array($window) || (int) ($window['start'] ?? 0) <= $now - self::FAILURE_WINDOW) {
            $window = ['start' => $now, 'count' => 0];
        }
        $window['count'] = (int) $window['count'] + 1;
        set_transient($key, $window, self::FAILURE_WINDOW);

        return $response;
    }

    /** @return int 0 when the caller may try, else seconds to wait */
    private function failure_wait(): int
    {
        $window = get_transient(self::failure_key());
        $now = time();
        if (!is_array($window) || (int) ($window['start'] ?? 0) <= $now - self::FAILURE_WINDOW || (int) $window['count'] < self::FAILURE_LIMIT) {
            return 0;
        }

        return max(1, (int) $window['start'] + self::FAILURE_WINDOW - $now);
    }

    private static function failure_key(): string
    {
        return self::FAILURE_TRANSIENT . md5(isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '');
    }

    /** An OAuth error body: {"error": ..., "error_description": ...}. */
    private static function oauth_error(string $error, string $description, int $status): WP_REST_Response
    {
        return self::no_store(new WP_REST_Response(['error' => $error, 'error_description' => $description], $status));
    }

    private static function no_store(WP_REST_Response $response): WP_REST_Response
    {
        $response->header('Cache-Control', 'no-store');
        $response->header('Pragma', 'no-cache');

        return $response;
    }

    private static function param(array $params, string $key): string
    {
        return is_string($params[$key] ?? null) ? $params[$key] : '';
    }

    private static function with_query(string $uri, array $query): string
    {
        return $uri . (strpos($uri, '?') === false ? '?' : '&') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}

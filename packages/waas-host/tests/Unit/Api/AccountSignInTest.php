<?php

namespace Tests\Unit\Api;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSHost\Api\AccountClients;
use WaaSHost\Api\AccountSignIn;
use WaaSHost\Api\AccountTokens;
use WP_REST_Request;
use WP_REST_Response;

class AccountSignInTest extends TestCase
{
    private const USER_ID = 7;
    private const REDIRECT_URI = 'https://front.example/api/auth/callback';
    private const VERIFIER = 'a-verifier-that-is-long-enough-to-be-valid-0123456789';

    private AccountTokens $tokens;
    private AccountSignIn $sign_in;
    private array $options = [];
    private array $transients = [];
    private array $user_meta = [];
    /** @var array<string, array> path => route options */
    private array $routes = [];
    private string $client_id;
    private string $client_secret;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_salt')->justReturn('auth-salt');
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->alias(fn ($key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('get_transient')->alias(fn ($key) => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function ($key, $value) {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('delete_transient')->alias(function ($key) {
            $existed = array_key_exists($key, $this->transients);
            unset($this->transients[$key]);
            return $existed;
        });
        Functions\when('get_user_meta')->alias(fn ($user_id, $key) => $this->user_meta[$user_id][$key] ?? '');
        Functions\when('update_user_meta')->alias(function ($user_id, $key, $value) {
            $this->user_meta[$user_id][$key] = $value;
            return true;
        });
        Functions\when('delete_user_meta')->alias(function ($user_id, $key) {
            unset($this->user_meta[$user_id][$key]);
            return true;
        });
        Functions\when('register_rest_route')->alias(function ($namespace, $path, $options) {
            $this->assertSame('waas-host/v1/account', $namespace);
            $this->routes[$path] = $options;
        });

        $client = AccountClients::create('Front end', [self::REDIRECT_URI]);
        $this->client_id = $client['client_id'];
        $this->client_secret = $client['client_secret'];

        $this->tokens = new AccountTokens();
        $this->sign_in = new AccountSignIn($this->tokens);
        $this->sign_in->register_routes();
    }

    public function test_token_and_revoke_routes_are_registered(): void
    {
        $this->assertSame(['/token', '/revoke'], array_keys($this->routes));
        $this->assertSame('POST', $this->routes['/token']['methods']);
    }

    public function test_clients_keep_only_a_hash_of_their_secret(): void
    {
        $stored = $this->options[AccountClients::OPTION][$this->client_id];

        $this->assertStringNotContainsString($this->client_secret, json_encode($stored));
        $this->assertNotNull(AccountClients::authenticate($this->client_id, $this->client_secret));
        $this->assertNull(AccountClients::authenticate($this->client_id, 'wrong'));
        $this->assertNull(AccountClients::authenticate('unknown', $this->client_secret));
    }

    public function test_redirect_uris_must_be_https_without_a_fragment(): void
    {
        $this->assertTrue(AccountClients::is_valid_redirect_uri('https://front.example/cb?x=1'));
        $this->assertTrue(AccountClients::is_valid_redirect_uri('http://localhost:3000/cb'));
        $this->assertFalse(AccountClients::is_valid_redirect_uri('http://front.example/cb'));
        $this->assertFalse(AccountClients::is_valid_redirect_uri('https://front.example/cb#frag'));
        $this->assertFalse(AccountClients::is_valid_redirect_uri('javascript:alert(1)'));

        $this->expectException(\InvalidArgumentException::class);
        AccountClients::create('Bad', ['http://front.example/cb']);
    }

    public function test_authorize_sends_the_customer_back_with_a_code_and_the_state(): void
    {
        $result = $this->sign_in->authorize($this->authorize_params(), self::USER_ID);

        $this->assertStringStartsWith(self::REDIRECT_URI . '?code=', $result['redirect']);
        parse_str(parse_url($result['redirect'], PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state']);
        $this->assertNotEmpty($query['code']);
    }

    public function test_authorize_refuses_an_unregistered_client_or_redirect_uri_without_redirecting(): void
    {
        $unknown = $this->sign_in->authorize(['client_id' => 'nope'] + $this->authorize_params(), self::USER_ID);
        $elsewhere = $this->sign_in->authorize(['redirect_uri' => 'https://evil.example/cb'] + $this->authorize_params(), self::USER_ID);
        $prefix = $this->sign_in->authorize(['redirect_uri' => self::REDIRECT_URI . '/more'] + $this->authorize_params(), self::USER_ID);

        foreach ([$unknown, $elsewhere, $prefix] as $result) {
            $this->assertArrayNotHasKey('redirect', $result);
            $this->assertNotEmpty($result['error']);
        }
    }

    public function test_authorize_requires_pkce_with_s256(): void
    {
        $plain = $this->sign_in->authorize(['code_challenge_method' => 'plain'] + $this->authorize_params(), self::USER_ID);
        $missing = $this->sign_in->authorize(['code_challenge' => ''] + $this->authorize_params(), self::USER_ID);

        foreach ([$plain, $missing] as $result) {
            $this->assertStringStartsWith(self::REDIRECT_URI . '?error=invalid_request', $result['redirect']);
            $this->assertStringContainsString('state=xyz', $result['redirect']);
        }
        $this->assertSame([], $this->transients, 'no code was made');
    }

    public function test_authorize_only_answers_response_type_code(): void
    {
        $result = $this->sign_in->authorize(['response_type' => 'token'] + $this->authorize_params(), self::USER_ID);

        $this->assertStringStartsWith(self::REDIRECT_URI . '?error=unsupported_response_type', $result['redirect']);
    }

    public function test_a_code_swaps_for_tokens_that_sign_the_customer_in(): void
    {
        $response = $this->token(['grant_type' => 'authorization_code', 'code' => $this->code()] + $this->code_params());

        $this->assertSame(200, $response->get_status());
        $this->assertSame('no-store', $response->get_headers()['Cache-Control']);
        $data = $response->get_data();
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertSame(AccountTokens::ACCESS_TTL, $data['expires_in']);
        $this->assertSame(self::USER_ID, $this->tokens->user_for_access_token($data['access_token']));
        $this->assertSame(0, $this->tokens->user_for_access_token($data['refresh_token']), 'a refresh token is not an access token');
        $this->assertStringNotContainsString($data['access_token'], json_encode($this->user_meta), 'only hashes are kept');
    }

    public function test_the_client_can_sign_in_with_http_basic(): void
    {
        $request = $this->request(['grant_type' => 'authorization_code', 'code' => $this->code(), 'redirect_uri' => self::REDIRECT_URI, 'code_verifier' => self::VERIFIER]);
        $request->set_header('Authorization', 'Basic ' . base64_encode($this->client_id . ':' . $this->client_secret));

        $this->assertSame(200, $this->routes['/token']['callback']($request)->get_status());
    }

    public function test_a_code_works_once_and_reusing_it_revokes_its_tokens(): void
    {
        $code = $this->code();
        $first = $this->token(['grant_type' => 'authorization_code', 'code' => $code] + $this->code_params())->get_data();

        $second = $this->token(['grant_type' => 'authorization_code', 'code' => $code] + $this->code_params());

        $this->assertOAuthError(400, 'invalid_grant', $second);
        $this->assertSame(0, $this->tokens->user_for_access_token($first['access_token']), 'the first exchange is revoked');
        $this->assertOAuthError(400, 'invalid_grant', $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token']] + $this->client_params()));
    }

    public function test_a_wrong_redirect_uri_is_refused_and_burns_the_code(): void
    {
        $code = $this->code();

        $this->assertOAuthError(400, 'invalid_grant', $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://front.example/other'] + $this->code_params()));
        $this->assertOAuthError(400, 'invalid_grant', $this->token(['grant_type' => 'authorization_code', 'code' => $code] + $this->code_params()));
    }

    public function test_a_wrong_pkce_verifier_is_refused(): void
    {
        $response = $this->token(['grant_type' => 'authorization_code', 'code' => $this->code(), 'code_verifier' => str_repeat('b', 43)] + $this->code_params());

        $this->assertOAuthError(400, 'invalid_grant', $response);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $code = $this->code();
        foreach ($this->transients as $key => $value) {
            if (strpos($key, AccountTokens::CODE_TRANSIENT) === 0) {
                $this->transients[$key]['expires'] = time() - 1;
            }
        }

        $this->assertOAuthError(400, 'invalid_grant', $this->token(['grant_type' => 'authorization_code', 'code' => $code] + $this->code_params()));
    }

    public function test_a_code_is_only_for_the_client_it_was_made_for(): void
    {
        $other = AccountClients::create('Other', ['https://other.example/cb']);

        $response = $this->token(['grant_type' => 'authorization_code', 'code' => $this->code(), 'client_id' => $other['client_id'], 'client_secret' => $other['client_secret']] + $this->code_params());

        $this->assertOAuthError(400, 'invalid_grant', $response);
    }

    public function test_a_wrong_client_secret_is_refused(): void
    {
        $response = $this->token(['grant_type' => 'authorization_code', 'code' => $this->code(), 'client_secret' => 'wrong'] + $this->code_params());

        $this->assertOAuthError(401, 'invalid_client', $response);
    }

    public function test_refreshing_rotates_both_tokens(): void
    {
        $first = $this->sign_in_tokens();

        $second = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token']] + $this->client_params())->get_data();

        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertSame(self::USER_ID, $this->tokens->user_for_access_token($second['access_token']));
        $this->assertSame(0, $this->tokens->user_for_access_token($first['access_token']), 'the old access token stops working');
    }

    public function test_reusing_a_rotated_refresh_token_revokes_the_session(): void
    {
        $first = $this->sign_in_tokens();
        $second = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token']] + $this->client_params())->get_data();

        $reused = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token']] + $this->client_params());

        $this->assertOAuthError(400, 'invalid_grant', $reused);
        $this->assertSame(0, $this->tokens->user_for_access_token($second['access_token']));
        $this->assertOAuthError(400, 'invalid_grant', $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $second['refresh_token']] + $this->client_params()));
    }

    public function test_a_refresh_token_only_works_for_its_own_client(): void
    {
        $tokens = $this->sign_in_tokens();
        $other = AccountClients::create('Other', ['https://other.example/cb']);

        $response = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $other['client_id'], 'client_secret' => $other['client_secret']]);

        $this->assertOAuthError(400, 'invalid_grant', $response);
        $this->assertSame(self::USER_ID, $this->tokens->user_for_access_token($tokens['access_token']), 'still signed in on its own client');
    }

    public function test_access_tokens_expire(): void
    {
        $tokens = $this->sign_in_tokens();
        foreach ($this->user_meta[self::USER_ID][AccountTokens::SESSIONS_META] as $id => $session) {
            $this->user_meta[self::USER_ID][AccountTokens::SESSIONS_META][$id]['access_expires'] = time() - 1;
        }

        $this->assertSame(0, $this->tokens->user_for_access_token($tokens['access_token']));
    }

    public function test_signing_out_revokes_the_session(): void
    {
        $tokens = $this->sign_in_tokens();

        $response = $this->routes['/revoke']['callback']($this->request(['token' => $tokens['refresh_token']] + $this->client_params()));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(0, $this->tokens->user_for_access_token($tokens['access_token']));
    }

    public function test_another_client_cant_revoke_a_session(): void
    {
        $tokens = $this->sign_in_tokens();
        $other = AccountClients::create('Other', ['https://other.example/cb']);

        $this->routes['/revoke']['callback']($this->request(['token' => $tokens['access_token'], 'client_id' => $other['client_id'], 'client_secret' => $other['client_secret']]));

        $this->assertSame(self::USER_ID, $this->tokens->user_for_access_token($tokens['access_token']));
    }

    public function test_a_new_password_ends_every_session(): void
    {
        $tokens = $this->sign_in_tokens();

        $this->tokens->revoke_all(self::USER_ID);

        $this->assertSame(0, $this->tokens->user_for_access_token($tokens['access_token']));
    }

    public function test_too_many_failures_have_to_wait(): void
    {
        for ($i = 0; $i < AccountSignIn::FAILURE_LIMIT; $i++) {
            $this->assertOAuthError(401, 'invalid_client', $this->token(['grant_type' => 'refresh_token', 'client_secret' => 'wrong'] + $this->client_params()));
        }

        $response = $this->token(['grant_type' => 'authorization_code', 'code' => $this->code()] + $this->code_params());

        $this->assertOAuthError(429, 'slow_down', $response);
        $this->assertGreaterThan(0, (int) $response->get_headers()['Retry-After']);
    }

    public function test_unknown_grant_types_are_refused(): void
    {
        $this->assertOAuthError(400, 'unsupported_grant_type', $this->token(['grant_type' => 'password'] + $this->client_params()));
    }

    private function authorize_params(): array
    {
        return [
            'client_id' => $this->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'state' => 'xyz',
            'code_challenge' => AccountTokens::base64url(hash('sha256', self::VERIFIER, true)),
            'code_challenge_method' => 'S256',
        ];
    }

    private function code(): string
    {
        parse_str(parse_url($this->sign_in->authorize($this->authorize_params(), self::USER_ID)['redirect'], PHP_URL_QUERY), $query);

        return $query['code'];
    }

    private function sign_in_tokens(): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $this->code()] + $this->code_params())->get_data();
    }

    private function client_params(): array
    {
        return ['client_id' => $this->client_id, 'client_secret' => $this->client_secret];
    }

    private function code_params(): array
    {
        return ['redirect_uri' => self::REDIRECT_URI, 'code_verifier' => self::VERIFIER] + $this->client_params();
    }

    private function token(array $params): WP_REST_Response
    {
        return $this->routes['/token']['callback']($this->request($params));
    }

    private function request(array $params): WP_REST_Request
    {
        return new WP_REST_Request('POST', '', $params);
    }

    private function assertOAuthError(int $status, string $error, WP_REST_Response $response): void
    {
        $this->assertSame($status, $response->get_status());
        $this->assertSame($error, $response->get_data()['error']);
    }
}

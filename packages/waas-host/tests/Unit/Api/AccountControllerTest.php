<?php

namespace Tests\Unit\Api;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Api\AccountController;
use WaaSHost\Api\AccountTokens;
use WaaSHost\Api\SingleLogin;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\PrivateKeyStore;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\NameConflicts;
use WaaSHost\Features\WebsiteSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class AccountControllerTest extends TestCase
{
    private const OWNER_ID = 7;
    private const OTHER_CUSTOMER_ID = 99;
    /** The owner's website, ready. */
    private const WEBSITE = 42;
    /** Another customer's website. */
    private const OTHERS_WEBSITE = 43;
    /** The owner's subscription whose website name was taken: no store yet. */
    private const WAITING_FOR_NAME = 44;

    private const ID = '/websites/(?P<id>\d+)';

    private static string $private_key;

    private $api;
    private $name_conflicts;
    private $config;
    private $tokens;
    private int $current_user = self::OWNER_ID;
    private FakeCommerceAdapter $cart;
    /** @var array<string, array> "METHOD path" => route options */
    private array $routes = [];
    private array $transients = [];

    public static function setUpBeforeClass(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $private_key);
        self::$private_key = $private_key;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_salt')->justReturn('auth-salt');
        Functions\when('sanitize_title_with_dashes')->alias(fn ($value) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $value)), '-'));
        Functions\when('remove_accents')->returnArg();
        $this->current_user = self::OWNER_ID;
        Functions\when('get_current_user_id')->alias(fn () => $this->current_user);
        Functions\when('wp_set_current_user')->alias(function ($id) {
            $this->current_user = (int) $id;
        });
        Functions\when('get_transient')->alias(fn ($key) => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function ($key, $value) {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('register_rest_route')->alias(function ($namespace, $path, $options) {
            $this->assertSame('waas-host/v1/account', $namespace);
            $this->routes[$options['methods'] . ' ' . $path] = $options;
        });

        Commerce::reset();
        $this->cart = new FakeCommerceAdapter();
        $this->cart->customer_id = self::OWNER_ID;
        $this->cart->owners[self::OTHERS_WEBSITE] = self::OTHER_CUSTOMER_ID;
        $this->cart->meta = [
            self::WEBSITE => [
                Tenant::TENANT_EXTERNAL_ID_META => 'ext-42',
                Tenant::DOMAIN_NAME_META => 'apex.example.com',
                Tenant::TENANT_STATE => Tenant::READY,
            ],
            self::OTHERS_WEBSITE => [
                Tenant::TENANT_EXTERNAL_ID_META => 'ext-43',
                Tenant::DOMAIN_NAME_META => 'theirs.example.com',
                Tenant::TENANT_STATE => Tenant::READY,
            ],
            self::WAITING_FOR_NAME => [
                Tenant::TENANT_STATE => Tenant::NEEDS_NEW_NAME,
            ],
        ];
        Commerce::register($this->cart);

        $this->api = Mockery::mock(ProvisioningApi::class);
        $this->name_conflicts = Mockery::mock(NameConflicts::class);
        $this->config = Mockery::mock(ConfigService::class);
        $this->config->allows('get_dns_a_records')->andReturn(['191.101.15.236'])->byDefault();
        $this->config->allows('get_dns_cname_target')->andReturn('stores.example.net')->byDefault();
        $this->tokens = Mockery::mock(AccountTokens::class);
        $controller = new AccountController(new WebsiteSettings($this->api), $this->name_conflicts, $this->config, $this->tokens);
        $controller->register_routes();
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    /** Every route of the API, with the parameters a valid call needs. */
    public function routes(): array
    {
        return [
            'who is signed in' => ['GET', '/me', []],
            'list websites' => ['GET', '/websites', []],
            'login' => ['POST', self::ID . '/login', []],
            'change address' => ['PUT', self::ID . '/address', ['domain' => 'new.example.com']],
            'get email sender' => ['GET', self::ID . '/email-sender', []],
            'set email sender' => ['PUT', self::ID . '/email-sender', ['domain' => 'mail.example.com']],
            'remove email sender' => ['DELETE', self::ID . '/email-sender', []],
            'domain connect' => ['GET', self::ID . '/domain-connect', ['domain' => 'apex.example.com', 'service' => 'website']],
            'choose name' => ['POST', self::ID . '/name', ['name' => 'Apex Two']],
        ];
    }

    /** The routes for one website. */
    public function website_routes(): array
    {
        return array_filter($this->routes(), fn ($route) => strpos($route[1], '(?P<id>') !== false);
    }

    public function test_all_routes_are_registered_and_none_is_public(): void
    {
        $this->assertEqualsCanonicalizing(
            array_map(fn ($route) => $route[0] . ' ' . $route[1], array_values($this->routes())),
            array_keys($this->routes)
        );
        foreach ($this->routes as $options) {
            $this->assertIsCallable($options['permission_callback']);
        }
    }

    /** @dataProvider website_routes */
    public function test_another_customers_website_is_refused(string $method, string $path, array $params): void
    {
        $this->api->shouldNotReceive('add_tenant_domain', 'get_mail_domain', 'set_mail_domain', 'remove_mail_domain', 'domain_connect');
        $this->name_conflicts->shouldNotReceive('choose');

        $this->assertError(403, $this->call($method, $path, self::OTHERS_WEBSITE, $params));
    }

    /** @dataProvider website_routes */
    public function test_a_subscription_that_does_not_exist_is_refused_like_someone_elses(string $method, string $path, array $params): void
    {
        $this->cart->owners[404] = 0;

        $this->assertError(403, $this->call($method, $path, 404, $params));
    }

    /** @dataProvider routes */
    public function test_signed_out_visitors_are_refused(string $method, string $path, array $params): void
    {
        $this->current_user = 0;

        $this->assertError(401, $this->call($method, $path, self::WEBSITE, $params));
    }

    /** @dataProvider routes */
    public function test_an_access_token_signs_in_on_every_route(string $method, string $path, array $params): void
    {
        $this->current_user = 0;
        $this->tokens->expects('user_for_access_token')->with('at.7.token')->andReturn(self::OWNER_ID);

        $allowed = $this->routes["$method $path"]['permission_callback']($this->request(self::WEBSITE, $params, 'Bearer at.7.token'));

        $this->assertTrue($allowed);
        $this->assertSame(self::OWNER_ID, $this->current_user);
    }

    public function test_an_access_token_only_reaches_its_own_users_websites(): void
    {
        $this->current_user = 0;
        $this->tokens->expects('user_for_access_token')->andReturn(self::OWNER_ID);

        $refused = $this->routes['PUT ' . self::ID . '/address']['permission_callback']($this->request(self::OTHERS_WEBSITE, ['domain' => 'x.example.com'], 'Bearer at.7.token'));

        $this->assertError(403, $refused);
    }

    public function test_an_expired_or_unknown_access_token_is_refused(): void
    {
        $this->current_user = 0;
        $this->tokens->expects('user_for_access_token')->with('at.7.old')->andReturn(0);

        $refused = $this->routes['GET /websites']['permission_callback']($this->request(null, [], 'Bearer at.7.old'));

        $this->assertError(401, $refused);
        $this->assertSame('waas_invalid_token', $refused->get_error_code());
        $this->assertSame(0, $this->current_user);
    }

    public function test_the_login_cookie_wins_over_a_token(): void
    {
        $this->tokens->shouldNotReceive('user_for_access_token');

        $this->assertTrue($this->routes['GET /websites']['permission_callback']($this->request(null, [], 'Bearer at.99.someone-else')));
    }

    public function test_me_is_the_signed_in_customer(): void
    {
        Functions\when('wp_get_current_user')->justReturn((object) ['ID' => self::OWNER_ID, 'user_email' => 'pat@example.com', 'display_name' => 'Pat']);

        $this->assertSame(['id' => self::OWNER_ID, 'email' => 'pat@example.com', 'name' => 'Pat'], $this->call('GET', '/me')->get_data());
    }

    public function test_lists_the_dns_records_for_a_custom_address(): void
    {
        $this->assertSame([
            'apex' => [['type' => 'A', 'value' => '191.101.15.236']],
            'subdomain' => [['type' => 'CNAME', 'value' => 'stores.example.net']],
        ], $this->call('GET', '/websites')->get_data()['custom_address_records']);
    }

    public function test_no_dns_records_when_the_storefront_has_none_set(): void
    {
        $this->config->allows('get_dns_a_records')->andReturn([]);
        $this->config->allows('get_dns_cname_target')->andReturn('');

        $this->assertNull($this->call('GET', '/websites')->get_data()['custom_address_records']);
    }

    public function test_ipv6_addresses_are_aaaa_records_and_the_cname_is_optional(): void
    {
        $this->config->allows('get_dns_a_records')->andReturn(['191.101.15.236', '2001:db8::1']);
        $this->config->allows('get_dns_cname_target')->andReturn('');

        $this->assertSame([
            'apex' => [['type' => 'A', 'value' => '191.101.15.236'], ['type' => 'AAAA', 'value' => '2001:db8::1']],
            'subdomain' => [],
        ], $this->call('GET', '/websites')->get_data()['custom_address_records']);
    }

    public function test_website_routes_need_a_store_except_choosing_a_name(): void
    {
        foreach ($this->website_routes() as $name => [$method, $path, $params]) {
            $allowed = $this->routes["$method $path"]['permission_callback']($this->request(self::WAITING_FOR_NAME, $params));
            if ($name === 'choose name') {
                $this->assertTrue($allowed);
            } else {
                $this->assertInstanceOf(WP_Error::class, $allowed, $name);
                $this->assertSame(409, $allowed->get_error_data()['status'], $name);
            }
        }
    }

    public function test_lists_only_the_customers_own_websites(): void
    {
        $suspend_at = 1798761600; // 2027-01-01
        $this->cart->meta[self::WEBSITE][Tenant::TENANT_SUSPEND_AT_META] = $suspend_at;
        $this->cart->meta[45] = []; // a subscription without a website

        $response = $this->call('GET', '/websites');

        $this->assertSame(200, $response->get_status());
        $this->assertSame('no-store', $response->get_headers()['Cache-Control']);
        $this->assertSame([
            [
                'id' => self::WAITING_FOR_NAME,
                'domain' => '',
                'status' => Tenant::NEEDS_NEW_NAME,
                'status_label' => 'Choose a new website name',
                'active_until' => null,
                'can_log_in' => false,
                'needs_new_name' => true,
                'manage_plan_url' => 'https://storefront.example/account/subscriptions/44',
                'buy_emails_url' => null,
            ],
            [
                'id' => self::WEBSITE,
                'domain' => 'apex.example.com',
                'status' => Tenant::READY,
                'status_label' => Tenant::READY,
                'active_until' => '2027-01-01T00:00:00+00:00',
                'can_log_in' => true,
                'needs_new_name' => false,
                'manage_plan_url' => 'https://storefront.example/account/subscriptions/42',
                'buy_emails_url' => null,
            ],
        ], $response->get_data()['websites']);
    }

    public function test_lists_the_email_pack_checkout_when_the_cart_sells_one(): void
    {
        Filters\expectApplied('waas_buy_emails_url')->andReturnUsing(function ($url, $subscription_id) {
            return $subscription_id === self::WEBSITE ? 'https://storefront.example/?fluent-cart=instant_checkout&item_id=9&quantity=1&waas_website=42' : $url;
        });

        $websites = array_column($this->call('GET', '/websites')->get_data()['websites'], 'buy_emails_url', 'id');

        $this->assertSame('https://storefront.example/?fluent-cart=instant_checkout&item_id=9&quantity=1&waas_website=42', $websites[self::WEBSITE]);
        $this->assertNull($websites[self::WAITING_FOR_NAME]);
    }

    public function test_login_returns_a_fresh_sign_in_link(): void
    {
        PrivateKeyStore::save(self::WEBSITE, self::$private_key);

        $first = $this->call('POST', self::ID . '/login', self::WEBSITE);
        $second = $this->call('POST', self::ID . '/login', self::WEBSITE);

        $this->assertSame(200, $first->get_status());
        $this->assertStringStartsWith('https://apex.example.com/wp-json/waas-client/v1/single_login/verify?token=', $first->get_data()['url']);
        $this->assertSame(SingleLogin::PORTAL_TOKEN_LIFETIME, $first->get_data()['expires_in']);
        $this->assertSame('no-store', $first->get_headers()['Cache-Control']);
        $this->assertNotSame($first->get_data()['url'], $second->get_data()['url'], 'every call makes a new link');
    }

    public function test_login_is_rate_limited(): void
    {
        PrivateKeyStore::save(self::WEBSITE, self::$private_key);

        for ($i = 0; $i < AccountController::LOGIN_LIMIT; $i++) {
            $this->assertSame(200, $this->call('POST', self::ID . '/login', self::WEBSITE)->get_status());
        }
        $refused = $this->call('POST', self::ID . '/login', self::WEBSITE);

        $this->assertInstanceOf(WP_REST_Response::class, $refused);
        $this->assertSame(429, $refused->get_status());
        $this->assertSame('waas_rate_limited', $refused->get_data()['code']);
        $this->assertGreaterThan(0, (int) $refused->get_headers()['Retry-After']);
    }

    public function test_login_waits_until_the_website_is_ready(): void
    {
        $this->cart->meta[self::WEBSITE][Tenant::TENANT_STATE] = Tenant::PROVISIONING;

        $this->assertError(409, $this->call('POST', self::ID . '/login', self::WEBSITE));
    }

    public function test_login_without_the_stores_key_is_an_error(): void
    {
        $this->assertError(500, $this->call('POST', self::ID . '/login', self::WEBSITE));
    }

    public function test_changes_the_address(): void
    {
        $this->api->expects('add_tenant_domain')->with(['external_id' => 'ext-42', 'domain_name' => 'new.example.com']);
        Functions\expect('wp_schedule_single_event')->once()->with(Mockery::type('int'), 'remove_tenant_old_domain', ['ext-42', 'apex.example.com']);

        $response = $this->call('PUT', self::ID . '/address', self::WEBSITE, ['domain' => ' New.Example.com ']);

        $this->assertSame(['domain' => 'new.example.com'], $response->get_data());
        $this->assertSame('new.example.com', $this->cart->meta[self::WEBSITE][Tenant::DOMAIN_NAME_META]);
    }

    public function test_an_invalid_address_is_refused_with_a_plain_message(): void
    {
        $this->api->shouldNotReceive('add_tenant_domain');

        $error = $this->call('PUT', self::ID . '/address', self::WEBSITE, ['domain' => 'not a domain']);

        $this->assertError(400, $error);
        $this->assertSame('Please enter a valid domain name, like example.com.', $error->get_error_message());
    }

    public function test_the_apis_reason_is_passed_on_when_it_refuses_an_address(): void
    {
        $this->api->expects('add_tenant_domain')->andThrow(new \Exception('Failed: 400 - {"message":"That domain is already in use."}'));

        $error = $this->call('PUT', self::ID . '/address', self::WEBSITE, ['domain' => 'taken.example.com']);

        $this->assertError(400, $error);
        $this->assertSame('That domain is already in use.', $error->get_error_message());
        $this->assertSame('apex.example.com', $this->cart->meta[self::WEBSITE][Tenant::DOMAIN_NAME_META]);
    }

    public function test_reads_the_email_sender_with_its_dns_records(): void
    {
        $this->api->expects('get_mail_domain')->with('ext-42')->andReturn((object) [
            'domainName' => 'apex.example.com',
            'status' => 'pending',
            'records' => [(object) ['type' => 'MX', 'name' => 'send', 'value' => 'feedback.example.net', 'priority' => 10]],
        ]);

        $this->assertSame(['email_sender' => [
            'domain' => 'apex.example.com',
            'from' => 'no-reply@apex.example.com',
            'status' => 'pending',
            'records' => [['type' => 'MX', 'name' => 'send', 'value' => 'feedback.example.net', 'priority' => 10]],
        ]], $this->call('GET', self::ID . '/email-sender', self::WEBSITE)->get_data());
    }

    public function test_no_email_sender_while_the_website_sends_from_the_platform(): void
    {
        $this->api->expects('get_mail_domain')->with('ext-42')->andReturnNull();

        $this->assertSame(['email_sender' => null], $this->call('GET', self::ID . '/email-sender', self::WEBSITE)->get_data());
    }

    public function test_an_unreachable_api_is_a_502(): void
    {
        $this->api->expects('get_mail_domain')->andThrow(new \Exception('Failed: 500 - oops'));

        $error = $this->call('GET', self::ID . '/email-sender', self::WEBSITE);

        $this->assertError(502, $error);
        $this->assertSame(WebsiteSettings::GENERIC_ERROR_MESSAGE, $error->get_error_message());
    }

    public function test_sets_the_email_sender(): void
    {
        $this->api->expects('set_mail_domain')->with('ext-42', 'mail.example.com')
            ->andReturn((object) ['domainName' => 'mail.example.com', 'status' => 'pending', 'records' => []]);

        $data = $this->call('PUT', self::ID . '/email-sender', self::WEBSITE, ['domain' => 'Mail.Example.com'])->get_data();

        $this->assertSame('no-reply@mail.example.com', $data['email_sender']['from']);
        $this->assertSame('pending', $data['email_sender']['status']);
    }

    public function test_an_invalid_email_sender_is_refused(): void
    {
        $this->api->shouldNotReceive('set_mail_domain');

        $this->assertError(400, $this->call('PUT', self::ID . '/email-sender', self::WEBSITE, ['domain' => 'localhost']));
    }

    public function test_removes_the_email_sender(): void
    {
        $this->api->expects('remove_mail_domain')->with('ext-42');

        $this->assertSame(['removed' => true], $this->call('DELETE', self::ID . '/email-sender', self::WEBSITE)->get_data());
    }

    public function test_domain_connect_link(): void
    {
        $this->api->expects('domain_connect')->with('ext-42', 'mail.example.com', 'email')
            ->andReturn((object) ['supported' => true, 'url' => 'https://dns.example.net/connect', 'dnsProvider' => 'Example DNS']);

        $this->assertSame(
            ['supported' => true, 'url' => 'https://dns.example.net/connect', 'dns_provider' => 'Example DNS', 'conflict' => null],
            $this->call('GET', self::ID . '/domain-connect', self::WEBSITE, ['domain' => 'mail.example.com', 'service' => 'email'])->get_data()
        );
    }

    public function test_domain_connect_without_support_or_with_an_unsafe_link(): void
    {
        $this->api->expects('domain_connect')->twice()->andReturn(
            (object) ['supported' => false, 'url' => null, 'dnsProvider' => 'Other DNS'],
            (object) ['supported' => true, 'url' => 'javascript:alert(1)', 'dnsProvider' => 'Other DNS']
        );
        $unsupported = ['supported' => false, 'url' => null, 'dns_provider' => null, 'conflict' => null];

        $this->assertSame($unsupported, $this->call('GET', self::ID . '/domain-connect', self::WEBSITE, ['domain' => 'apex.example.com'])->get_data());
        $this->assertSame($unsupported, $this->call('GET', self::ID . '/domain-connect', self::WEBSITE, ['domain' => 'apex.example.com'])->get_data());
    }

    public function test_domain_connect_passes_on_why_there_is_no_link(): void
    {
        $this->api->expects('domain_connect')->andReturn((object) [
            'supported' => false,
            'url' => null,
            'dnsProvider' => 'Cloudflare',
            'conflict' => 'tosend._domainkey.mail.example.com already has a different email signing (DKIM) key.',
        ]);

        $this->assertSame(
            ['supported' => false, 'url' => null, 'dns_provider' => null, 'conflict' => 'tosend._domainkey.mail.example.com already has a different email signing (DKIM) key.'],
            $this->call('GET', self::ID . '/domain-connect', self::WEBSITE, ['domain' => 'mail.example.com', 'service' => 'email'])->get_data()
        );
    }

    public function test_domain_connect_refuses_an_invalid_domain(): void
    {
        $this->api->shouldNotReceive('domain_connect');

        $this->assertError(400, $this->call('GET', self::ID . '/domain-connect', self::WEBSITE, ['domain' => 'bad domain']));
    }

    public function test_chooses_a_new_website_name(): void
    {
        $this->name_conflicts->expects('choose')->with(self::WAITING_FOR_NAME, 'Apex Two')->andReturn('ok');

        $response = $this->call('POST', self::ID . '/name', self::WAITING_FOR_NAME, ['name' => 'Apex Two']);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(NameConflicts::result_message('ok'), $response->get_data()['message']);
    }

    public function test_a_taken_name_is_refused_with_the_reason(): void
    {
        $this->name_conflicts->expects('choose')->andReturn('taken');

        $error = $this->call('POST', self::ID . '/name', self::WAITING_FOR_NAME, ['name' => 'Apex']);

        $this->assertError(400, $error);
        $this->assertSame(NameConflicts::result_message('taken'), $error->get_error_message());
    }

    public function test_a_name_for_a_website_that_needs_none_is_a_conflict(): void
    {
        $this->name_conflicts->expects('choose')->andReturn('not_needed');

        $this->assertError(409, $this->call('POST', self::ID . '/name', self::WEBSITE, ['name' => 'Apex']));
    }

    /** Runs a route like WordPress does: the permission callback first, then the callback. */
    private function call(string $method, string $path, $subscription_id = null, array $params = [])
    {
        $route = $this->routes["$method $path"] ?? null;
        $this->assertNotNull($route, "No route $method $path");

        $request = $this->request($subscription_id, $params);
        $allowed = $route['permission_callback']($request);
        if ($allowed !== true) {
            return $allowed;
        }

        return $route['callback']($request);
    }

    private function request($subscription_id, array $params, string $authorization = ''): WP_REST_Request
    {
        // Path parameters arrive as strings, like in WordPress.
        $request = new WP_REST_Request('', '', $subscription_id === null ? $params : ['id' => (string) $subscription_id] + $params);
        if ($authorization !== '') {
            $request->set_header('Authorization', $authorization);
        }

        return $request;
    }

    private function assertError(int $status, $result): void
    {
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame($status, $result->get_error_data()['status']);
        $this->assertNotSame('', $result->get_error_message());
    }
}

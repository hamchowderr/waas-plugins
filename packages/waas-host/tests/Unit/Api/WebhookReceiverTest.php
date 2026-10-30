<?php

namespace Tests\Unit\Api;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Api\WebhookReceiver;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\SecretBox;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\TenantStatusService;
use WaaSHost\Features\Webhooks;
use WP_REST_Request;

/** The API's signed events: checked exactly as docs/webhooks.md says, then acted on with the poller's own code. */
class WebhookReceiverTest extends TestCase
{
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    private FakeCommerceAdapter $cart;
    private array $options = [];
    private array $transients = [];
    private array $scheduled = [];
    private $api;
    private WebhookReceiver $receiver;
    private string $log;
    private $previous_log;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }
        Commerce::reset();
        \WaaSHost\Features\RunSoon::reset();
        Commerce::register($this->cart = new FakeCommerceAdapter());

        $this->options = [];
        $this->transients = [];
        $this->scheduled = [];
        Functions\when('wp_salt')->justReturn('auth-salt');
        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('update_option')->alias(function ($name, $value) {
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('get_transient')->alias(fn ($name) => $this->transients[$name] ?? false);
        Functions\when('set_transient')->alias(function ($name, $value) {
            $this->transients[$name] = $value;
            return true;
        });
        Functions\when('sanitize_key')->alias(fn ($key) => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)));
        Functions\when('wp_next_scheduled')->alias(fn ($hook, $args = []) => in_array([$hook, $args], array_map(fn ($s) => [$s[1], $s[2]], $this->scheduled), true) ? 1 : false);
        Functions\when('wp_schedule_single_event')->alias(function ($timestamp, $hook, $args = []) {
            $this->scheduled[] = [$timestamp, $hook, $args];
            return true;
        });
        Functions\when('wp_schedule_event')->justReturn(true);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn ($response) => $response['response']['code']);

        $this->options[Webhooks::OPTION] = ['id' => 'ep-1', 'secret' => SecretBox::seal(self::SECRET, 'waas-host-webhook-secret')];

        $this->api = Mockery::mock(ProvisioningApi::class);
        $this->receiver = new WebhookReceiver(new TenantStatusService($this->api));

        $this->log = tempnam(sys_get_temp_dir(), 'waas-log');
        $this->previous_log = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previous_log);
        @unlink($this->log);
        Commerce::reset();
        parent::tearDown();
    }

    private static function sign(string $secret, string $id, string $timestamp, string $body): string
    {
        $key = base64_decode(substr($secret, strlen('whsec_')));
        return 'v1,' . base64_encode(hash_hmac('sha256', "$id.$timestamp.$body", $key, true));
    }

    private function request(string $type, array $data, string $id = 'evt-1', ?int $timestamp = null, ?string $signature = null): WP_REST_Request
    {
        $body = json_encode(['type' => $type, 'timestamp' => '2026-10-20T10:00:00.000Z', 'data' => $data]);
        $timestamp = (string) ($timestamp ?? time());

        $request = new WP_REST_Request('POST', '/waas-host/v1/webhook');
        $request->set_header('webhook-id', $id);
        $request->set_header('webhook-timestamp', $timestamp);
        $request->set_header('webhook-signature', $signature ?? self::sign(self::SECRET, $id, $timestamp, $body));
        $request->set_body($body);

        return $request;
    }

    private function store(string $state, string $domain = 'apex.sites.example'): void
    {
        $this->cart->meta[900] = [
            Tenant::TENANT_STATE => $state,
            Tenant::TENANT_EXTERNAL_ID_META => 'ext-900',
            Tenant::DOMAIN_NAME_META => $domain,
        ];
        // Another store, so the lookup has to pick the right one.
        $this->cart->meta[901] = [Tenant::TENANT_STATE => Tenant::READY, Tenant::TENANT_EXTERNAL_ID_META => 'ext-901'];
    }

    public function test_a_valid_signature_is_accepted(): void
    {
        $now = 1760430000;
        $body = '{"type":"webhook.test"}';

        $this->assertTrue(WebhookReceiver::verify(self::SECRET, 'msg_1', (string) $now, self::sign(self::SECRET, 'msg_1', (string) $now, $body), $body, $now));
    }

    public function test_the_signature_must_be_over_this_body_with_this_secret(): void
    {
        $now = 1760430000;
        $body = '{"type":"webhook.test"}';
        $signature = self::sign(self::SECRET, 'msg_1', (string) $now, $body);

        $this->assertFalse(WebhookReceiver::verify('whsec_' . base64_encode('another key'), 'msg_1', (string) $now, $signature, $body, $now), 'wrong secret');
        $this->assertFalse(WebhookReceiver::verify(self::SECRET, 'msg_1', (string) $now, $signature, $body . ' ', $now), 'body changed');
        $this->assertFalse(WebhookReceiver::verify(self::SECRET, 'msg_2', (string) $now, $signature, $body, $now), 'another event id');
        $this->assertFalse(WebhookReceiver::verify(self::SECRET, 'msg_1', (string) $now, '', $body, $now), 'no signature');
        $this->assertFalse(WebhookReceiver::verify('', 'msg_1', (string) $now, $signature, $body, $now), 'no secret stored');
    }

    public function test_a_timestamp_more_than_five_minutes_off_is_refused(): void
    {
        $now = 1760430000;
        $body = '{}';
        $at = fn (int $timestamp) => WebhookReceiver::verify(self::SECRET, 'msg_1', (string) $timestamp, self::sign(self::SECRET, 'msg_1', (string) $timestamp, $body), $body, $now);

        $this->assertTrue($at($now - 300));
        $this->assertTrue($at($now + 300));
        $this->assertFalse($at($now - 301));
        $this->assertFalse($at($now + 301));
    }

    public function test_any_one_of_several_signatures_may_match(): void
    {
        $now = 1760430000;
        $body = '{}';
        $good = self::sign(self::SECRET, 'msg_1', (string) $now, $body);
        $old = self::sign('whsec_' . base64_encode('rotated away'), 'msg_1', (string) $now, $body);

        $this->assertTrue(WebhookReceiver::verify(self::SECRET, 'msg_1', (string) $now, "$old $good", $body, $now));
        $this->assertFalse(WebhookReceiver::verify(self::SECRET, 'msg_1', (string) $now, "$old v2,abc", $body, $now));
    }

    public function test_a_bad_signature_gets_401_and_changes_nothing(): void
    {
        $this->store(Tenant::REQUESTING_SSL);
        Functions\expect('wp_remote_head')->never();

        $response = $this->receiver->handle($this->request('tenant.ready', ['tenant' => ['externalId' => 'ext-900']], 'evt-1', null, 'v1,' . base64_encode('nope')));

        $this->assertSame(401, $response->get_status());
        $this->assertSame(Tenant::REQUESTING_SSL, $this->cart->meta[900][Tenant::TENANT_STATE]);
        $this->assertSame([], $this->transients);
    }

    public function test_ready_takes_the_store_to_ready_through_the_same_steps_as_the_poll(): void
    {
        $this->store(Tenant::PROVISIONING);
        // The payload's own state is not trusted: the store is read from the API again.
        $this->api->expects('get_tenant_safe')->twice()->with('ext-900')->andReturn((object) ['statusCode' => 1, 'domainName' => 'apex.sites.example']);
        Functions\expect('wp_remote_head')->once()->with('https://apex.sites.example', ['redirection' => 0])->andReturn(['response' => ['code' => 200]]);
        Actions\expectDone('waas_tenant_ready')->once()->with(900, 'ext-900', 'apex.sites.example');

        $response = $this->receiver->handle($this->request('tenant.ready', ['tenant' => ['externalId' => 'ext-900', 'statusCode' => 1]]));

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['handled']);
        $this->assertSame(Tenant::READY, $this->cart->meta[900][Tenant::TENANT_STATE]);
        $this->assertSame(Tenant::READY, $this->cart->meta[901][Tenant::TENANT_STATE]);
        $this->assertSame([], $this->scheduled, 'no re-check needed');
        $this->assertGreaterThan(0, $this->options[Webhooks::OPTION]['last_event_at']);
    }

    public function test_a_certificate_not_served_yet_gets_one_quick_recheck(): void
    {
        $this->store(Tenant::REQUESTING_SSL);
        Functions\expect('wp_remote_head')->once()->andReturn(['response' => ['code' => 503]]);
        Actions\expectDone('waas_tenant_ready')->never();

        $this->receiver->handle($this->request('tenant.ready', ['tenant' => ['externalId' => 'ext-900']], 'evt-1'));
        // A second event for the same store doesn't stack another re-check.
        Functions\expect('wp_remote_head')->once()->andReturn(['response' => ['code' => 503]]);
        $this->receiver->handle($this->request('tenant.restored', ['tenant' => ['externalId' => 'ext-900']], 'evt-2'));

        $this->assertSame(Tenant::REQUESTING_SSL, $this->cart->meta[900][Tenant::TENANT_STATE]);
        $this->assertCount(1, $this->scheduled);
        [$timestamp, $hook, $args] = $this->scheduled[0];
        $this->assertSame(TenantStatusService::RECHECK_HOOK, $hook);
        $this->assertSame([900], $args);
        $this->assertEqualsWithDelta(time() + 5, $timestamp, 2);
        // And one in its own request, without waiting for cron.
        $this->assertSame([[TenantStatusService::RECHECK_HOOK, 900, 5]], \WaaSHost\Features\RunSoon::pending());
    }

    public function test_a_repeated_delivery_is_answered_without_acting_again(): void
    {
        $this->store(Tenant::REQUESTING_SSL);
        Functions\expect('wp_remote_head')->once()->andReturn(['response' => ['code' => 200]]);
        Actions\expectDone('waas_tenant_ready')->once();

        $first = $this->receiver->handle($this->request('tenant.ready', ['tenant' => ['externalId' => 'ext-900']], 'evt-7'));
        $this->cart->meta[900][Tenant::TENANT_STATE] = Tenant::REQUESTING_SSL;
        $again = $this->receiver->handle($this->request('tenant.ready', ['tenant' => ['externalId' => 'ext-900']], 'evt-7'));

        $this->assertSame(200, $first->get_status());
        $this->assertSame(200, $again->get_status());
        $this->assertTrue($again->get_data()['duplicate']);
    }

    public function test_events_it_does_not_handle_and_unknown_stores_are_acknowledged_and_ignored(): void
    {
        $this->store(Tenant::READY);
        $this->api->expects('get_tenant_safe')->never();
        Functions\expect('wp_remote_head')->never();

        foreach ([
            ['tenant.backup_completed', ['tenant' => ['externalId' => 'ext-900'], 'backupId' => 'b-1']],
            ['tenant.suspended', ['tenant' => ['externalId' => 'ext-900']]],
            ['webhook.test', ['endpointId' => 'ep-1']],
            ['something.new', []],
            ['tenant.ready', ['tenant' => ['externalId' => 'not-ours']]],
        ] as $i => [$type, $data]) {
            $response = $this->receiver->handle($this->request($type, $data, 'evt-' . $i));
            $this->assertSame(200, $response->get_status(), $type);
            $this->assertFalse($response->get_data()['handled'], $type);
        }
        $this->assertSame(Tenant::READY, $this->cart->meta[900][Tenant::TENANT_STATE]);
    }

    public function test_deleted_shows_the_website_removed_once_the_api_no_longer_has_it(): void
    {
        $this->store(Tenant::READY);
        $this->api->expects('get_tenant_safe')->with('ext-900')->andReturn(null);

        $response = $this->receiver->handle($this->request('tenant.deleted', ['tenant' => ['externalId' => 'ext-900']]));

        $this->assertTrue($response->get_data()['handled']);
        $this->assertSame(Tenant::DELETED, $this->cart->meta[900][Tenant::TENANT_STATE]);
        $this->assertSame(Tenant::READY, $this->cart->meta[901][Tenant::TENANT_STATE]);
    }

    public function test_deleted_for_a_store_the_api_still_has_changes_nothing(): void
    {
        $this->store(Tenant::READY);
        $this->api->expects('get_tenant_safe')->with('ext-900')->andReturn((object) ['statusCode' => 1]);

        $this->receiver->handle($this->request('tenant.deleted', ['tenant' => ['externalId' => 'ext-900']]));

        $this->assertSame(Tenant::READY, $this->cart->meta[900][Tenant::TENANT_STATE]);
    }

    public function test_restored_brings_a_removed_website_back_to_ready(): void
    {
        $this->store(Tenant::DELETED);
        $this->api->allows('get_tenant_safe')->with('ext-900')->andReturn((object) ['statusCode' => 1, 'isSuspended' => false, 'domainName' => 'apex.sites.example']);
        Functions\expect('wp_remote_head')->once()->andReturn(['response' => ['code' => 200]]);
        Actions\expectDone('waas_tenant_ready')->once()->with(900, 'ext-900', 'apex.sites.example');

        $this->receiver->handle($this->request('tenant.restored', ['tenant' => ['externalId' => 'ext-900']]));

        $this->assertSame(Tenant::READY, $this->cart->meta[900][Tenant::TENANT_STATE]);
    }

    public function test_domains_changed_copies_the_apis_address_not_the_payloads(): void
    {
        $this->store(Tenant::READY, 'old.example.com');
        $this->api->expects('get_tenant_safe')->with('ext-900')->andReturn((object) ['domainName' => 'Shop.Example.com']);

        $this->receiver->handle($this->request('tenant.domains_changed', ['tenant' => ['externalId' => 'ext-900', 'domainName' => 'payload.example.com']]));

        $this->assertSame('shop.example.com', $this->cart->meta[900][Tenant::DOMAIN_NAME_META]);
    }

    public function test_domains_changed_for_a_store_still_linking_its_domain_moves_it_on(): void
    {
        $this->store(Tenant::LINKING_DOMAIN);
        $this->api->expects('get_tenant_safe')->with('ext-900')->andReturn((object) ['statusCode' => 1, 'domainName' => 'apex.sites.example']);
        Functions\expect('wp_remote_head')->once()->andReturn(['response' => ['code' => 200]]);

        $this->receiver->handle($this->request('tenant.domains_changed', ['tenant' => ['externalId' => 'ext-900']]));

        $this->assertSame(Tenant::READY, $this->cart->meta[900][Tenant::TENANT_STATE]);
    }

    public function test_failed_is_recorded_on_the_subscription_once(): void
    {
        $this->store(Tenant::LINKING_DOMAIN);
        Actions\expectDone('waas_tenant_failed')->once()->with(900, 'create');

        $this->receiver->handle($this->request('tenant.failed', ['tenant' => ['externalId' => 'ext-900'], 'action' => 'create'], 'evt-1'));
        $this->receiver->handle($this->request('tenant.failed', ['tenant' => ['externalId' => 'ext-900'], 'action' => 'create'], 'evt-2'));

        $failure = $this->cart->meta[900][Webhooks::TENANT_FAILURE_META];
        $this->assertSame('create', $failure['action']);
        $this->assertSame(Tenant::LINKING_DOMAIN, $this->cart->meta[900][Tenant::TENANT_STATE], 'the state is left to the poll');
        $this->assertStringContainsString('"create" failed for the website of subscription 900', (string) file_get_contents($this->log));

        Webhooks::clear_tenant_failure(900);
        $this->assertSame('', $this->cart->meta[900][Webhooks::TENANT_FAILURE_META]);
    }
}

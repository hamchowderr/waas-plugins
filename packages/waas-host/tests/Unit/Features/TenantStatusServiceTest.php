<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\TenantStatusService;
use WaaSHost\Features\Webhooks;

/** The poller that takes a created store to Ready runs every five seconds, so it must skip stores it has nothing to do for. */
class TenantStatusServiceTest extends TestCase
{
    private FakeCommerceAdapter $cart;
    private string $log;
    private $previous_log;
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Commerce::reset();
        Commerce::register($this->cart = new FakeCommerceAdapter());
        Functions\when('wp_next_scheduled')->justReturn(1);
        $this->options = [];
        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
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

    public function test_a_store_waiting_for_a_new_name_while_its_subscription_is_inactive_is_skipped_quietly(): void
    {
        $this->cart->meta[901] = [
            Tenant::TENANT_STATE => '',
            Tenant::NAME_CONFLICT_META => ['name' => 'Apex Research', 'paused' => true],
        ];
        $api = Mockery::mock(ProvisioningApi::class);
        $api->expects('get_tenant_safe')->never();

        (new TenantStatusService($api))->poll_tenant_status();

        $this->assertSame('', (string) file_get_contents($this->log), 'nothing logged every five seconds');
        $this->assertSame('', $this->cart->meta[901][Tenant::TENANT_STATE]);
    }

    public function test_a_live_store_takes_the_address_the_api_has_for_it(): void
    {
        $this->cart->meta[1] = [Tenant::TENANT_STATE => Tenant::READY, Tenant::TENANT_EXTERNAL_ID_META => 'a', Tenant::DOMAIN_NAME_META => 'old.example.com'];
        $this->cart->meta[2] = [Tenant::TENANT_STATE => Tenant::SUSPENDED, Tenant::TENANT_EXTERNAL_ID_META => 'b', Tenant::DOMAIN_NAME_META => 'b.example.com'];
        // Still being created: its address is the one being linked, not the API's yet.
        $this->cart->meta[3] = [Tenant::TENANT_STATE => Tenant::LINKING_DOMAIN, Tenant::TENANT_EXTERNAL_ID_META => 'c', Tenant::DOMAIN_NAME_META => 'c.example.com'];
        $api = Mockery::mock(ProvisioningApi::class);
        $api->expects('get_tenants')->andReturn([
            (object) ['externalId' => 'a', 'domainName' => 'New.Example.com'],
            (object) ['externalId' => 'b', 'domainName' => 'b.example.com'],
            (object) ['externalId' => 'c', 'domainName' => 'base-c.example.com'],
        ]);

        (new TenantStatusService($api))->sync_tenant_domains();

        $this->assertSame('new.example.com', $this->cart->meta[1][Tenant::DOMAIN_NAME_META]);
        $this->assertSame('b.example.com', $this->cart->meta[2][Tenant::DOMAIN_NAME_META]);
        $this->assertSame('c.example.com', $this->cart->meta[3][Tenant::DOMAIN_NAME_META]);
    }

    public function test_no_live_store_means_no_request_and_an_unreachable_api_changes_nothing(): void
    {
        $api = Mockery::mock(ProvisioningApi::class);
        $api->expects('get_tenants')->never();
        (new TenantStatusService($api))->sync_tenant_domains();

        $this->cart->meta[1] = [Tenant::TENANT_STATE => Tenant::READY, Tenant::TENANT_EXTERNAL_ID_META => 'a', Tenant::DOMAIN_NAME_META => 'a.example.com'];
        $down = Mockery::mock(ProvisioningApi::class);
        $down->expects('get_tenants')->andThrow(new \Exception('API down'));
        (new TenantStatusService($down))->sync_tenant_domains();

        $this->assertSame('a.example.com', $this->cart->meta[1][Tenant::DOMAIN_NAME_META]);
    }

    /** Runs the poll the way WP-Cron does, from inside its hook (a test name; the cron event's real name is the plugin's). */
    private function run_poll_from_cron(TenantStatusService $service): void
    {
        Actions\expectDone('status-poll')->once()->whenHappen(fn () => $service->poll_tenant_status());
        do_action('status-poll');
    }

    public function test_once_webhooks_are_registered_the_poll_moves_to_every_minute(): void
    {
        $service = new TenantStatusService(Mockery::mock(ProvisioningApi::class));
        $this->options[Webhooks::OPTION] = ['id' => 'ep-1', 'secret' => 'waasenc:v1:sealed'];
        Functions\when('wp_get_schedule')->justReturn('five_seconds');
        Functions\expect('wp_clear_scheduled_hook')->once()->with('status-poll');
        Functions\expect('wp_schedule_event')->once()->with(Mockery::type('int'), 'every_minute', 'status-poll');

        $this->run_poll_from_cron($service);
    }

    public function test_without_webhooks_the_poll_goes_back_to_every_five_seconds(): void
    {
        $service = new TenantStatusService(Mockery::mock(ProvisioningApi::class));
        $this->options[Webhooks::OPTION] = ['id' => '', 'error' => 'url must be on a public address (HTTP 400)'];
        Functions\when('wp_get_schedule')->justReturn('every_minute');
        Functions\expect('wp_clear_scheduled_hook')->once()->with('status-poll');
        Functions\expect('wp_schedule_event')->once()->with(Mockery::type('int'), 'five_seconds', 'status-poll');

        $this->run_poll_from_cron($service);
    }

    public function test_the_poll_keeps_its_schedule_when_nothing_changed_or_when_called_directly(): void
    {
        $service = new TenantStatusService(Mockery::mock(ProvisioningApi::class));
        Functions\when('wp_get_schedule')->justReturn('five_seconds');
        Functions\expect('wp_clear_scheduled_hook')->never();
        Functions\expect('wp_schedule_event')->never();

        $this->run_poll_from_cron($service);
        $this->options[Webhooks::OPTION] = ['id' => 'ep-1', 'secret' => 'waasenc:v1:sealed'];
        $service->poll_tenant_status();

        $this->assertSame('every_minute', TenantStatusService::poll_recurrence());
    }

    public function test_one_store_can_be_processed_on_demand_and_others_are_left_alone(): void
    {
        $this->cart->meta[1] = [Tenant::TENANT_STATE => Tenant::LINKING_DOMAIN, Tenant::TENANT_EXTERNAL_ID_META => 'a', Tenant::DOMAIN_NAME_META => 'a.example.com'];
        $this->cart->meta[2] = [Tenant::TENANT_STATE => Tenant::LINKING_DOMAIN, Tenant::TENANT_EXTERNAL_ID_META => 'b', Tenant::DOMAIN_NAME_META => 'b.example.com'];
        $this->cart->meta[3] = [Tenant::TENANT_STATE => Tenant::SUSPENDED, Tenant::TENANT_EXTERNAL_ID_META => 'c'];
        $api = Mockery::mock(ProvisioningApi::class);
        $api->expects('get_tenant_safe')->once()->with('a')->andReturn((object) ['statusCode' => 1, 'domainName' => 'a.example.com']);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn ($response) => $response['response']['code']);
        Functions\expect('wp_remote_head')->once()->with('https://a.example.com', ['redirection' => 0])->andReturn(['response' => ['code' => 200]]);
        Actions\expectDone('waas_tenant_ready')->once()->with(1, 'a', 'a.example.com');
        $service = new TenantStatusService($api);

        $this->assertSame(Tenant::READY, $service->process_subscription(1));
        $this->assertSame(Tenant::SUSPENDED, $service->process_subscription(3), 'not on its way to Ready: nothing to do');

        $this->assertSame(Tenant::LINKING_DOMAIN, $this->cart->meta[2][Tenant::TENANT_STATE]);
        $this->assertSame('', (string) file_get_contents($this->log));
    }
}

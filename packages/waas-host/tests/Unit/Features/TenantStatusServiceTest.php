<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Functions;
use Mockery;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\TenantStatusService;

/** The poller that takes a created store to Ready runs every five seconds, so it must skip stores it has nothing to do for. */
class TenantStatusServiceTest extends TestCase
{
    private FakeCommerceAdapter $cart;
    private string $log;
    private $previous_log;

    protected function setUp(): void
    {
        parent::setUp();
        Commerce::reset();
        Commerce::register($this->cart = new FakeCommerceAdapter());
        Functions\when('wp_next_scheduled')->justReturn(1);
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
}

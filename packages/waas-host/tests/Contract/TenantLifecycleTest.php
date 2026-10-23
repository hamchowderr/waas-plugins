<?php

namespace Tests\Contract;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Commerce\PlanProduct;
use WaaSHost\Commerce\PlanPurchase;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\HttpService;
use WaaSHost\Core\PrivateKeyStore;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\TenantsSubscriptionManager;
use WaaSHost\Features\TenantStatusService;

/**
 * Store creation, polling, suspension, resumption and removal through a fake cart, against the provisioning API (see MockApi).
 * Proves the shared code needs no WooCommerce.
 *
 * @group mock
 */
class TenantLifecycleTest extends TestCase
{
    use MockApi;

    private FakeCommerceAdapter $cart;
    private ProvisioningApi $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }
        Commerce::reset();
        $this->use_mock_api([
            'waas_root_domain' => 'stores.waas.example',
            'waas_default_user_role' => 'administrator',
        ]);

        Functions\when('sanitize_text_field')->alias(fn ($value) => trim(strip_tags((string) $value)));
        Functions\when('remove_accents')->returnArg();
        Functions\when('sanitize_title_with_dashes')->alias(fn ($value) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $value)), '-'));
        Functions\when('wp_salt')->justReturn('auth-salt');
        Functions\when('wp_next_scheduled')->justReturn(true);

        $this->cart = new FakeCommerceAdapter();
        Commerce::register($this->cart);
        $this->service = new ProvisioningApi(new HttpService(new ConfigService()));
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    private function purchase(): PlanPurchase
    {
        // The adapter has already left out the non-plan product on the order.
        return new PlanPurchase([new PlanProduct(101, 'peptide-store', 'peptide-starter')], ' Apex Research ', 'owner@apexresearch.example');
    }

    public function test_creates_the_store_and_saves_it_on_the_subscription(): void
    {
        $manager = new TenantsSubscriptionManager($this->service, $jobs = $this->use_store_jobs());
        $this->mock_example('Create tenant: Scheduled (API generated the externalId)');
        Actions\expectDone('waas_tenant_linked_to_subscription')->once()->with('sub-1');

        $manager->create_tenant_when_subscription_created('sub-1', $this->purchase());
        $this->assertSame([], $this->requests, 'the cart event only queues the job');
        $this->run_store_jobs($jobs, 'sub-1');

        $sent = json_decode($this->requests[0][3], true);
        $this->assertSame('Apex Research', $sent['name']);
        $this->assertSame('jordan_reyes', $sent['wordPressUserName']);
        $this->assertSame('owner@apexresearch.example', $sent['wordPressUserEmail']);
        $this->assertSame('apex-research.stores.waas.example', $sent['customDomainName']);
        $this->assertSame('peptide-starter', $sent['groupName']);
        $this->assertSame('peptide-store', $sent['phpConstants']['WAAS_TENANT_ROLES']['value']);
        // Stores on an older WaaS Client read the old name.
        $this->assertSame('peptide-store', $sent['phpConstants']['WPCS_TENANT_ROLES']['value']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $sent['externalId'], 'chosen up front, so a retry cannot make a second store');

        $meta = $this->cart->meta['sub-1'];
        $this->assertSame('store-1042', $meta[Tenant::TENANT_EXTERNAL_ID_META]);
        $this->assertSame('apex-research.stores.waas.example', $meta[Tenant::DOMAIN_NAME_META]);
        $this->assertSame('t-7c1e2a9b.stores.waas.example', $meta[Tenant::BASE_DOMAIN_NAME_META]);
        $this->assertSame(['peptide-store'], $meta[Tenant::SUBSCRIPTION_USER_ROLES]);
        // The private key is stored encrypted, in the cart's subscription meta.
        $this->assertStringStartsWith(PrivateKeyStore::PREFIX, $meta[Tenant::TENANT_PRIVATE_KEY_META]);
        $this->assertStringContainsString('PRIVATE KEY', PrivateKeyStore::load('sub-1'));
    }

    public function test_does_not_create_a_second_store_for_the_same_subscription(): void
    {
        $manager = new TenantsSubscriptionManager($this->service, $jobs = $this->use_store_jobs());
        $this->cart->meta['sub-1'][Tenant::TENANT_EXTERNAL_ID_META] = 'store-1042';

        $manager->create_tenant_when_subscription_created('sub-1', $this->purchase());
        $this->run_store_jobs($jobs, 'sub-1');

        $this->assertSame([], $this->requests);
    }

    public function test_purchase_without_a_plan_product_creates_nothing(): void
    {
        $manager = new TenantsSubscriptionManager($this->service, $jobs = $this->use_store_jobs());

        $manager->create_tenant_when_subscription_created('sub-1', new PlanPurchase([], 'Apex Research', 'owner@apexresearch.example'));
        $this->run_store_jobs($jobs, 'sub-1');

        $this->assertSame([], $this->requests);
    }

    public function test_poller_moves_a_ready_tenant_on_to_the_ssl_check(): void
    {
        $poller = new TenantStatusService($this->service);
        $this->cart->meta['sub-1'] = [
            Tenant::TENANT_STATE => Tenant::PROVISIONING,
            Tenant::TENANT_EXTERNAL_ID_META => 'store-1042',
            Tenant::DOMAIN_NAME_META => 'apex-research.stores.waas.example',
        ];
        $this->cart->meta['sub-2'] = [Tenant::TENANT_STATE => Tenant::READY];
        $this->mock_example('List tenants: Ready (statusCode 1, domain linked)');

        $poller->poll_tenant_status();

        // The example domain doesn't resolve, so the HEAD check fails and it waits there.
        $this->assertSame(Tenant::REQUESTING_SSL, $this->cart->meta['sub-1'][Tenant::TENANT_STATE]);
        $this->assertSame(Tenant::READY, $this->cart->meta['sub-2'][Tenant::TENANT_STATE]);
        $this->assertSame(['GET', 'GET', 'HEAD'], array_column($this->requests, 0));
    }

    public function test_poller_does_nothing_without_a_cart(): void
    {
        $poller = new TenantStatusService($this->service);
        Commerce::reset();

        $poller->poll_tenant_status();

        $this->assertSame([], $this->requests);
    }

    public function test_cancelling_backs_up_and_suspends_the_store_by_external_id(): void
    {
        $manager = new TenantsSubscriptionManager($this->service, $jobs = $this->use_store_jobs());
        $this->cart->meta['sub-1'][Tenant::TENANT_EXTERNAL_ID_META] = 'store-1042';
        $this->cart->meta['sub-1'][Tenant::TENANT_STATE] = Tenant::READY;
        Functions\expect('wp_schedule_single_event')->once()
            ->with(Mockery::type('int'), TenantsSubscriptionManager::DELETE_SUSPENDED_TENANT_HOOK, ['sub-1'])->andReturn(true);
        $this->mock_example('Suspend tenant: Scheduled with backup');

        $manager->suspend_tenant_when_subscription_expired('sub-1');
        $this->assertSame([], $this->requests, 'the cart event only queues the job');
        // The deletion is planned when the cart event comes in; the job passes that date on to the notification.
        $delete_at = $this->cart->meta['sub-1'][Tenant::TENANT_DELETE_AT_META];
        Actions\expectDone('waas_tenant_suspended')->once()->with('sub-1', $delete_at);
        $this->run_store_jobs($jobs, 'sub-1');

        $this->assertCount(1, $this->requests);
        $this->assertSame('POST', $this->requests[0][0]);
        $this->assertStringEndsWith('/v1/tenants/suspend?externalId=store-1042', $this->requests[0][1]);
        $this->assertSame(['takeBackup' => true], json_decode($this->requests[0][3], true), 'a cancel keeps a backup to restore from');

        $meta = $this->cart->meta['sub-1'];
        $this->assertSame(Tenant::SUSPENDED, $meta[Tenant::TENANT_STATE]);
        $this->assertSame(Tenant::SUSPENDED_ENDED, $meta[Tenant::TENANT_SUSPEND_REASON_META]);
        $this->assertEqualsWithDelta(time() + 30 * DAY_IN_SECONDS, $delete_at, 60, 'deleted after the default 30-day grace period');
    }

    public function test_reactivating_in_the_grace_period_resumes_the_store_and_cancels_its_deletion(): void
    {
        $manager = new TenantsSubscriptionManager($this->service, $jobs = $this->use_store_jobs());
        $this->cart->meta['sub-1'] = [
            Tenant::TENANT_EXTERNAL_ID_META => 'store-1042',
            Tenant::TENANT_STATE => Tenant::SUSPENDED,
            Tenant::TENANT_SUSPEND_REASON_META => Tenant::SUSPENDED_ENDED,
            Tenant::TENANT_DELETE_AT_META => time() + 20 * DAY_IN_SECONDS,
        ];
        Functions\expect('wp_clear_scheduled_hook')->once()->with(TenantsSubscriptionManager::DELETE_SUSPENDED_TENANT_HOOK, ['sub-1']);
        $this->mock_example('Resume tenant: Scheduled');
        Actions\expectDone('waas_tenant_resumed')->once()->with('sub-1');

        $manager->resume_suspended_tenant('sub-1');
        $this->assertSame([], $this->requests, 'the cart event only queues the job');
        $this->run_store_jobs($jobs, 'sub-1');

        $this->assertCount(1, $this->requests);
        $this->assertSame('POST', $this->requests[0][0]);
        $this->assertStringEndsWith('/v1/tenants/resume?externalId=store-1042', $this->requests[0][1]);
        $this->assertSame([], json_decode($this->requests[0][3], true), 'a resume takes no options');

        // Back online at once: the API brings the store back from the suspension, not from a backup.
        $meta = $this->cart->meta['sub-1'];
        $this->assertSame(Tenant::READY, $meta[Tenant::TENANT_STATE]);
        $this->assertSame('', $meta[Tenant::TENANT_DELETE_AT_META]);
        $this->assertSame('', $meta[Tenant::TENANT_SUSPEND_REASON_META]);
    }

    public function test_deletion_after_the_grace_period_deletes_the_store_by_external_id(): void
    {
        $manager = new TenantsSubscriptionManager($this->service, $jobs = $this->use_store_jobs());
        $this->cart->meta['sub-1'][Tenant::TENANT_EXTERNAL_ID_META] = 'store-1042';
        $this->cart->meta['sub-1'][Tenant::TENANT_STATE] = Tenant::SUSPENDED;
        $this->mock_example('Delete tenant: Scheduled');

        $manager->delete_suspended_tenant('sub-1');
        $this->run_store_jobs($jobs, 'sub-1');

        $this->assertSame('DELETE', $this->requests[0][0]);
        $this->assertStringEndsWith('/v1/tenants?externalId=store-1042', $this->requests[0][1]);
        $this->assertSame(Tenant::DELETED, $this->cart->meta['sub-1'][Tenant::TENANT_STATE]);
    }
}

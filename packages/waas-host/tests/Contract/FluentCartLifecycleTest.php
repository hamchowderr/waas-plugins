<?php

namespace Tests\Contract;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Commerce\FluentCart\FluentCartAdapter;
use WaaSHost\Commerce\FluentCart\FluentCartStore;
use WaaSHost\Commerce\FluentCart\WaasProvisioningIntegration;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\HttpService;
use WaaSHost\Core\PrivateKeyStore;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\StoreJobs;
use WaaSHost\Features\TenantsSubscriptionManager;

/**
 * A FluentCart subscription from feed run to store creation, suspension, resumption and removal, against the
 * provisioning API (see MockApi). FluentCart's tables are an in-memory store mock.
 *
 * @group mock
 */
class FluentCartLifecycleTest extends TestCase
{
    use MockApi;

    private const SUBSCRIPTION_ID = 900;

    /** @var array<int, array<string, mixed>> fct_subscription_meta */
    private array $meta = [];
    private bool $canceled = false;
    /** The subscription's next billing date as a unix time; 0 = nothing left of the paid period. */
    private int $paid_through = 0;
    /** wp_schedule_single_event calls: [timestamp, hook, args]. */
    private array $scheduled = [];
    private $order;
    private WaasProvisioningIntegration $integration;
    private TenantsSubscriptionManager $manager;
    private StoreJobs $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }
        Commerce::reset();
        $this->use_mock_api([
            'wpcs_host_settings_root_domain' => 'stores.waas.example',
            'wpcs_host_settings_default_user_role' => 'administrator',
        ]);
        Functions\stubTranslationFunctions();
        Functions\when('sanitize_text_field')->alias(fn ($value) => trim(strip_tags((string) $value)));
        Functions\when('remove_accents')->returnArg();
        Functions\when('sanitize_title_with_dashes')->alias(fn ($value) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $value)), '-'));
        Functions\when('wp_salt')->justReturn('auth-salt');
        Functions\when('is_plugin_active')->alias(fn ($plugin) => $plugin === 'fluent-cart/fluent-cart.php');

        $this->order = Mockery::mock();
        $this->order->id = 500;
        $this->order->allows('addLog');

        $store = Mockery::mock(FluentCartStore::class);
        $store->allows('get_subscription_meta')->andReturnUsing(fn ($id, $key) => $this->meta[$id][$key] ?? '');
        $store->allows('update_subscription_meta')->andReturnUsing(function ($id, $key, $value) {
            $this->meta[$id][$key] = $value;
        });
        $store->allows('get_waas_state')->andReturnUsing(fn ($id) => array_intersect_key($this->meta[$id] ?? [], array_flip(FluentCartStore::WAAS_STATE_META)));
        $store->allows('get_order_product_ids')->with($this->order)->andReturn([101, 102]);
        $store->allows('get_plan_feed')->with(101)->andReturn(['enabled' => 'yes', 'role' => 'peptide-store', 'group_name' => 'peptide-starter']);
        $store->allows('get_plan_feed')->with(102)->andReturnNull();
        $store->allows('get_order_meta')->with($this->order, Tenant::WEBSITE_NAME_META)->andReturn('Apex Research');
        $store->allows('get_order_billing')->with($this->order)->andReturn([]);
        // The feeds run after FluentCart changed the subscription; a cancel finds it canceled.
        $store->allows('get_subscription_status')->andReturnUsing(fn () => $this->canceled ? 'canceled' : 'active');
        $store->allows('get_paid_through')->andReturnUsing(fn () => $this->paid_through);
        Functions\when('wp_schedule_single_event')->alias(function ($timestamp, $hook, $args = []) {
            $this->scheduled[] = [$timestamp, $hook, $args];
            return true;
        });
        Functions\when('wp_clear_scheduled_hook')->justReturn(0);
        $store->allows('get_subscription_customer')->andReturn(['user_id' => 7, 'email' => 'owner@apexresearch.example', 'name' => 'Jordan Reyes']);

        Commerce::register(new FluentCartAdapter($store));
        $service = new ProvisioningApi(new HttpService(new ConfigService()));
        $this->jobs = $this->use_store_jobs();
        $this->manager = new TenantsSubscriptionManager($service, $this->jobs);
        $this->integration = new WaasProvisioningIntegration($service, $store);

        // Wire the actions the integration fires to the shared code, as the manager's own add_action calls do.
        Actions\expectDone('waas_subscription_created')->zeroOrMoreTimes()
            ->whenHappen(fn ($id, $order) => $this->manager->create_tenant_when_subscription_created($id, $order));
        Actions\expectDone('waas_subscription_expired')->zeroOrMoreTimes()
            ->whenHappen(fn ($id) => $this->manager->suspend_tenant_when_subscription_expired($id));
        Actions\expectDone('waas_subscription_canceled_until')->zeroOrMoreTimes()
            ->whenHappen(fn ($id, $until) => $this->manager->suspend_at_end_of_paid_period($id, $until));
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    private function run_feed(string $trigger, string $is_revoke = 'no'): void
    {
        $this->canceled = in_array($trigger, ['subscription_canceled', 'subscription_eot', 'subscription_expired_validity'], true);
        $this->integration->processAction($this->order, [
            'trigger' => $trigger,
            'is_revoke_hook' => $is_revoke,
            'event_data' => ['subscription' => (object) ['id' => self::SUBSCRIPTION_ID]],
        ]);
        // The feed only queued the API work; run it as Action Scheduler would.
        $this->run_store_jobs($this->jobs, self::SUBSCRIPTION_ID);
    }

    public function test_activation_creates_the_store_from_the_product_feed(): void
    {
        $this->mock_example('Create tenant: Scheduled (API generated the externalId)');

        $this->run_feed('subscription_activated');

        $sent = json_decode($this->requests[0][3], true);
        $this->assertSame('Apex Research', $sent['name']);
        $this->assertSame('jordan_reyes', $sent['wordPressUserName']);
        $this->assertSame('owner@apexresearch.example', $sent['wordPressUserEmail']);
        $this->assertSame('apex-research.stores.waas.example', $sent['customDomainName']);
        $this->assertSame('peptide-starter', $sent['groupName']);
        $this->assertSame('peptide-store', $sent['phpConstants']['WPCS_TENANT_ROLES']['value']);

        $meta = $this->meta[self::SUBSCRIPTION_ID];
        $this->assertSame('store-1042', $meta[Tenant::TENANT_EXTERNAL_ID_META]);
        $this->assertSame('apex-research.stores.waas.example', $meta[Tenant::DOMAIN_NAME_META]);
        $this->assertStringContainsString('PRIVATE KEY', PrivateKeyStore::load(self::SUBSCRIPTION_ID));
    }

    public function test_a_second_activation_does_not_create_another_store(): void
    {
        $this->meta[self::SUBSCRIPTION_ID][Tenant::TENANT_EXTERNAL_ID_META] = 'store-1042';

        $this->run_feed('subscription_reactivated');

        $this->assertSame([], $this->requests);
    }

    public function test_cancel_backs_up_and_suspends_the_store_then_deletes_it_after_the_grace_period(): void
    {
        $this->meta[self::SUBSCRIPTION_ID][Tenant::TENANT_EXTERNAL_ID_META] = 'store-1042';
        $this->meta[self::SUBSCRIPTION_ID][Tenant::TENANT_STATE] = Tenant::READY;
        $this->mock_example('Suspend tenant: Scheduled with backup');

        $this->run_feed('subscription_canceled');

        $this->assertCount(1, $this->requests);
        $this->assertSame('POST', $this->requests[0][0]);
        $this->assertStringEndsWith('/v1/tenants/suspend?externalId=store-1042', $this->requests[0][1]);
        $this->assertSame(['takeBackup' => true], json_decode($this->requests[0][3], true));
        $meta = $this->meta[self::SUBSCRIPTION_ID];
        $this->assertSame(Tenant::SUSPENDED, $meta[Tenant::TENANT_STATE]);
        $this->assertSame(Tenant::SUSPENDED_ENDED, $meta[Tenant::TENANT_SUSPEND_REASON_META]);
        $this->assertSame([[$meta[Tenant::TENANT_DELETE_AT_META], TenantsSubscriptionManager::DELETE_SUSPENDED_TENANT_HOOK, [self::SUBSCRIPTION_ID]]], $this->scheduled);

        // The grace period is over: WP-cron fires the deletion it scheduled.
        $this->mock_example('Delete tenant: Scheduled');
        $this->manager->delete_suspended_tenant(self::SUBSCRIPTION_ID);
        $this->run_store_jobs($this->jobs, self::SUBSCRIPTION_ID);

        $this->assertSame('DELETE', $this->requests[1][0]);
        $this->assertStringEndsWith('/v1/tenants?externalId=store-1042', $this->requests[1][1]);
        $this->assertSame(Tenant::DELETED, $this->meta[self::SUBSCRIPTION_ID][Tenant::TENANT_STATE]);
    }

    public function test_reactivation_in_the_grace_period_resumes_the_suspended_store(): void
    {
        $this->meta[self::SUBSCRIPTION_ID] = [
            Tenant::TENANT_EXTERNAL_ID_META => 'store-1042',
            Tenant::TENANT_STATE => Tenant::SUSPENDED,
            Tenant::TENANT_SUSPEND_REASON_META => Tenant::SUSPENDED_ENDED,
            Tenant::TENANT_DELETE_AT_META => time() + 20 * DAY_IN_SECONDS,
        ];
        $this->mock_example('Resume tenant: Scheduled');

        // A late payment: FluentCart's subscription_reactivated is a create trigger, which resumes a suspended store.
        $this->run_feed('subscription_reactivated');

        $this->assertCount(1, $this->requests, 'the store is resumed, not created again');
        $this->assertSame('POST', $this->requests[0][0]);
        $this->assertStringEndsWith('/v1/tenants/resume?externalId=store-1042', $this->requests[0][1]);
        $meta = $this->meta[self::SUBSCRIPTION_ID];
        $this->assertSame(Tenant::READY, $meta[Tenant::TENANT_STATE]);
        $this->assertSame('', $meta[Tenant::TENANT_DELETE_AT_META]);
        $this->assertSame('', $meta[Tenant::TENANT_SUSPEND_REASON_META]);
    }

    public function test_a_cancel_with_paid_time_left_waits_for_the_end_of_the_period(): void
    {
        $this->meta[self::SUBSCRIPTION_ID][Tenant::TENANT_EXTERNAL_ID_META] = 'store-1042';
        $this->meta[self::SUBSCRIPTION_ID][Tenant::TENANT_STATE] = Tenant::READY;
        $this->paid_through = time() + 30 * 86400;

        $this->run_feed('subscription_canceled');

        $this->assertSame([], $this->requests, 'nothing reaches the API before the end of the paid period');
        $this->assertSame(Tenant::READY, $this->meta[self::SUBSCRIPTION_ID][Tenant::TENANT_STATE]);
        $this->assertSame($this->paid_through, $this->meta[self::SUBSCRIPTION_ID][Tenant::TENANT_SUSPEND_AT_META]);
        $this->assertSame([[$this->paid_through, TenantsSubscriptionManager::SUSPEND_AT_PERIOD_END_HOOK, [self::SUBSCRIPTION_ID]]], $this->scheduled);

        // The end of the paid period, with the subscription still cancelled: the usual cancel path.
        $this->manager->paid_period_ended(self::SUBSCRIPTION_ID);

        $this->assertSame(Tenant::SUSPENDED, $this->meta[self::SUBSCRIPTION_ID][Tenant::TENANT_STATE]);
        $queue = $this->meta[self::SUBSCRIPTION_ID][StoreJobs::QUEUE_META];
        $this->assertSame(['suspend'], array_column($queue, 'op'));
        $this->assertTrue($queue[0]['args']['backup']);
        $this->assertSame(TenantsSubscriptionManager::DELETE_SUSPENDED_TENANT_HOOK, end($this->scheduled)[1]);
        $this->assertSame([], $this->requests);

        $this->mock_example('Suspend tenant: Scheduled with backup');
        $this->run_store_jobs($this->jobs, self::SUBSCRIPTION_ID);

        $this->assertSame('POST', $this->requests[0][0]);
        $this->assertStringEndsWith('/v1/tenants/suspend?externalId=store-1042', $this->requests[0][1]);
        $this->assertSame(['takeBackup' => true], json_decode($this->requests[0][3], true));
    }
}

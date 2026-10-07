<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Commerce\PlanPurchase;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\TenantsSubscriptionManager;

/**
 * Cancel -> suspend and back up; delete after the grace period; resume if the subscription comes back first.
 */
class TenantsSubscriptionManagerTest extends TestCase
{
    private const HOOK = TenantsSubscriptionManager::DELETE_SUSPENDED_TENANT_HOOK;

    private FakeCommerceAdapter $cart;
    private $wpcs;
    private TenantsSubscriptionManager $manager;
    private array $options = [];
    /** wp-cron single events: [timestamp, hook, args] */
    private array $scheduled = [];

    protected function setUp(): void
    {
        parent::setUp();
        Commerce::reset();
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }

        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('absint')->alias(fn ($value) => abs((int) $value));
        Functions\when('wp_schedule_single_event')->alias(function ($timestamp, $hook, $args = []) {
            $this->scheduled[] = [$timestamp, $hook, $args];
            return true;
        });
        Functions\when('wp_clear_scheduled_hook')->alias(function ($hook, $args = []) {
            $this->scheduled = array_values(array_filter($this->scheduled, fn ($e) => !($e[1] === $hook && $e[2] === $args)));
            return 1;
        });

        $this->cart = new FakeCommerceAdapter();
        Commerce::register($this->cart);
        $this->wpcs = Mockery::mock(WPCSService::class);
        $this->manager = new TenantsSubscriptionManager($this->wpcs);

        $this->cart->meta[900] = [
            WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META => 'store-1042',
            WPCSTenant::WPCS_TENANT_STATE => WPCSTenant::READY,
        ];
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    private function state(): string
    {
        return $this->cart->meta[900][WPCSTenant::WPCS_TENANT_STATE];
    }

    public function test_cancelling_suspends_with_a_backup_and_schedules_deletion_after_30_days(): void
    {
        $this->wpcs->expects('suspend_tenant')->with('store-1042', true);
        $this->wpcs->expects('delete_tenant')->never();
        Actions\expectDone('wpcs_tenant_suspended')->once();

        $before = time();
        $this->manager->suspend_tenant_when_subscription_expired(900);

        $this->assertSame(WPCSTenant::SUSPENDED, $this->state());
        $delete_at = $this->cart->meta[900][WPCSTenant::WPCS_TENANT_DELETE_AT_META];
        $this->assertGreaterThanOrEqual($before + 30 * DAY_IN_SECONDS, $delete_at);
        $this->assertLessThanOrEqual(time() + 30 * DAY_IN_SECONDS, $delete_at);
        $this->assertSame([[$delete_at, self::HOOK, [900]]], $this->scheduled);
    }

    public function test_grace_period_comes_from_the_setting(): void
    {
        $this->options[TenantsSubscriptionManager::DELETION_GRACE_DAYS_OPTION] = '7';
        $this->wpcs->allows('suspend_tenant');

        $this->manager->suspend_tenant_when_subscription_expired(900);

        $this->assertEqualsWithDelta(time() + 7 * DAY_IN_SECONDS, $this->scheduled[0][0], 5);
    }

    public function test_grace_period_is_at_least_one_day(): void
    {
        $this->options[TenantsSubscriptionManager::DELETION_GRACE_DAYS_OPTION] = '0';

        $this->assertSame(1, TenantsSubscriptionManager::get_deletion_grace_days());
    }

    public function test_a_repeated_cancellation_does_nothing(): void
    {
        // FluentCart reports subscription_canceled and later subscription_eot for the same subscription.
        $this->wpcs->expects('suspend_tenant')->once();

        $this->manager->suspend_tenant_when_subscription_expired(900);
        $this->manager->suspend_tenant_when_subscription_expired(900);

        $this->assertCount(1, $this->scheduled);
    }

    public function test_cancelling_a_subscription_without_a_store_does_nothing(): void
    {
        $this->wpcs->expects('suspend_tenant')->never();

        $this->manager->suspend_tenant_when_subscription_expired(901);

        $this->assertSame([], $this->scheduled);
    }

    public function test_deletion_after_the_grace_period_deletes_the_suspended_store(): void
    {
        $this->wpcs->allows('suspend_tenant');
        $this->manager->suspend_tenant_when_subscription_expired(900);
        $this->wpcs->expects('delete_tenant')->with(['external_id' => 'store-1042']);
        Actions\expectDone('wpcs_tenant_deleted')->once()->with(900);

        $this->manager->delete_suspended_tenant(900);

        $this->assertSame(WPCSTenant::DELETED, $this->state());
        $this->assertSame('', $this->cart->meta[900][WPCSTenant::WPCS_TENANT_DELETE_AT_META]);
    }

    public function test_reactivating_within_the_grace_period_resumes_and_cancels_the_deletion(): void
    {
        $this->wpcs->allows('suspend_tenant');
        $this->manager->suspend_tenant_when_subscription_expired(900);
        $this->wpcs->expects('resume_tenant')->with('store-1042');
        $this->wpcs->expects('create_tenant')->never();
        Actions\expectDone('wpcs_tenant_resumed')->once()->with(900);

        // Both carts fire wpcs_subscription_created when a subscription becomes active again.
        $this->manager->create_tenant_when_subscription_created(900, new PlanPurchase([], 'Apex Research', 'owner@apexresearch.example'));

        $this->assertSame(WPCSTenant::READY, $this->state());
        $this->assertSame([], $this->scheduled);
        $this->assertSame('', $this->cart->meta[900][WPCSTenant::WPCS_TENANT_DELETE_AT_META]);
    }

    public function test_a_deletion_event_left_over_after_a_resume_does_nothing(): void
    {
        $this->wpcs->allows('suspend_tenant');
        $this->wpcs->allows('resume_tenant');
        $this->manager->suspend_tenant_when_subscription_expired(900);
        $this->manager->resume_suspended_tenant(900);
        $this->wpcs->expects('delete_tenant')->never();

        $this->manager->delete_suspended_tenant(900);

        $this->assertSame(WPCSTenant::READY, $this->state());
    }

    public function test_activation_of_a_running_store_does_not_resume_it(): void
    {
        $this->wpcs->expects('resume_tenant')->never();
        $this->wpcs->expects('create_tenant')->never();

        $this->manager->create_tenant_when_subscription_created(900, new PlanPurchase([], 'Apex Research', 'owner@apexresearch.example'));

        $this->assertSame(WPCSTenant::READY, $this->state());
    }

    public function test_a_failed_suspend_leaves_the_store_as_it_was_so_the_cart_can_retry(): void
    {
        $this->wpcs->expects('suspend_tenant')->andThrow(new \Exception('API down'));

        try {
            $this->manager->suspend_tenant_when_subscription_expired(900);
            $this->fail('expected the API error to propagate');
        } catch (\Exception $e) {
            $this->assertSame('API down', $e->getMessage());
        }

        $this->assertSame(WPCSTenant::READY, $this->state());
        $this->assertSame([], $this->scheduled);
    }
}

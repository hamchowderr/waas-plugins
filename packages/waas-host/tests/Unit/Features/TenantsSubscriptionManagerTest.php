<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Commerce\PlanProduct;
use WaaSHost\Commerce\PlanPurchase;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\TenantsSubscriptionManager;

/**
 * Cancel -> suspend and back up; delete after the grace period; resume if the subscription comes back first;
 * after deletion, restore the newest backup or create a fresh store. On hold -> suspend only.
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

    public function test_on_hold_takes_the_store_offline_without_backup_or_deletion(): void
    {
        $this->wpcs->expects('suspend_tenant')->with('store-1042', false);
        Actions\expectDone('wpcs_tenant_suspended')->once()->with(900, 0);

        $this->manager->suspend_tenant_on_hold(900);

        $this->assertSame(WPCSTenant::SUSPENDED, $this->state());
        $this->assertSame([], $this->scheduled);
    }

    public function test_cancelling_a_store_on_hold_backs_it_up_and_starts_the_grace_period(): void
    {
        $this->wpcs->expects('suspend_tenant')->once()->with('store-1042', false);
        $this->wpcs->expects('create_tenant_backup')->once()->with('store-1042');
        $this->manager->suspend_tenant_on_hold(900);

        $this->manager->suspend_tenant_when_subscription_expired(900);
        $this->manager->suspend_tenant_when_subscription_expired(900);

        $this->assertSame(WPCSTenant::SUSPENDED, $this->state());
        $this->assertCount(1, $this->scheduled);
        $this->assertSame(self::HOOK, $this->scheduled[0][1]);
    }

    public function test_reactivating_after_a_hold_resumes_the_store(): void
    {
        $this->wpcs->allows('suspend_tenant');
        $this->manager->suspend_tenant_on_hold(900);
        $this->wpcs->expects('resume_tenant')->with('store-1042');

        $this->manager->resume_suspended_tenant(900);

        $this->assertSame(WPCSTenant::READY, $this->state());
    }

    private function delete_store(): void
    {
        $this->wpcs->allows('suspend_tenant');
        $this->wpcs->allows('delete_tenant');
        $this->manager->suspend_tenant_when_subscription_expired(900);
        $this->manager->delete_suspended_tenant(900);
        $this->scheduled = [];
    }

    private function purchase(): PlanPurchase
    {
        return new PlanPurchase([new PlanProduct(101, 'peptide-store', 'peptide-starter')], 'Apex Research', 'owner@apexresearch.example');
    }

    public function test_reactivating_after_deletion_restores_the_newest_finished_backup(): void
    {
        $this->delete_store();
        $this->wpcs->expects('get_tenant_backups')->with('store-1042')->andReturn([
            (object) ['id' => 'bk-pending', 'statusCode' => 0],
            (object) ['id' => 'bk-newest-done', 'statusCode' => 1],
            (object) ['id' => 'bk-older', 'statusCode' => 1],
        ]);
        $this->wpcs->expects('restore_tenant')->with('store-1042', 'bk-newest-done');
        $this->wpcs->expects('create_tenant')->never();
        Actions\expectDone('wpcs_tenant_linked_to_subscription')->once()->with(900);
        Actions\expectDone('wpcs_tenant_restored')->once()->with(900, 'bk-newest-done');

        $this->manager->create_tenant_when_subscription_created(900, $this->purchase());

        $this->assertSame('store-1042', $this->cart->meta[900][WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META]);
    }

    public function test_reactivating_after_deletion_without_a_backup_creates_a_fresh_store(): void
    {
        $this->delete_store();
        $this->options['wpcs_host_settings_root_domain'] = 'stores.waas.example';
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('remove_accents')->returnArg();
        Functions\when('sanitize_title_with_dashes')->alias(fn ($v) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $v)), '-'));
        Functions\when('wp_salt')->justReturn('auth-salt');
        Functions\when('apply_filters')->returnArg(2);
        $this->wpcs->expects('get_tenant_backups')->with('store-1042')->andReturn([]);
        $this->wpcs->expects('restore_tenant')->never();
        $this->wpcs->expects('create_tenant')->once()->andReturn((object) [
            'externalId' => 'store-2001',
            'baseDomain' => 't-2001.stores.waas.example',
            'customDomain' => 'apex-research.stores.waas.example',
        ]);

        $this->manager->create_tenant_when_subscription_created(900, $this->purchase());

        $meta = $this->cart->meta[900];
        $this->assertSame('store-2001', $meta[WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META]);
        $this->assertSame('t-2001.stores.waas.example', $meta[WPCSTenant::WPCS_BASE_DOMAIN_NAME_META]);
        $this->assertSame('apex-research.stores.waas.example', $meta[WPCSTenant::WPCS_DOMAIN_NAME_META]);
    }

    public function test_an_error_listing_backups_never_creates_a_second_store(): void
    {
        $this->delete_store();
        $this->wpcs->expects('get_tenant_backups')->andThrow(new \Exception('API down'));
        $this->wpcs->expects('create_tenant')->never();

        try {
            $this->manager->create_tenant_when_subscription_created(900, $this->purchase());
            $this->fail('expected the API error to propagate');
        } catch (\Exception $e) {
            $this->assertSame('API down', $e->getMessage());
        }

        $this->assertSame('store-1042', $this->cart->meta[900][WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META]);
        $this->assertSame(WPCSTenant::DELETED, $this->state());
    }
}

<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use WaaSHost\Commerce\PlanProduct;
use WaaSHost\Commerce\PlanPurchase;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\NameConflicts;
use WaaSHost\Features\StoreJobs;
use WaaSHost\Features\TenantsSubscriptionManager;

/**
 * Cancel -> suspend and back up; delete after the grace period; resume if the subscription comes back first;
 * after deletion, restore the newest backup or create a fresh store. On hold -> suspend only.
 *
 * The cart events only queue work: every API call happens when the store's jobs run (run_due).
 */
class TenantsSubscriptionManagerTest extends StoreJobsTestCase
{
    private const HOOK = TenantsSubscriptionManager::DELETE_SUSPENDED_TENANT_HOOK;

    private $api;
    private TenantsSubscriptionManager $manager;
    private NameConflicts $name_conflicts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = Mockery::mock(ProvisioningApi::class);
        $this->name_conflicts = new NameConflicts($this->api, $this->jobs);
        $this->manager = new TenantsSubscriptionManager($this->api, $this->jobs, $this->name_conflicts);

        $this->cart->meta[900] = [
            Tenant::TENANT_EXTERNAL_ID_META => 'store-1042',
            Tenant::TENANT_STATE => Tenant::READY,
        ];
    }

    private function state($subscription_id = 900): string
    {
        return $this->cart->meta[$subscription_id][Tenant::TENANT_STATE];
    }

    private function purchase(): PlanPurchase
    {
        return new PlanPurchase([new PlanProduct(101, 'peptide-store', 'peptide-starter')], 'Apex Research', 'owner@apexresearch.example', 'us');
    }

    private function stub_new_store_functions(): void
    {
        $this->options['wpcs_host_settings_root_domain'] = 'stores.waas.example';
        $this->options[Tenant::DEFAULT_USER_ROLE] = 'administrator';
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('remove_accents')->returnArg();
        Functions\when('sanitize_title_with_dashes')->alias(fn ($v) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $v)), '-'));
        Functions\when('wp_salt')->justReturn('auth-salt');
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('wp_generate_uuid4')->justReturn('5f0b7c3e-2d1a-4e8b-9c6f-0a1b2c3d4e5f');
    }

    public function test_activation_only_queues_the_store_creation(): void
    {
        $this->stub_new_store_functions();
        $this->api->expects('create_tenant')->never();

        $this->manager->create_tenant_when_subscription_created(901, $this->purchase());

        $this->assertSame(Tenant::QUEUED, $this->state(901));
        $this->assertSame('create', $this->queue(901)[0]['op']);
        $this->assertSame('5f0b7c3e-2d1a-4e8b-9c6f-0a1b2c3d4e5f', $this->queue(901)[0]['args']['external_id']);
        $this->assertCount(1, $this->actions);
    }

    public function test_the_plan_size_goes_into_the_create_request(): void
    {
        $this->stub_new_store_functions();
        $purchase = new PlanPurchase([new PlanProduct(101, 'online-store', '', 'medium')], 'Apex Research', 'owner@apexresearch.example', 'us');

        $this->manager->create_tenant_when_subscription_created(901, $purchase);

        $this->assertSame('medium', $this->queue(901)[0]['args']['request']['size']);
    }

    public function test_the_create_job_sends_the_chosen_external_id_and_links_the_store(): void
    {
        $this->stub_new_store_functions();
        $this->manager->create_tenant_when_subscription_created(901, $this->purchase());
        $this->api->expects('create_tenant')->once()->with(Mockery::on(function ($args) {
            return $args['external_id'] === '5f0b7c3e-2d1a-4e8b-9c6f-0a1b2c3d4e5f'
                && $args['name'] === 'Apex Research'
                && $args['custom_domain_name'] === 'apex-research.stores.waas.example'
                && $args['group_name'] === 'peptide-starter'
                && $args['size'] === null
                && $args['wordpress_user_role'] === 'administrator'
                && $args['php_constants']['WPCS_TENANT_ROLES']['value'] === 'peptide-store'
                && $args['wp_options'] === [
                    'blogname' => 'Apex Research',
                    'admin_email' => 'owner@apexresearch.example',
                    'waas_store_owner' => '{"country":"US"}',
                ]
                && strpos(base64_decode($args['php_constants']['WPCS_PUBLIC_KEY']['value']), 'PUBLIC KEY') !== false;
        }))->andReturn((object) [
            'externalId' => '5f0b7c3e-2d1a-4e8b-9c6f-0a1b2c3d4e5f',
            'baseDomain' => 't-1.stores.waas.example',
            'customDomain' => 'apex-research.stores.waas.example',
        ]);
        Actions\expectDone('waas_tenant_linked_to_subscription')->once()->with(901);

        $this->run_due(901);

        $meta = $this->cart->meta[901];
        $this->assertSame('5f0b7c3e-2d1a-4e8b-9c6f-0a1b2c3d4e5f', $meta[Tenant::TENANT_EXTERNAL_ID_META]);
        $this->assertSame('apex-research.stores.waas.example', $meta[Tenant::DOMAIN_NAME_META]);
        $this->assertSame('t-1.stores.waas.example', $meta[Tenant::BASE_DOMAIN_NAME_META]);
        $this->assertSame([], $this->queue(901));
    }

    public function test_a_second_activation_before_the_job_ran_queues_nothing_more(): void
    {
        $this->stub_new_store_functions();

        // FluentCart reports subscription_activated and order_paid_done for the same purchase.
        $this->manager->create_tenant_when_subscription_created(901, $this->purchase());
        $this->manager->create_tenant_when_subscription_created(901, $this->purchase());

        $this->assertCount(1, $this->queue(901));
    }

    public function test_a_create_retried_after_its_answer_was_lost_adopts_the_store_instead_of_making_another(): void
    {
        $this->stub_new_store_functions();
        $this->manager->create_tenant_when_subscription_created(901, $this->purchase());

        // First try: the API created the store but the answer timed out.
        $sent_keys = [];
        $this->api->expects('create_tenant')->once()->andReturnUsing(function ($args) use (&$sent_keys) {
            $sent_keys[] = $args['php_constants']['WPCS_PUBLIC_KEY']['value'];
            throw new \Exception('Failed: cURL error 28: Operation timed out');
        });
        $this->run_due(901);
        $this->assertSame(Tenant::QUEUED, $this->state(901));

        // Retry: the store is there under the externalId chosen at checkout.
        $this->api->expects('get_tenant_safe')->with('5f0b7c3e-2d1a-4e8b-9c6f-0a1b2c3d4e5f')->andReturn((object) [
            'externalId' => '5f0b7c3e-2d1a-4e8b-9c6f-0a1b2c3d4e5f',
            'domainName' => 'apex-research.stores.waas.example',
            'baseDomain' => 't-1.stores.waas.example',
            'statusCode' => 0,
        ]);
        $this->run_due(901, true);

        $this->assertSame('5f0b7c3e-2d1a-4e8b-9c6f-0a1b2c3d4e5f', $this->cart->meta[901][Tenant::TENANT_EXTERNAL_ID_META]);
        $this->assertSame('apex-research.stores.waas.example', $this->cart->meta[901][Tenant::DOMAIN_NAME_META]);
        $this->assertSame(base64_encode($this->cart->meta[901][Tenant::TENANT_PUBLIC_KEY_META]), $sent_keys[0]);
        $this->assertSame([], $this->queue(901));
    }

    public function test_a_create_retried_after_the_api_was_down_sends_the_same_external_id_and_key(): void
    {
        $this->stub_new_store_functions();
        $this->manager->create_tenant_when_subscription_created(901, $this->purchase());

        $sent = [];
        $this->api->expects('create_tenant')->twice()->andReturnUsing(function ($args) use (&$sent) {
            $sent[] = [$args['external_id'], $args['php_constants']['WPCS_PUBLIC_KEY']['value']];
            if (count($sent) === 1) {
                throw new \Exception('Failed: 503');
            }
            return (object) ['externalId' => $args['external_id'], 'baseDomain' => 't-1.stores.waas.example', 'customDomain' => null];
        });
        $this->api->expects('get_tenant_safe')->andReturnNull();

        $this->run_due(901);
        $this->run_due(901, true);

        $this->assertSame($sent[0], $sent[1]);
        $this->assertSame('t-1.stores.waas.example', $this->cart->meta[901][Tenant::DOMAIN_NAME_META]);
    }

    public function test_cancelling_queues_a_suspend_with_backup_and_schedules_deletion_after_30_days(): void
    {
        $this->api->expects('suspend_tenant')->never();

        $before = time();
        $this->manager->suspend_tenant_when_subscription_expired(900);

        $this->assertSame(Tenant::SUSPENDED, $this->state());
        $delete_at = $this->cart->meta[900][Tenant::TENANT_DELETE_AT_META];
        $this->assertGreaterThanOrEqual($before + 30 * DAY_IN_SECONDS, $delete_at);
        $this->assertSame([[$delete_at, self::HOOK, [900]]], $this->scheduled);

        $this->api->expects('suspend_tenant')->once()->with('store-1042', true);
        $this->api->expects('delete_tenant')->never();
        Actions\expectDone('waas_tenant_suspended')->once()->with(900, $delete_at);
        $this->run_due(900);
    }

    public function test_grace_period_comes_from_the_setting(): void
    {
        $this->options[TenantsSubscriptionManager::DELETION_GRACE_DAYS_OPTION] = '7';

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
        $this->manager->suspend_tenant_when_subscription_expired(900);
        $this->manager->suspend_tenant_when_subscription_expired(900);

        $this->assertCount(1, $this->queue(900));
        $this->assertCount(1, $this->scheduled);
    }

    public function test_cancelling_a_subscription_without_a_store_does_nothing(): void
    {
        $this->manager->suspend_tenant_when_subscription_expired(901);

        $this->assertSame([], $this->queue(901));
        $this->assertSame([], $this->scheduled);
    }

    public function test_cancelling_before_the_store_was_created_suspends_it_once_created(): void
    {
        $this->stub_new_store_functions();
        $this->manager->create_tenant_when_subscription_created(901, $this->purchase());
        $this->manager->suspend_tenant_when_subscription_expired(901);

        $calls = [];
        $this->api->expects('create_tenant')->andReturnUsing(function ($args) use (&$calls) {
            $calls[] = 'create';
            return (object) ['externalId' => $args['external_id'], 'baseDomain' => 't-1.stores.waas.example', 'customDomain' => null];
        });
        $this->api->expects('suspend_tenant')->andReturnUsing(function ($external_id, $backup) use (&$calls) {
            $calls[] = "suspend $external_id";
        });
        Actions\expectDone('waas_tenant_linked_to_subscription')->never();

        $this->run_due(901);

        $this->assertSame(['create', 'suspend 5f0b7c3e-2d1a-4e8b-9c6f-0a1b2c3d4e5f'], $calls);
        $this->assertSame(Tenant::SUSPENDED, $this->state(901));
    }

    public function test_deletion_after_the_grace_period_deletes_the_suspended_store(): void
    {
        $this->api->allows('suspend_tenant');
        $this->manager->suspend_tenant_when_subscription_expired(900);
        $this->manager->delete_suspended_tenant(900);
        $this->assertSame(Tenant::DELETED, $this->state());

        $this->api->expects('delete_tenant')->with(['external_id' => 'store-1042']);
        Actions\expectDone('waas_tenant_deleted')->once()->with(900);
        $this->run_due(900);

        $this->assertSame('', $this->cart->meta[900][Tenant::TENANT_DELETE_AT_META]);
    }

    public function test_reactivating_within_the_grace_period_resumes_and_cancels_the_deletion(): void
    {
        $this->manager->suspend_tenant_when_subscription_expired(900);
        $this->manager->create_tenant_when_subscription_created(900, $this->purchase());

        $this->assertSame(Tenant::READY, $this->state());
        $this->assertSame([], $this->scheduled);
        $this->assertSame('', $this->cart->meta[900][Tenant::TENANT_DELETE_AT_META]);

        $calls = [];
        $this->api->expects('suspend_tenant')->andReturnUsing(function () use (&$calls) { $calls[] = 'suspend'; });
        $this->api->expects('resume_tenant')->with('store-1042')->andReturnUsing(function () use (&$calls) { $calls[] = 'resume'; });
        $this->api->expects('create_tenant')->never();
        $this->run_due(900);

        $this->assertSame(['suspend', 'resume'], $calls);
    }

    public function test_a_deletion_event_left_over_after_a_resume_does_nothing(): void
    {
        $this->manager->suspend_tenant_when_subscription_expired(900);
        $this->manager->resume_suspended_tenant(900);
        $this->manager->delete_suspended_tenant(900);

        $this->assertSame(Tenant::READY, $this->state());
        $this->assertSame(['suspend', 'resume'], array_column($this->queue(900), 'op'));
    }

    public function test_activation_of_a_running_store_queues_nothing(): void
    {
        $this->manager->create_tenant_when_subscription_created(900, $this->purchase());

        $this->assertSame(Tenant::READY, $this->state());
        $this->assertSame([], $this->queue(900));
    }

    public function test_on_hold_takes_the_store_offline_without_backup_or_deletion(): void
    {
        $this->manager->suspend_tenant_on_hold(900);

        $this->assertSame(Tenant::SUSPENDED, $this->state());
        $this->assertSame([], $this->scheduled);

        $this->api->expects('suspend_tenant')->once()->with('store-1042', false);
        Actions\expectDone('waas_tenant_suspended')->once()->with(900, 0);
        $this->run_due(900);
    }

    public function test_cancelling_a_store_on_hold_backs_it_up_and_starts_the_grace_period(): void
    {
        $this->manager->suspend_tenant_on_hold(900);
        $this->manager->suspend_tenant_when_subscription_expired(900);
        $this->manager->suspend_tenant_when_subscription_expired(900);

        $this->assertCount(1, $this->scheduled);
        $this->api->expects('suspend_tenant')->once()->with('store-1042', false);
        $this->api->expects('create_tenant_backup')->once()->with('store-1042');
        $this->run_due(900);

        $this->assertSame(Tenant::SUSPENDED, $this->state());
    }

    public function test_reactivating_after_a_hold_resumes_the_store(): void
    {
        $this->manager->suspend_tenant_on_hold(900);
        $this->manager->resume_suspended_tenant(900);

        $this->api->expects('suspend_tenant')->once();
        $this->api->expects('resume_tenant')->once()->with('store-1042');
        $this->run_due(900);

        $this->assertSame(Tenant::READY, $this->state());
    }

    private function reason($subscription_id = 900)
    {
        return $this->cart->meta[$subscription_id][Tenant::TENANT_SUSPEND_REASON_META] ?? null;
    }

    private function queued_ops($subscription_id = 900): array
    {
        return array_column($this->queue($subscription_id), 'op');
    }

    public function test_a_billing_suspension_records_why_and_a_resume_clears_it(): void
    {
        $this->manager->suspend_tenant_on_hold(900);
        $this->assertSame(Tenant::SUSPENDED_ON_HOLD, $this->reason());

        $this->manager->suspend_tenant_when_subscription_expired(900);
        $this->assertSame(Tenant::SUSPENDED_ENDED, $this->reason());

        $this->manager->resume_suspended_tenant(900);
        $this->assertSame('', $this->reason());
    }

    public function test_a_renewal_paid_after_a_hold_resumes_the_store(): void
    {
        $this->manager->suspend_tenant_on_hold(900);

        $this->manager->resume_after_renewal(900);

        $this->assertSame(Tenant::READY, $this->state());
        $this->assertSame(['suspend', 'resume'], $this->queued_ops());
    }

    public function test_a_renewal_after_the_subscription_ended_resumes_the_store_and_drops_the_deletion(): void
    {
        // E.g. the first order was refunded in the payment provider's dashboard, but billing went on.
        $this->manager->suspend_tenant_when_subscription_expired(900);
        $this->assertCount(1, $this->scheduled);

        $this->manager->resume_after_renewal(900);

        $this->assertSame(Tenant::READY, $this->state());
        $this->assertSame([], $this->scheduled);
        $this->assertSame('', $this->cart->meta[900][Tenant::TENANT_DELETE_AT_META]);
    }

    public function test_a_renewal_leaves_a_store_suspended_by_hand_offline(): void
    {
        $this->cart->meta[900][Tenant::TENANT_STATE] = Tenant::SUSPENDED;

        $this->manager->resume_after_renewal(900);

        $this->assertSame(Tenant::SUSPENDED, $this->state());
        $this->assertSame([], $this->queue(900));
    }

    public function test_a_renewal_while_the_subscription_is_not_active_keeps_the_store_offline(): void
    {
        $this->manager->suspend_tenant_on_hold(900);
        $this->cart->inactive[900] = true;

        $this->manager->resume_after_renewal(900);

        $this->assertSame(Tenant::SUSPENDED, $this->state());
        $this->assertSame(['suspend'], $this->queued_ops());
    }

    public function test_a_renewal_after_the_deletion_ran_does_nothing(): void
    {
        $this->manager->suspend_tenant_when_subscription_expired(900);
        $this->manager->delete_suspended_tenant(900);

        $this->manager->resume_after_renewal(900);

        $this->assertSame(Tenant::DELETED, $this->state());
        $this->assertSame(['suspend', 'delete'], $this->queued_ops());
    }

    public function test_a_renewal_of_a_running_store_does_nothing(): void
    {
        $this->manager->resume_after_renewal(900);

        $this->assertSame(Tenant::READY, $this->state());
        $this->assertSame([], $this->queue(900));
    }

    public function test_the_renewal_event_is_wired(): void
    {
        $this->assertNotFalse(has_action('waas_subscription_renewed', [$this->manager, 'resume_after_renewal']));
    }

    private function deleted_store(): void
    {
        $this->cart->meta[900][Tenant::TENANT_STATE] = Tenant::DELETED;
    }

    public function test_reactivating_after_deletion_restores_the_newest_finished_backup(): void
    {
        $this->stub_new_store_functions();
        $this->deleted_store();
        $this->manager->create_tenant_when_subscription_created(900, $this->purchase());
        $this->assertSame(Tenant::QUEUED, $this->state());

        $this->api->expects('get_tenant_backups')->with('store-1042')->andReturn([
            (object) ['id' => 'bk-pending', 'statusCode' => 0],
            (object) ['id' => 'bk-newest-done', 'statusCode' => 1],
            (object) ['id' => 'bk-older', 'statusCode' => 1],
        ]);
        $this->api->expects('restore_tenant')->with('store-1042', 'bk-newest-done');
        $this->api->expects('create_tenant')->never();
        Actions\expectDone('waas_tenant_linked_to_subscription')->once()->with(900);
        Actions\expectDone('waas_tenant_restored')->once()->with(900, 'bk-newest-done');
        $this->run_due(900);

        $this->assertSame('store-1042', $this->cart->meta[900][Tenant::TENANT_EXTERNAL_ID_META]);
    }

    public function test_reactivating_after_deletion_without_a_backup_creates_a_fresh_store(): void
    {
        $this->stub_new_store_functions();
        $this->deleted_store();
        $this->manager->create_tenant_when_subscription_created(900, $this->purchase());

        $this->api->expects('get_tenant_backups')->with('store-1042')->andReturn([]);
        $this->api->expects('restore_tenant')->never();
        $this->api->expects('create_tenant')->once()->andReturn((object) [
            'externalId' => '5f0b7c3e-2d1a-4e8b-9c6f-0a1b2c3d4e5f',
            'baseDomain' => 't-2001.stores.waas.example',
            'customDomain' => 'apex-research.stores.waas.example',
        ]);
        $this->run_due(900);

        $meta = $this->cart->meta[900];
        $this->assertSame('5f0b7c3e-2d1a-4e8b-9c6f-0a1b2c3d4e5f', $meta[Tenant::TENANT_EXTERNAL_ID_META]);
        $this->assertSame('t-2001.stores.waas.example', $meta[Tenant::BASE_DOMAIN_NAME_META]);
        $this->assertSame([], $this->queue(900));
    }

    public function test_an_error_listing_backups_never_creates_a_second_store(): void
    {
        $this->stub_new_store_functions();
        $this->deleted_store();
        $this->manager->create_tenant_when_subscription_created(900, $this->purchase());
        $this->api->expects('get_tenant_backups')->andThrow(new \Exception('API down'));
        $this->api->expects('create_tenant')->never();

        $this->run_due(900);

        $this->assertSame('store-1042', $this->cart->meta[900][Tenant::TENANT_EXTERNAL_ID_META]);
        $this->assertSame('restore_or_create', $this->queue(900)[0]['op']);
        $this->assertSame(1, $this->queue(900)[0]['attempts']);
    }
    private const SUSPEND_HOOK = TenantsSubscriptionManager::SUSPEND_AT_PERIOD_END_HOOK;

    public function test_a_cancel_inside_the_paid_period_keeps_the_store_and_plans_the_suspend(): void
    {
        $until = time() + 30 * DAY_IN_SECONDS;
        Actions\expectDone('waas_tenant_suspend_scheduled')->once()->with(900, $until);

        $this->manager->suspend_at_end_of_paid_period(900, $until);

        $this->assertSame(Tenant::READY, $this->state());
        $this->assertSame($until, $this->cart->meta[900][Tenant::TENANT_SUSPEND_AT_META]);
        $this->assertSame([[$until, self::SUSPEND_HOOK, [900]]], $this->scheduled);
        $this->assertSame([], $this->queue(900), 'no API call until then');
    }

    public function test_a_repeated_cancel_keeps_the_first_date(): void
    {
        $until = time() + 30 * DAY_IN_SECONDS;
        $this->manager->suspend_at_end_of_paid_period(900, $until);
        $this->manager->suspend_at_end_of_paid_period(900, $until + 3600);

        $this->assertSame($until, $this->cart->meta[900][Tenant::TENANT_SUSPEND_AT_META]);
        $this->assertCount(1, $this->scheduled);
    }

    public function test_at_the_end_of_the_paid_period_the_store_is_suspended_as_on_any_cancel(): void
    {
        $this->manager->suspend_at_end_of_paid_period(900, time() + 30 * DAY_IN_SECONDS);
        $this->cart->inactive[900] = true;

        $this->manager->paid_period_ended(900);

        $this->assertSame(Tenant::SUSPENDED, $this->state());
        $this->assertSame('', $this->cart->meta[900][Tenant::TENANT_SUSPEND_AT_META]);
        $this->assertSame('suspend', $this->queue(900)[0]['op']);
        $this->assertTrue($this->queue(900)[0]['args']['backup']);
        $this->assertNotEmpty($this->cart->meta[900][Tenant::TENANT_DELETE_AT_META]);
    }

    public function test_reactivating_before_the_end_keeps_the_store_and_drops_the_planned_suspend(): void
    {
        $this->manager->suspend_at_end_of_paid_period(900, time() + 30 * DAY_IN_SECONDS);
        Actions\expectDone('waas_tenant_suspend_canceled')->once()->with(900);

        $this->manager->create_tenant_when_subscription_created(900, $this->purchase());

        $this->assertSame(Tenant::READY, $this->state());
        $this->assertSame('', $this->cart->meta[900][Tenant::TENANT_SUSPEND_AT_META]);
        $this->assertSame([], $this->scheduled);
        $this->assertSame([], $this->queue(900));
    }

    public function test_a_planned_suspend_firing_after_a_reactivation_does_nothing(): void
    {
        $this->manager->suspend_at_end_of_paid_period(900, time() + 30 * DAY_IN_SECONDS);
        $this->manager->create_tenant_when_subscription_created(900, $this->purchase());

        $this->manager->paid_period_ended(900);

        $this->assertSame(Tenant::READY, $this->state());
        $this->assertSame([], $this->queue(900));
    }

    public function test_an_active_subscription_at_the_end_of_the_period_keeps_the_store(): void
    {
        // e.g. reactivated through a path that didn't reach waas-host
        $this->manager->suspend_at_end_of_paid_period(900, time() + 30 * DAY_IN_SECONDS);

        $this->manager->paid_period_ended(900);

        $this->assertSame(Tenant::READY, $this->state());
        $this->assertSame('', $this->cart->meta[900][Tenant::TENANT_SUSPEND_AT_META]);
        $this->assertSame([], $this->queue(900));
    }

    public function test_the_sweep_runs_a_planned_suspend_whose_event_was_lost(): void
    {
        $this->cart->meta[900][Tenant::TENANT_SUSPEND_AT_META] = time() - 60;
        $this->cart->meta[901] = [Tenant::TENANT_SUSPEND_AT_META => time() + 3600];
        $this->cart->inactive[900] = true;

        $this->manager->run_due_suspensions();

        $this->assertSame(Tenant::SUSPENDED, $this->state());
        $this->assertSame('suspend', $this->queue(900)[0]['op']);
        $this->assertSame([], $this->queue(901), 'not due yet');
    }

    public function test_a_store_already_offline_or_a_past_date_takes_the_usual_path_now(): void
    {
        $this->manager->suspend_tenant_on_hold(900);
        $this->manager->suspend_at_end_of_paid_period(900, time() + 30 * DAY_IN_SECONDS);

        $this->assertSame('', $this->cart->meta[900][Tenant::TENANT_SUSPEND_AT_META] ?? '');
        $this->assertNotEmpty($this->cart->meta[900][Tenant::TENANT_DELETE_AT_META], 'backup and grace period start now');

        $this->cart->meta[902] = [Tenant::TENANT_EXTERNAL_ID_META => 'store-2', Tenant::TENANT_STATE => Tenant::READY];
        $this->manager->suspend_at_end_of_paid_period(902, time() - 1);
        $this->assertSame(Tenant::SUSPENDED, $this->state(902));
    }
}

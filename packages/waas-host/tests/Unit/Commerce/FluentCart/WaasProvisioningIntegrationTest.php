<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\FluentCart\FluentCartStore;
use WaaSHost\Commerce\FluentCart\WaasProvisioningIntegration;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;

class WaasProvisioningIntegrationTest extends TestCase
{
    private $store;
    private $order;
    private array $logs = [];
    /** What get_waas_state returns: a test's action changes it to say waas-host acted. */
    private array $state = [];
    private WaasProvisioningIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        $this->store = Mockery::mock(FluentCartStore::class);
        $this->order = Mockery::mock();
        $this->order->id = 500;
        $this->order->type = 'subscription';
        $this->order->allows('addLog')->andReturnUsing(function (...$args) {
            $this->logs[] = $args;
        });
        $this->store->allows('get_subscription_status')->with(900)->andReturn('canceled')->byDefault();
        // Nothing left of the paid period unless a test says otherwise: removal is immediate.
        $this->store->allows('get_paid_through')->with(900)->andReturn(0)->byDefault();
        $this->store->allows('get_waas_state')->andReturnUsing(function () {
            return $this->state;
        })->byDefault();
        $this->integration = new WaasProvisioningIntegration(Mockery::mock(WPCSService::class), $this->store);
    }

    private function run_feed(string $trigger, array $extra = []): void
    {
        $this->integration->processAction($this->order, array_merge([
            'trigger' => $trigger,
            'is_revoke_hook' => 'no',
            'feed' => ['enabled' => 'yes'],
            'event_data' => ['subscription' => (object) ['id' => 900]],
        ], $extra));
    }

    private function has_store(bool $has): void
    {
        $this->store->allows('get_subscription_meta')->with(900, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META)->andReturn($has ? 'ext-123' : '');
    }

    public function test_activation_creates_the_store_with_the_order(): void
    {
        Actions\expectDone('wpcs_subscription_created')->once()->with(900, $this->order);

        $this->run_feed('subscription_activated');
    }

    public function test_background_run_finds_the_subscription_by_order(): void
    {
        $this->store->expects('find_subscription_for_order')->with($this->order)->andReturn((object) ['id' => 901]);
        Actions\expectDone('wpcs_subscription_created')->once()->with(901, $this->order);

        $this->run_feed('subscription_activated', ['event_data' => []]);
    }

    public function test_one_off_purchase_is_ignored(): void
    {
        $this->store->allows('find_subscription_for_order')->andReturnNull();
        Actions\expectDone('wpcs_subscription_created')->never();
        Actions\expectDone('wpcs_subscription_expired')->never();

        $this->run_feed('order_paid_done', ['event_data' => []]);
    }

    /** @dataProvider removal_events */
    public function test_cancel_end_of_term_and_revoke_remove_the_store(string $trigger, string $is_revoke): void
    {
        $this->has_store(true);
        Actions\expectDone('wpcs_subscription_expired')->once()->with(900);
        Actions\expectDone('wpcs_subscription_created')->never();

        $this->run_feed($trigger, ['is_revoke_hook' => $is_revoke]);
    }

    public function removal_events(): array
    {
        return [
            'canceled' => ['subscription_canceled', 'no'],
            'end of term' => ['subscription_eot', 'no'],
            'access expired' => ['subscription_expired_validity', 'yes'],
            'refunded' => ['order_fully_refunded', 'yes'],
        ];
    }

    public function test_a_cancel_inside_the_paid_period_keeps_the_website_until_its_end(): void
    {
        $this->has_store(true);
        $until = time() + 30 * 86400;
        $this->store->allows('get_paid_through')->with(900)->andReturn($until);
        Actions\expectDone('wpcs_subscription_canceled_until')->once()->with(900, $until)->whenHappen(function () use ($until) {
            $this->state = [WPCSTenant::WPCS_TENANT_SUSPEND_AT_META => $until];
        });
        Actions\expectDone('wpcs_subscription_expired')->never();

        $this->run_feed('subscription_canceled');

        $this->assertCount(1, $this->logs);
        $this->assertSame('Website stays online until the paid period ends', $this->logs[0][0]);
        $this->assertStringContainsString(gmdate('Y-m-d H:i', $until), $this->logs[0][1]);
    }

    /** @dataProvider immediate_removals_despite_a_paid_period */
    public function test_refund_end_of_term_and_expired_access_stay_immediate(string $trigger, string $is_revoke): void
    {
        $this->has_store(true);
        $this->store->allows('get_paid_through')->with(900)->andReturn(time() + 30 * 86400);
        Actions\expectDone('wpcs_subscription_expired')->once()->with(900);
        Actions\expectDone('wpcs_subscription_canceled_until')->never();

        $this->run_feed($trigger, ['is_revoke_hook' => $is_revoke]);
    }

    public function immediate_removals_despite_a_paid_period(): array
    {
        return [
            'refunded' => ['order_fully_refunded', 'yes'],
            'order cancelled' => ['order_status_changed_to_canceled', 'yes'],
            'end of term' => ['subscription_eot', 'no'],
            'access expired' => ['subscription_expired_validity', 'yes'],
        ];
    }

    public function test_a_cancel_after_the_paid_period_is_immediate(): void
    {
        $this->has_store(true);
        $this->store->allows('get_paid_through')->with(900)->andReturn(time() - 60);
        Actions\expectDone('wpcs_subscription_expired')->once()->with(900);
        Actions\expectDone('wpcs_subscription_canceled_until')->never();

        $this->run_feed('subscription_canceled');
    }

    public function test_removal_without_a_store_does_nothing(): void
    {
        $this->has_store(false);
        $this->store->allows('get_subscription_meta')->with(900, WPCSTenant::WPCS_NAME_CONFLICT_META)->andReturn('');
        Actions\expectDone('wpcs_subscription_expired')->never();

        $this->run_feed('subscription_canceled');
    }

    public function test_other_triggers_do_nothing(): void
    {
        Actions\expectDone('wpcs_subscription_created')->never();
        Actions\expectDone('wpcs_subscription_expired')->never();

        $this->run_feed('subscription_renewed');
    }

    public function test_defaults_create_on_activation_and_remove_on_cancel_end_and_revoke(): void
    {
        $defaults = $this->integration->getIntegrationDefaults([]);

        // Reactivation (a late payment after the subscription expired) resumes the suspended store.
        $this->assertSame(['subscription_activated', 'subscription_reactivated', 'subscription_canceled', 'subscription_eot'], $defaults['event_trigger']);
        $this->assertSame('yes', $defaults['watch_on_access_revoke']);
        $this->assertTrue($this->integration->isConfigured());
    }

    public function test_settings_offer_roles_and_snapshot_groups(): void
    {
        Functions\when('get_option')->justReturn(['peptide-store' => (object) ['title' => 'Peptide store']]);
        Functions\when('esc_url')->returnArg();
        Functions\when('admin_url')->returnArg();
        Functions\when('wp_nonce_url')->returnArg();
        $wpcs = Mockery::mock(WPCSService::class);
        $wpcs->allows('get_available_groupnames')->andReturn(['peptide-starter']);
        $integration = new WaasProvisioningIntegration($wpcs, $this->store);

        $fields = array_column($integration->getSettingsFields([])['fields'], null, 'key');

        $this->assertSame(['peptide-store' => 'Peptide store'], $fields['role']['options']);
        $this->assertSame(['peptide-starter' => 'peptide-starter'], $fields['group_name']['options']);
        $this->assertArrayHasKey('event_trigger', $fields);
    }

    public function test_settings_still_load_when_the_api_is_down(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('esc_url')->returnArg();
        Functions\when('admin_url')->returnArg();
        Functions\when('wp_nonce_url')->returnArg();
        $wpcs = Mockery::mock(WPCSService::class);
        $wpcs->allows('get_available_groupnames')->andThrow(new \Exception('down'));
        $integration = new WaasProvisioningIntegration($wpcs, $this->store);

        $fields = array_column($integration->getSettingsFields([])['fields'], null, 'key');

        $this->assertSame([], $fields['group_name']['options']);
    }

    public function test_cancelling_a_store_that_waits_for_a_new_name_is_reported(): void
    {
        $this->has_store(false);
        $this->store->allows('get_subscription_meta')->with(900, WPCSTenant::WPCS_NAME_CONFLICT_META)->andReturn(['name' => 'Apex Research']);
        Actions\expectDone('wpcs_subscription_expired')->once()->with(900);

        $this->run_feed('subscription_canceled');
    }

    /** @dataProvider stale_removal_events */
    public function test_a_cancel_handled_after_the_subscription_became_active_again_is_ignored_but_a_refund_counts(string $trigger, bool $waiting): void
    {
        // Canceled, then reactivated; FluentCart runs the feeds in the background and the reactivation ran first.
        $this->has_store(!$waiting);
        $this->store->allows('get_subscription_meta')->with(900, WPCSTenant::WPCS_NAME_CONFLICT_META)->andReturn($waiting ? ['name' => 'Apex Research'] : '');
        $this->store->allows('get_subscription_status')->with(900)->andReturn('active');
        // Only the refund: it leaves the subscription active.
        Actions\expectDone('wpcs_subscription_expired')->once()->with(900);

        $this->run_feed($trigger);
        $this->run_feed('order_fully_refunded', ['is_revoke_hook' => 'yes']);
    }

    public function stale_removal_events(): array
    {
        return [
            'canceled, store' => ['subscription_canceled', false],
            'canceled, waiting for a name' => ['subscription_canceled', true],
            'end of term, waiting for a name' => ['subscription_eot', true],
        ];
    }

    public function test_a_late_payment_that_reactivates_the_subscription_resumes_the_store(): void
    {
        Actions\expectDone('wpcs_subscription_created')->once()->with(900, $this->order);

        $this->run_feed('subscription_reactivated');
    }

    /** @dataProvider renewal_order_revokes */
    public function test_a_renewal_order_refunded_or_cancelled_while_the_subscription_stays_active_keeps_the_store(string $trigger, string $status): void
    {
        $this->order->type = 'renewal';
        $this->has_store(true);
        $this->store->allows('get_subscription_status')->with(900)->andReturn($status);
        Actions\expectDone('wpcs_subscription_expired')->never();

        $this->run_feed($trigger, ['is_revoke_hook' => 'yes']);

        $this->assertSame('Website kept', $this->logs[0][0]);
        $this->assertStringContainsString('renewal order', $this->logs[0][1]);
    }

    public function renewal_order_revokes(): array
    {
        return [
            'refunded, active' => ['order_fully_refunded', 'active'],
            'refunded, trialing' => ['order_fully_refunded', 'trialing'],
            'cancelled, active' => ['order_status_changed_to_canceled', 'active'],
        ];
    }

    public function test_a_renewal_order_refunded_after_the_subscription_ended_still_removes_the_store(): void
    {
        $this->order->type = 'renewal';
        $this->has_store(true);
        $this->store->allows('get_subscription_status')->with(900)->andReturn('canceled');
        Actions\expectDone('wpcs_subscription_expired')->once()->with(900);

        $this->run_feed('order_fully_refunded', ['is_revoke_hook' => 'yes']);
    }

    public function test_the_initial_order_refunded_removes_the_store_although_fluentcart_keeps_the_subscription_active(): void
    {
        $this->has_store(true);
        $this->store->allows('get_subscription_status')->with(900)->andReturn('active');
        Actions\expectDone('wpcs_subscription_expired')->once()->with(900);

        $this->run_feed('order_fully_refunded', ['is_revoke_hook' => 'yes']);
    }

    public function test_the_order_note_is_written_when_waas_host_acted(): void
    {
        $this->has_store(true);
        Actions\expectDone('wpcs_subscription_expired')->once()->with(900)->whenHappen(function () {
            $this->state = [WPCSTenant::WPCS_TENANT_STATE => WPCSTenant::SUSPENDED];
        });

        $this->run_feed('subscription_eot');

        $this->assertSame([['Website suspended', 'Subscription #900 (subscription_eot)', 'info', 'WaaS']], $this->logs);
    }

    public function test_no_order_note_when_waas_host_did_nothing(): void
    {
        // E.g. a cancel reported a second time: the store is already suspended and its deletion planned.
        $this->has_store(true);
        Actions\expectDone('wpcs_subscription_expired')->once()->with(900);

        $this->run_feed('subscription_eot');

        $this->assertSame([], $this->logs);
    }

    public function test_a_create_for_a_store_that_already_runs_leaves_no_note(): void
    {
        Actions\expectDone('wpcs_subscription_created')->once()->with(900, $this->order);

        $this->run_feed('subscription_reactivated');

        $this->assertSame([], $this->logs);
    }

    public function test_a_requested_website_gets_its_note(): void
    {
        Actions\expectDone('wpcs_subscription_created')->once()->with(900, $this->order)->whenHappen(function () {
            $this->state = [WPCSTenant::WPCS_TENANT_STATE => WPCSTenant::QUEUED];
        });

        $this->run_feed('subscription_activated');

        $this->assertSame('Website requested', $this->logs[0][0]);
    }
}

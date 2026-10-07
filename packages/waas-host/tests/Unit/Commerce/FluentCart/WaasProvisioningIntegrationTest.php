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
    private WaasProvisioningIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        $this->store = Mockery::mock(FluentCartStore::class);
        $this->order = Mockery::mock();
        $this->order->id = 500;
        $this->order->allows('addLog');
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

        $this->assertSame(['subscription_activated', 'subscription_canceled', 'subscription_eot'], $defaults['event_trigger']);
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
}

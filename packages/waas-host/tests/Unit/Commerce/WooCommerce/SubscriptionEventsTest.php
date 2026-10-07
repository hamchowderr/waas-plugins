<?php

namespace Tests\Unit\Commerce\WooCommerce;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Commerce\WooCommerce\SubscriptionEvents;
use WaaSHost\Commerce\WooCommerce\WooCommerceSubscriptions;
use WaaSHost\Commerce\WooCommerce\WpSwingsSubscriptions;
use WaaSHost\Core\WPCSTenant;

class SubscriptionEventsTest extends TestCase
{
    private FakeCommerceAdapter $commerce;

    protected function setUp(): void
    {
        parent::setUp();
        Commerce::reset();
        Commerce::register($this->commerce = new FakeCommerceAdapter('woocommerce'));
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    public function test_activation_without_a_store_creates_one(): void
    {
        $order = Mockery::mock('WC_Order');
        Actions\expectDone('wpcs_subscription_created')->once()->with(900, $order);
        Actions\expectDone('wpcs_subscription_reactivated')->never();

        SubscriptionEvents::activated(900, $order);
    }

    public function test_activation_without_an_order_creates_nothing(): void
    {
        Actions\expectDone('wpcs_subscription_created')->never();

        SubscriptionEvents::activated(900, null);
    }

    public function test_hold_then_reactivation_fire_once_each(): void
    {
        $this->with_store(900);
        Actions\expectDone('wpcs_subscription_suspended')->once()->with(900);
        Actions\expectDone('wpcs_subscription_reactivated')->once()->with(900);
        Actions\expectDone('wpcs_subscription_created')->never();

        SubscriptionEvents::suspended(900);
        SubscriptionEvents::suspended(900);
        SubscriptionEvents::activated(900, Mockery::mock('WC_Order'));
        SubscriptionEvents::activated(900, Mockery::mock('WC_Order'));

        $this->assertSame('', $this->commerce->meta[900][SubscriptionEvents::SUSPENDED_META]);
    }

    public function test_renewal_of_an_active_store_fires_nothing(): void
    {
        $this->with_store(900);
        Actions\expectDone('wpcs_subscription_created')->never();
        Actions\expectDone('wpcs_subscription_reactivated')->never();

        SubscriptionEvents::activated(900, Mockery::mock('WC_Order'));
    }

    public function test_end_and_hold_fire_only_for_subscriptions_with_a_store(): void
    {
        $this->with_store(900);
        Actions\expectDone('wpcs_subscription_expired')->once()->with(900);
        Actions\expectDone('wpcs_subscription_suspended')->never();

        SubscriptionEvents::ended(900);
        SubscriptionEvents::ended(901);
        SubscriptionEvents::suspended(901);
    }

    /**
     * @dataProvider woocommerce_subscriptions_statuses
     */
    public function test_woocommerce_subscriptions_status_mapping(string $status, ?string $event): void
    {
        $this->with_store(900);
        $this->commerce->meta[900][SubscriptionEvents::SUSPENDED_META] = $status === 'active' ? 'yes' : '';
        foreach (['wpcs_subscription_reactivated', 'wpcs_subscription_suspended', 'wpcs_subscription_expired'] as $name) {
            Actions\expectDone($name)->times($name === $event ? 1 : 0);
        }

        $subscription = Mockery::mock('WC_Subscription', 'WC_Order');
        $subscription->allows('get_id')->andReturn(900);
        $subscription->allows('get_parent_id')->andReturn(0);

        (new WooCommerceSubscriptions())->on_status_updated($subscription, $status, 'whatever');
    }

    public function woocommerce_subscriptions_statuses(): array
    {
        return [
            'active after hold' => ['active', 'wpcs_subscription_reactivated'],
            'on hold' => ['on-hold', 'wpcs_subscription_suspended'],
            'cancelled' => ['cancelled', 'wpcs_subscription_expired'],
            'expired' => ['expired', 'wpcs_subscription_expired'],
            'pending cancel' => ['pending-cancel', null],
        ];
    }

    public function test_woocommerce_subscriptions_without_a_parent_order_creates_from_the_subscription(): void
    {
        $subscription = Mockery::mock('WC_Subscription', 'WC_Order');
        $subscription->allows('get_id')->andReturn(900);
        $subscription->allows('get_parent_id')->andReturn(0);
        Actions\expectDone('wpcs_subscription_created')->once()->with(900, $subscription);

        (new WooCommerceSubscriptions())->on_status_updated($subscription, 'active', 'pending');
    }

    /**
     * @dataProvider wp_swings_statuses
     */
    public function test_wp_swings_status_meta_mapping(string $status, ?string $event): void
    {
        $this->with_store(900);
        $this->commerce->meta[900][SubscriptionEvents::SUSPENDED_META] = $status === 'active' ? 'yes' : '';
        foreach (['wpcs_subscription_reactivated', 'wpcs_subscription_suspended', 'wpcs_subscription_created'] as $name) {
            Actions\expectDone($name)->times($name === $event ? 1 : 0);
        }
        Functions\when('get_post_type')->justReturn('wps_subscriptions');

        (new WpSwingsSubscriptions())->on_post_meta_written(1, 900, WpSwingsSubscriptions::STATUS_META, $status);
    }

    public function wp_swings_statuses(): array
    {
        return [
            'active after hold' => ['active', 'wpcs_subscription_reactivated'],
            'on hold' => ['on-hold', 'wpcs_subscription_suspended'],
            'paused' => ['paused', 'wpcs_subscription_suspended'],
            'cancelled (its own hook handles it)' => ['cancelled', null],
        ];
    }

    public function test_wp_swings_ignores_other_post_types_and_keys(): void
    {
        $this->with_store(900);
        Actions\expectDone('wpcs_subscription_suspended')->never();
        Functions\when('get_post_type')->justReturn('shop_order');

        $plugin = new WpSwingsSubscriptions();
        $plugin->on_post_meta_written(1, 900, WpSwingsSubscriptions::STATUS_META, 'on-hold');
        $plugin->on_post_meta_written(1, 900, 'something_else', 'on-hold');
    }

    public function test_wp_swings_hpos_save_reads_the_status_from_the_subscription(): void
    {
        $this->with_store(900);
        Actions\expectDone('wpcs_subscription_suspended')->once()->with(900);

        $subscription = Mockery::mock('WPS_Subscription', 'WC_Order');
        $subscription->allows('get_type')->andReturn('wps_subscriptions');
        $subscription->allows('get_id')->andReturn(900);
        $subscription->allows('get_meta')->with(WpSwingsSubscriptions::STATUS_META)->andReturn('on-hold');

        $order = Mockery::mock('WC_Order');
        $order->allows('get_type')->andReturn('shop_order');

        $plugin = new WpSwingsSubscriptions();
        $plugin->on_order_saved($order);
        $plugin->on_order_saved($subscription);
    }

    public function test_wp_swings_activation_passes_the_parent_order(): void
    {
        $subscription = Mockery::mock('WPS_Subscription', 'WC_Order');
        $subscription->allows('get_type')->andReturn('wps_subscriptions');
        $subscription->allows('get_id')->andReturn(900);
        $order = Mockery::mock('WC_Order');
        $order->allows('get_type')->andReturn('shop_order');
        Functions\when('wc_get_order')->alias(fn ($id) => [900 => $subscription, 500 => $order][$id] ?? false);
        Actions\expectDone('wpcs_subscription_created')->once()->with(900, $order);

        (new WpSwingsSubscriptions())->on_activated(500, 900);
    }

    public function test_wp_swings_customer_falls_back_to_its_meta(): void
    {
        $subscription = Mockery::mock('WPS_Subscription', 'WC_Order');
        $subscription->allows('get_customer_id')->andReturn(0);
        $subscription->allows('get_meta')->with('wps_customer_id')->andReturn('42');

        $this->assertSame(42, (new WpSwingsSubscriptions())->get_customer_id($subscription));
    }

    private function with_store(int $subscription_id): void
    {
        $this->commerce->meta[$subscription_id][WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META] = 'ext-' . $subscription_id;
    }
}

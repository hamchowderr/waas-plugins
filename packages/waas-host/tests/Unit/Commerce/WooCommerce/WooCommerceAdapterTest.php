<?php

namespace Tests\Unit\Commerce\WooCommerce;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\PlanProduct;
use WaaSHost\Commerce\WooCommerce\SubscriptionPlugin;
use WaaSHost\Commerce\WooCommerce\WooCommerceAdapter;
use WaaSHost\Core\WPCSProduct;
use WaaSHost\Core\WPCSTenant;

class WooCommerceAdapterTest extends TestCase
{
    public function test_plan_purchase_keeps_only_plan_products_with_their_settings(): void
    {
        $post_meta = [
            101 => [
                '_' . WPCSProduct::IS_WPCS_PRODUCT_META => 'yes',
                WPCSProduct::WPCS_PRODUCT_ROLE_META => 'peptide-store',
                WPCSProduct::WPCS_PRODUCT_GROUPNAME_META => 'peptide-starter',
            ],
            102 => ['_' . WPCSProduct::IS_WPCS_PRODUCT_META => 'no'],
            103 => ['_' . WPCSProduct::IS_WPCS_PRODUCT_META => 'yes'],
        ];
        Functions\when('get_post_meta')->alias(fn ($id, $key) => $post_meta[$id][$key] ?? '');

        $order = Mockery::mock('WC_Order');
        $order->allows('get_meta')->with(WPCSTenant::WPCS_WEBSITE_NAME_META)->andReturn('Apex Research');
        $order->allows('get_billing_email')->andReturn('owner@apexresearch.example');
        $order->allows('get_items')->andReturn(array_map([$this, 'item'], [101, 102, 103]));

        $purchase = (new WooCommerceAdapter())->get_plan_purchase(900, $order);

        $this->assertEquals(
            [new PlanProduct(101, 'peptide-store', 'peptide-starter'), new PlanProduct(103, '', '')],
            $purchase->plans
        );
        $this->assertSame('Apex Research', $purchase->website_name);
        $this->assertSame('owner@apexresearch.example', $purchase->customer_email);
    }

    public function test_prefers_wp_swings_when_both_subscription_plugins_are_active(): void
    {
        $wp_swings = $this->plugin('wp-swings', 'wps_subscriptions', true);
        $woocommerce_subscriptions = $this->plugin('woocommerce-subscriptions', 'shop_subscription', true);

        $adapter = new WooCommerceAdapter([$wp_swings, $woocommerce_subscriptions]);

        $this->assertSame($wp_swings, $adapter->get_subscription_plugin());
    }

    public function test_uses_woocommerce_subscriptions_when_it_is_the_only_one_active(): void
    {
        $woocommerce_subscriptions = $this->plugin('woocommerce-subscriptions', 'shop_subscription', true);

        $adapter = new WooCommerceAdapter([$this->plugin('wp-swings', 'wps_subscriptions', false), $woocommerce_subscriptions]);

        $this->assertSame($woocommerce_subscriptions, $adapter->get_subscription_plugin());
    }

    public function test_meta_goes_through_the_order_api_and_only_for_the_plugins_subscriptions(): void
    {
        $subscription = Mockery::mock('WC_Order');
        $subscription->allows('get_type')->andReturn('wps_subscriptions');
        $subscription->allows('get_meta')->with(WPCSTenant::WPCS_TENANT_STATE, true)->andReturn(WPCSTenant::READY);
        $subscription->expects('update_meta_data')->with(WPCSTenant::WPCS_TENANT_STATE, WPCSTenant::DELETED);
        $subscription->expects('save');

        $plain_order = Mockery::mock('WC_Order');
        $plain_order->allows('get_type')->andReturn('shop_order');
        $plain_order->expects('update_meta_data')->never();

        Functions\when('wc_get_order')->alias(fn ($id) => [900 => $subscription, 901 => $plain_order][$id] ?? false);
        Functions\expect('get_post_meta')->never();
        Functions\expect('update_post_meta')->never();

        $adapter = new WooCommerceAdapter([$this->plugin('wp-swings', 'wps_subscriptions', true)]);

        $this->assertSame(WPCSTenant::READY, $adapter->get_subscription_meta(900, WPCSTenant::WPCS_TENANT_STATE));
        $adapter->update_subscription_meta(900, WPCSTenant::WPCS_TENANT_STATE, WPCSTenant::DELETED);

        $this->assertSame('', $adapter->get_subscription_meta(901, WPCSTenant::WPCS_TENANT_STATE));
        $adapter->update_subscription_meta(901, WPCSTenant::WPCS_TENANT_STATE, WPCSTenant::DELETED);
        $this->assertSame('', $adapter->get_subscription_meta(902, WPCSTenant::WPCS_TENANT_STATE));
    }

    public function test_find_subscriptions_queries_the_plugins_order_type(): void
    {
        $subscription = Mockery::mock('WC_Order');
        $subscription->allows('get_id')->andReturn(900);
        $subscription->allows('get_meta')->with(WPCSTenant::WPCS_TENANT_STATE, true)->andReturn(WPCSTenant::PROVISIONING);

        Functions\expect('wc_get_orders')->once()->with(Mockery::on(function ($args) {
            return $args['type'] === 'shop_subscription'
                && $args['limit'] === -1
                && $args['meta_query'][1] === ['key' => WPCSTenant::WPCS_TENANT_STATE, 'value' => [WPCSTenant::READY, WPCSTenant::DELETED], 'compare' => 'NOT IN'];
        }))->andReturn([$subscription]);

        // Posts storage ignores meta_query unless it is passed through to WP_Query.
        Filters\expectAdded('woocommerce_order_data_store_cpt_get_orders_query')->once()->whenHappen(function ($pass) {
            $passed = $pass(['post_type' => 'shop_subscription'], ['meta_query' => [['key' => 'k']]]);
            $this->assertSame([['key' => 'k']], $passed['meta_query']);
        });
        Filters\expectRemoved('woocommerce_order_data_store_cpt_get_orders_query')->once();

        $adapter = new WooCommerceAdapter([$this->plugin('woocommerce-subscriptions', 'shop_subscription', true)]);

        $this->assertSame(
            [900 => WPCSTenant::PROVISIONING],
            $adapter->find_subscriptions_by_meta(WPCSTenant::WPCS_TENANT_STATE, [WPCSTenant::READY, WPCSTenant::DELETED])
        );
    }

    public function test_customer_comes_from_the_subscription_plugin_and_unknown_ids_get_nobody(): void
    {
        $subscription = Mockery::mock('WC_Order');
        $subscription->allows('get_type')->andReturn('wps_subscriptions');
        $plugin = $this->plugin('wp-swings', 'wps_subscriptions', true);
        $plugin->allows('get_customer_id')->with($subscription)->andReturn(42);
        Functions\when('wc_get_order')->alias(fn ($id) => $id === 900 ? $subscription : false);

        $adapter = new WooCommerceAdapter([$plugin]);

        $this->assertSame(42, $adapter->get_customer_id('900'));
        $this->assertSame(0, $adapter->get_customer_id('123'));
        $this->assertSame(0, $adapter->get_customer_id(''));
        $this->assertSame(0, $adapter->get_customer_id('900 OR 1=1'));
    }

    private function plugin(string $id, string $order_type, bool $active)
    {
        $plugin = Mockery::mock(SubscriptionPlugin::class);
        $plugin->allows('get_id')->andReturn($id);
        $plugin->allows('get_order_type')->andReturn($order_type);
        $plugin->allows('is_active')->andReturn($active);

        return $plugin;
    }

    private function item(int $product_id)
    {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->allows('get_product_id')->andReturn($product_id);

        return $item;
    }
}

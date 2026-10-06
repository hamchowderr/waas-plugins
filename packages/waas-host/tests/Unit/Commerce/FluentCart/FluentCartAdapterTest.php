<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\FluentCart\FluentCartAdapter;
use WaaSHost\Commerce\FluentCart\FluentCartStore;
use WaaSHost\Commerce\PlanProduct;
use WaaSHost\Core\WPCSTenant;

class FluentCartAdapterTest extends TestCase
{
    private $store;
    private FluentCartAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Mockery::mock(FluentCartStore::class);
        $this->adapter = new FluentCartAdapter($this->store);
    }

    private function customer(int $user_id, string $email = 'owner@apexresearch.example', string $name = 'Jordan Reyes'): void
    {
        $this->store->allows('get_subscription_customer')->with(900)
            ->andReturn(['user_id' => $user_id, 'email' => $email, 'name' => $name]);
    }

    public function test_plan_purchase_reads_plan_settings_from_the_product_feeds(): void
    {
        $order = (object) ['id' => 500];
        $this->store->allows('get_order_product_ids')->with($order)->andReturn([101, 102]);
        $this->store->allows('get_plan_feed')->with(101)
            ->andReturn(['enabled' => 'yes', 'role' => 'peptide-store', 'group_name' => 'peptide-starter']);
        $this->store->allows('get_plan_feed')->with(102)->andReturnNull();
        $this->store->allows('get_order_meta')->with($order, WPCSTenant::WPCS_WEBSITE_NAME_META)->andReturn('Apex Research');
        $this->customer(7);

        $purchase = $this->adapter->get_plan_purchase(900, $order);

        $this->assertEquals([new PlanProduct(101, 'peptide-store', 'peptide-starter')], $purchase->plans);
        $this->assertSame('Apex Research', $purchase->website_name);
        $this->assertSame('owner@apexresearch.example', $purchase->customer_email);
    }

    public function test_linked_customer_is_the_owner(): void
    {
        $this->customer(7);
        Functions\expect('get_user_by')->never();

        $this->assertSame(7, $this->adapter->get_customer_id(900));
    }

    public function test_unlinked_customer_falls_back_to_the_account_with_their_email(): void
    {
        $this->customer(0);
        Functions\expect('get_user_by')->once()->with('email', 'owner@apexresearch.example')->andReturn((object) ['ID' => 12]);

        $this->assertSame(12, $this->adapter->get_customer_id(900));
    }

    public function test_unknown_subscription_has_no_owner(): void
    {
        $this->store->allows('get_subscription_customer')->with(900)->andReturnNull();

        $this->assertSame(0, $this->adapter->get_customer_id(900));
        $this->assertSame('', $this->adapter->get_customer_email(900));
        $this->assertSame('', $this->adapter->get_customer_name(900));
    }

    public function test_customer_name_falls_back_to_the_email_name(): void
    {
        $this->customer(7, 'jordan@apexresearch.example', '');

        $this->assertSame('jordan', $this->adapter->get_customer_name(900));
    }

    public function test_subscription_meta_goes_to_fluentcart(): void
    {
        $this->store->expects('update_subscription_meta')->with(900, WPCSTenant::WPCS_DOMAIN_NAME_META, 'shop.example.com');
        $this->store->allows('get_subscription_meta')->with(900, WPCSTenant::WPCS_DOMAIN_NAME_META)->andReturn('shop.example.com');
        $this->store->allows('find_subscription_meta')->with(WPCSTenant::WPCS_TENANT_STATE, [WPCSTenant::READY])->andReturn([900 => WPCSTenant::PROVISIONING]);

        $this->adapter->update_subscription_meta(900, WPCSTenant::WPCS_DOMAIN_NAME_META, 'shop.example.com');

        $this->assertSame('shop.example.com', $this->adapter->get_subscription_meta(900, WPCSTenant::WPCS_DOMAIN_NAME_META));
        $this->assertSame([900 => WPCSTenant::PROVISIONING], $this->adapter->find_subscriptions_by_meta(WPCSTenant::WPCS_TENANT_STATE, [WPCSTenant::READY]));
    }

    public function test_checklist_plan_item_is_done_once_a_product_has_the_feed(): void
    {
        $this->store->allows('has_plan_feed')->andReturn(true);

        $items = $this->adapter->mark_plan_product_checklist_item(['create_woo_wpcs_product' => ['label' => 'x', 'is_done' => false]]);

        $this->assertTrue($items['create_woo_wpcs_product']['is_done']);
    }
}

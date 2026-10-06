<?php

namespace Tests\Unit\Commerce\WooCommerce;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\PlanProduct;
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
            500 => [WPCSTenant::WPCS_WEBSITE_NAME_META => 'Apex Research'],
        ];
        Functions\when('get_post_meta')->alias(fn ($id, $key) => $post_meta[$id][$key] ?? '');

        $order = Mockery::mock('WC_Order');
        $order->allows('get_id')->andReturn(500);
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

    private function item(int $product_id)
    {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->allows('get_product_id')->andReturn($product_id);

        return $item;
    }
}

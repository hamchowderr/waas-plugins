<?php

namespace Tests\Unit\Core;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSHost\Core\StoreProduct;

class StoreProductTest extends TestCase
{
    public function test_reads_the_old_keys_until_the_product_is_migrated(): void
    {
        $store_product = new StoreProduct(new \WC_Product(7, [
            StoreProduct::LEGACY_PRODUCT_ROLE_META => 'peptide-store',
            StoreProduct::LEGACY_PRODUCT_GROUPNAME_META => 'peptide-starter',
        ]));

        $this->assertSame('peptide-store', $store_product->get_role());
        $this->assertSame('peptide-starter', $store_product->get_tenant_snapshot_group_name());
    }

    public function test_a_missing_product_has_no_settings(): void
    {
        Functions\when('wc_get_product')->justReturn(false);

        $store_product = new StoreProduct(404);

        $this->assertFalse($store_product->is_store_product());
        $this->assertSame('', $store_product->get_role());
        $store_product->update_role('peptide-store');
        $store_product->save();
    }

    public function test_migration_moves_the_old_keys_and_marks_old_plan_products_once(): void
    {
        $products = [
            // A plan from before the WaaS product checkbox existed.
            1 => new \WC_Product(1, [StoreProduct::LEGACY_PRODUCT_ROLE_META => 'peptide-store', StoreProduct::LEGACY_PRODUCT_GROUPNAME_META => 'peptide-starter']),
            // A product the owner unticked: it stays unticked.
            2 => new \WC_Product(2, ['_is_wpcs_product' => 'no', StoreProduct::LEGACY_PRODUCT_ROLE_META => 'crm']),
        ];
        Functions\when('get_posts')->justReturn([1, 2]);
        Functions\when('wc_get_product')->alias(fn ($id) => $products[$id] ?? false);

        $this->assertSame(2, StoreProduct::migrate_legacy_meta());

        $this->assertSame([
            '_is_wpcs_product' => 'yes',
            StoreProduct::PRODUCT_ROLE_META => 'peptide-store',
            StoreProduct::PRODUCT_GROUPNAME_META => 'peptide-starter',
        ], $products[1]->meta);
        $this->assertSame([
            '_is_wpcs_product' => 'no',
            StoreProduct::PRODUCT_ROLE_META => 'crm',
        ], $products[2]->meta);
        $this->assertSame(1, $products[1]->saves);
        $this->assertSame(1, $products[2]->saves);
    }
}

<?php

namespace Tests\Unit\Core;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use AnyPressHost\Core\StoreProduct;

class StoreProductTest extends TestCase
{
    public function test_reads_the_role_and_snapshot_group(): void
    {
        $store_product = new StoreProduct(new \WC_Product(7, [
            StoreProduct::PRODUCT_ROLE_META => 'peptide-store',
            StoreProduct::PRODUCT_GROUPNAME_META => 'peptide-starter',
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

}

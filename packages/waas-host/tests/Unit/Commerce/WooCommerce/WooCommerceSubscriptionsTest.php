<?php

namespace Tests\Unit\Commerce\WooCommerce;

use Tests\Unit\TestCase;
use WaaSHost\Commerce\WooCommerce\WooCommerceSubscriptions;
use WaaSHost\Core\Tenant;

class WooCommerceSubscriptionsTest extends TestCase
{
    /** M3: renewal orders get the subscription's meta, but not the store's login key. */
    public function test_renewal_orders_do_not_get_the_login_key(): void
    {
        $data = [
            Tenant::TENANT_PRIVATE_KEY_META => 'waasenc:v1:abc',
            Tenant::LEGACY_TENANT_PRIVATE_KEY_META => 'waasenc:v1:def',
            Tenant::TENANT_EXTERNAL_ID_META => 'ext-1',
        ];

        $filtered = (new WooCommerceSubscriptions())->exclude_secret_meta_from_renewals($data);

        $this->assertSame([Tenant::TENANT_EXTERNAL_ID_META => 'ext-1'], $filtered);
    }

    public function test_anything_but_an_array_is_passed_through(): void
    {
        $this->assertNull((new WooCommerceSubscriptions())->exclude_secret_meta_from_renewals(null));
    }
}

<?php

namespace Tests\Commerce;

use PHPUnit\Framework\TestCase;
use Tests\FakeCommerceAdapter;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\HttpService;
use WaaSHost\Core\WPCSService;
use WpStub;

class CommerceTest extends TestCase
{
    protected function setUp(): void
    {
        WpStub::reset();
        Commerce::reset();
    }

    public function test_no_adapter_when_no_cart_is_active()
    {
        Commerce::register(new FakeCommerceAdapter('woocommerce', false));

        $this->assertNull(Commerce::adapter());
    }

    public function test_first_available_adapter_wins()
    {
        Commerce::register(new FakeCommerceAdapter('woocommerce', false));
        Commerce::register($fluentcart = new FakeCommerceAdapter('fluentcart', true));
        Commerce::register(new FakeCommerceAdapter('other', true));

        $this->assertSame($fluentcart, Commerce::adapter());
    }

    public function test_filter_can_choose_the_adapter()
    {
        Commerce::register(new FakeCommerceAdapter('woocommerce', true));
        Commerce::register($fluentcart = new FakeCommerceAdapter('fluentcart', true));
        WpStub::$filters['wpcs_commerce_adapter'][] = fn ($default, $all) => $all['fluentcart'];

        $this->assertSame($fluentcart, Commerce::adapter());
    }

    public function test_require_adapter_throws_without_a_cart()
    {
        $this->expectException(\LogicException::class);
        Commerce::require_adapter();
    }

    public function test_boot_boots_only_the_active_adapter()
    {
        Commerce::register($inactive = new FakeCommerceAdapter('woocommerce', false));
        Commerce::register($active = new FakeCommerceAdapter('fluentcart', true));

        Commerce::boot(new WPCSService(new HttpService(new ConfigService())));

        $this->assertTrue($active->booted);
        $this->assertFalse($inactive->booted);
    }
}

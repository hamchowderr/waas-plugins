<?php

namespace Tests\Unit\Commerce;

use Brain\Monkey\Filters;
use Mockery;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ProvisioningApi;

class CommerceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Commerce::reset();
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    public function test_no_adapter_when_no_cart_is_active(): void
    {
        Commerce::register(new FakeCommerceAdapter('woocommerce', false));

        $this->assertNull(Commerce::adapter());
    }

    public function test_first_available_adapter_wins(): void
    {
        Commerce::register(new FakeCommerceAdapter('woocommerce', false));
        Commerce::register($fluentcart = new FakeCommerceAdapter('fluentcart', true));
        Commerce::register(new FakeCommerceAdapter('other', true));

        $this->assertSame($fluentcart, Commerce::adapter());
    }

    public function test_filter_can_choose_the_adapter(): void
    {
        Commerce::register(new FakeCommerceAdapter('woocommerce', true));
        Commerce::register($fluentcart = new FakeCommerceAdapter('fluentcart', true));
        Filters\expectApplied('waas_commerce_adapter')->once()->andReturnUsing(fn ($default, $all) => $all['fluentcart']);

        $this->assertSame($fluentcart, Commerce::adapter());
    }

    public function test_require_adapter_throws_without_a_cart(): void
    {
        $this->expectException(\LogicException::class);
        Commerce::require_adapter();
    }

    public function test_boot_boots_only_the_active_adapter(): void
    {
        Commerce::register($inactive = new FakeCommerceAdapter('woocommerce', false));
        Commerce::register($active = new FakeCommerceAdapter('fluentcart', true));

        Commerce::boot(Mockery::mock(ProvisioningApi::class));

        $this->assertTrue($active->booted);
        $this->assertFalse($inactive->booted);
    }
}

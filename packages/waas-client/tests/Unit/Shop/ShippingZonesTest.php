<?php

namespace Tests\Unit\Shop;

use Tests\Unit\TestCase;
use WaaSClient\Shop\ShippingZones;

class ShippingZonesTest extends TestCase
{
    public function test_one_country_is_that_country(): void
    {
        $where = ShippingZones::zone_region([['code' => 'US', 'type' => 'country']]);

        $this->assertSame(['region' => 'US', 'meta' => null, 'states' => [], 'lost' => []], $where);
    }

    public function test_several_countries_and_a_continent_are_a_selection(): void
    {
        $where = ShippingZones::zone_region(
            [['code' => 'CA', 'type' => 'country'], ['code' => 'EU', 'type' => 'continent']],
            ['EU' => ['FR', 'DE']]
        );

        $this->assertSame('selection', $where['region']);
        $this->assertSame(['countries' => ['CA', 'DE', 'FR'], 'selection_type' => 'included'], $where['meta']);
        $this->assertSame([], $where['lost']);
    }

    public function test_states_of_one_country_limit_the_methods(): void
    {
        $where = ShippingZones::zone_region([['code' => 'US:CA', 'type' => 'state'], ['code' => 'US:NV', 'type' => 'state']]);

        $this->assertSame('US', $where['region']);
        $this->assertSame(['CA', 'NV'], $where['states']);
        $this->assertSame([], $where['lost']);
    }

    public function test_states_mixed_with_other_countries_and_postcodes_are_reported(): void
    {
        $where = ShippingZones::zone_region([
            ['code' => 'US:CA', 'type' => 'state'],
            ['code' => 'CA', 'type' => 'country'],
            ['code' => '9021*', 'type' => 'postcode'],
        ]);

        $this->assertSame('selection', $where['region']);
        $this->assertSame(['CA', 'US'], $where['meta']['countries']);
        $this->assertSame([], $where['states']);
        $this->assertCount(2, $where['lost']);
        $this->assertStringContainsString('US: CA', $where['lost'][0]);
        $this->assertStringContainsString('9021*', $where['lost'][1]);
    }

    public function test_no_locations_is_the_whole_world(): void
    {
        $this->assertSame('all', ShippingZones::zone_region([])['region']);
    }

    public function test_flat_rate_keeps_its_cost_and_title(): void
    {
        $copy = ShippingZones::method_row('flat_rate', ['title' => 'Flat rate', 'cost' => '10.00', 'tax_status' => 'taxable'], true, ['CA'], 1);

        $this->assertSame([], $copy['lost']);
        $this->assertSame('Flat rate', $copy['row']['title']);
        $this->assertSame('flat_rate', $copy['row']['type']);
        $this->assertSame(10.0, $copy['row']['amount']);
        $this->assertSame('per_order', $copy['row']['settings']['configure_rate']);
        $this->assertSame(1, $copy['row']['is_enabled']);
        $this->assertSame(['CA'], $copy['row']['states']);
    }

    public function test_flat_rate_per_item_becomes_per_item(): void
    {
        $copy = ShippingZones::method_row('flat_rate', ['cost' => '4 * [qty]'], true, [], 0);

        $this->assertSame(4.0, $copy['row']['amount']);
        $this->assertSame('per_item', $copy['row']['settings']['configure_rate']);
    }

    public function test_flat_rate_formula_is_not_copied(): void
    {
        $copy = ShippingZones::method_row('flat_rate', ['title' => 'Bulky', 'cost' => '10 + [fee percent="10"]'], true, [], 0);

        $this->assertNull($copy['row']);
        $this->assertStringContainsString('Bulky', $copy['lost'][0]);
    }

    public function test_flat_rate_class_costs_are_reported(): void
    {
        $copy = ShippingZones::method_row('flat_rate', ['cost' => '5', 'class_cost_12' => '3'], true, [], 0);

        $this->assertNotNull($copy['row']);
        $this->assertStringContainsString('shipping class costs', $copy['lost'][0]);
    }

    public function test_free_shipping_without_condition_is_on(): void
    {
        $copy = ShippingZones::method_row('free_shipping', ['title' => 'Free shipping', 'requires' => ''], true, [], 2);

        $this->assertSame(1, $copy['row']['is_enabled']);
        $this->assertSame([], $copy['lost']);
    }

    public function test_free_shipping_with_a_minimum_comes_across_turned_off(): void
    {
        $copy = ShippingZones::method_row('free_shipping', ['requires' => 'min_amount', 'min_amount' => '50'], true, [], 2);

        $this->assertSame(0, $copy['row']['is_enabled']);
        $this->assertStringContainsString('a minimum order of 50', $copy['lost'][0]);
    }

    public function test_unknown_method_is_not_copied(): void
    {
        $copy = ShippingZones::method_row('table_rate', ['title' => 'Table'], true, [], 0);

        $this->assertNull($copy['row']);
        $this->assertStringContainsString('table_rate', $copy['lost'][0]);
    }
}

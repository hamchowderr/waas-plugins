<?php

namespace Tests\Unit\Shop;

use Tests\Unit\TestCase;
use WaaSClient\Shop\ShopReview;

class ShopReviewTest extends TestCase
{
    private function woo(int $id, string $country, string $state, string $rate, string $name, array $extra = []): array
    {
        return array_merge([
            'tax_rate_id' => (string) $id, 'tax_rate_country' => $country, 'tax_rate_state' => $state, 'tax_rate' => $rate,
            'tax_rate_name' => $name, 'tax_rate_shipping' => '0', 'tax_rate_compound' => '0', 'tax_rate_class' => '',
            'postcodes' => [], 'cities' => [],
        ], $extra);
    }

    private function fluent(int $id, string $country, string $state, string $rate, string $name): array
    {
        return ['id' => (string) $id, 'country' => $country, 'state' => $state, 'rate' => $rate, 'name' => $name, 'for_shipping' => null, 'is_compound' => '0'];
    }

    public function test_a_rate_that_matches_is_not_listed(): void
    {
        $rows = ShopReview::tax_differences(
            [$this->woo(1, 'US', 'CA', '7.2500', 'CA Standard')],
            [16 => $this->fluent(16, 'US', 'CA', '7.25', 'CA Standard')],
            [1 => 16]
        );

        $this->assertSame([], $rows);
    }

    public function test_lists_a_different_value_name_and_shipping_flag(): void
    {
        $rows = ShopReview::tax_differences(
            [$this->woo(1, 'US', 'CA', '8.0000', 'CA Sales Tax', ['tax_rate_shipping' => '1'])],
            [16 => $this->fluent(16, 'US', 'CA', '7.25', 'California State Tax (Standard)')],
            [1 => 16]
        );

        $this->assertCount(1, $rows);
        $this->assertSame('US CA', $rows[0]['place']);
        $this->assertSame('CA Sales Tax 8%', $rows[0]['woocommerce']);
        $this->assertSame('California State Tax (Standard) 7.25%', $rows[0]['fluentcart']);
        $this->assertSame([
            'Rate: WooCommerce 8%, FluentCart 7.25%.',
            'Name: WooCommerce "CA Sales Tax", FluentCart "California State Tax (Standard)".',
            'WooCommerce also taxes shipping at this rate; the FluentCart rate does not say so.',
        ], $rows[0]['differences']);
    }

    public function test_before_the_switch_compares_with_the_rate_the_add_on_would_pick(): void
    {
        $rows = ShopReview::tax_differences(
            [$this->woo(2, 'GB', '', '20.0000', 'VAT'), $this->woo(3, 'FR', '', '20.0000', 'TVA')],
            [],
            [],
            ['GB:' => $this->fluent(1, 'GB', '', '20', 'GB Standard Tax')]
        );

        $this->assertSame(['Name: WooCommerce "VAT", FluentCart "GB Standard Tax".'], $rows[0]['differences']);
        $this->assertSame('-', $rows[1]['fluentcart']);
    }

    public function test_postcodes_and_tax_classes_are_listed(): void
    {
        $rows = ShopReview::tax_differences(
            [$this->woo(1, 'US', 'CA', '7.25', 'CA', ['postcodes' => ['90210'], 'tax_rate_class' => 'reduced-rate'])],
            [16 => $this->fluent(16, 'US', 'CA', '7.25', 'CA')],
            [1 => 16]
        );

        $this->assertCount(2, $rows[0]['differences']);
        $this->assertStringContainsString('90210', $rows[0]['differences'][0]);
        $this->assertStringContainsString('reduced-rate', $rows[0]['differences'][1]);
    }
}

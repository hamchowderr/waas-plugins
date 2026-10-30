<?php

namespace Tests\Unit\Shop;

use Tests\Unit\TestCase;
use WaaSClient\Shop\ShopCompare;

class ShopCompareTest extends TestCase
{
    private function woo(): array
    {
        return [
            'products' => [13 => 'T-Shirt', 14 => 'Jacket'],
            'skus' => ['TS-001', 'JK-001-S', 'JK-001-M'],
            'orders' => [43 => ['total' => 6972, 'refunded' => 0], 46 => ['total' => 4000, 'refunded' => 1200]],
            'refunds' => ['count' => 1, 'total' => 1200],
            'coupons' => ['save10'],
            'users' => [2 => 'alice@example.com', 4 => 'carol@example.com'],
            'guests' => ['guest1@example.com', 'alice@example.com'],
        ];
    }

    private function fluent(): array
    {
        return [
            'products' => [13 => 53, 14 => 54],
            'skus' => ['TS-001', 'JK-001-S', 'JK-001-M'],
            'orders' => [43 => ['total' => 6972, 'refunded' => 0], 46 => ['total' => 4000, 'refunded' => 1200]],
            'refunds' => ['count' => 1, 'total' => 1200],
            'coupons' => ['save10'],
            'customers' => [
                ['id' => '1', 'user_id' => '2', 'email' => 'alice@example.com'],
                ['id' => '2', 'user_id' => '4', 'email' => 'carol@example.com'],
                ['id' => '3', 'user_id' => '0', 'email' => 'guest1@example.com'],
            ],
        ];
    }

    private function rows(array $woo, array $fc): array
    {
        $rows = [];
        foreach (ShopCompare::compare($woo, $fc) as $row) {
            $rows[$row['item']] = $row;
        }

        return $rows;
    }

    public function test_the_same_shop_has_no_differences(): void
    {
        $rows = $this->rows($this->woo(), $this->fluent());

        $this->assertSame(['Products', 'SKUs', 'Orders', 'Sum of order totals', 'Refunds', 'Coupons', 'Customers'], array_keys($rows));
        foreach ($rows as $row) {
            $this->assertTrue($row['same'], $row['item'] . ': ' . implode('; ', $row['differences']));
        }
        $this->assertSame('109.72', $rows['Sum of order totals']['woocommerce']);
        $this->assertSame('3 (2 registered, 1 guests)', $rows['Customers']['woocommerce']);
    }

    public function test_lists_every_difference(): void
    {
        $fc = $this->fluent();
        unset($fc['products'][14]);
        $fc['skus'] = ['TS-001', 'JK-001-S', 'EXTRA'];
        $fc['orders'][46] = ['total' => 3900, 'refunded' => 0];
        $fc['orders'][99] = ['total' => 200, 'refunded' => 0];
        $fc['refunds'] = ['count' => 0, 'total' => 0];
        $fc['coupons'] = [];
        $fc['customers'][] = ['id' => '4', 'user_id' => '4', 'email' => 'carol.work@example.com'];
        unset($fc['customers'][2]);

        $rows = $this->rows($this->woo(), $fc);

        $this->assertSame(['Not in FluentCart: #14 Jacket'], $rows['Products']['differences']);
        $this->assertSame(['Not in FluentCart: JK-001-M', 'Only in FluentCart: EXTRA'], $rows['SKUs']['differences']);
        $this->assertSame([
            'Order #46 total: WooCommerce 40.00, FluentCart 39.00',
            'Only in FluentCart: order #99 (2.00)',
        ], $rows['Orders']['differences']);
        $this->assertSame(['Off by 1.00'], $rows['Sum of order totals']['differences']);
        $this->assertSame([
            'Refunded in all: WooCommerce 12.00, FluentCart 0.00',
            'WooCommerce has 1 refunds, FluentCart 0 refund transactions',
            'Order #46 refunded: WooCommerce 12.00, FluentCart 0.00',
        ], $rows['Refunds']['differences']);
        $this->assertSame(['Not in FluentCart: save10'], $rows['Coupons']['differences']);
        $this->assertSame([
            'User #4 has 2 FluentCart customers: carol@example.com, carol.work@example.com',
            'Not in FluentCart: guest guest1@example.com',
        ], $rows['Customers']['differences']);
    }
}

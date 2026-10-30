<?php

namespace WaaSClient\Shop;

/**
 * WooCommerce next to FluentCart after the switch: products, SKUs, orders, the sum of order totals, refunds,
 * coupons and customers, each with every difference listed. Read only. Amounts are compared in cents.
 */
class ShopCompare
{
    /** Differences listed per row before "and N more". */
    private const LIST = 50;

    /**
     * @param array $woo products (id => name), skus (list), orders (id => [total, refunded] in cents),
     *                   refunds ([count, total]), coupons (codes), users (user id => email), guests (emails).
     * @param array $fc  products (WooCommerce id => FluentCart id), skus, orders (id => [total, refunded]),
     *                   refunds ([count, total]), coupons, customers (rows with id, user_id, email).
     * @return array<int, array{item: string, woocommerce: string, fluentcart: string, same: bool, differences: string[]}>
     */
    public static function compare(array $woo, array $fc): array
    {
        $rows = [];

        $missing = array_diff_key($woo['products'], $fc['products']);
        $rows[] = self::row('Products', count($woo['products']), count($fc['products']), array_map(
            fn ($id, $name) => sprintf('Not in FluentCart: #%d %s', $id, $name),
            array_keys($missing),
            $missing
        ));

        $woo_skus = self::counts($woo['skus']);
        $fc_skus = self::counts($fc['skus']);
        $sku_diff = [];
        foreach ($woo_skus as $sku => $count) {
            $have = $fc_skus[$sku] ?? 0;
            if ($have < $count) {
                $sku_diff[] = sprintf('Not in FluentCart: %s', $sku);
            }
        }
        foreach ($fc_skus as $sku => $count) {
            if (!isset($woo_skus[$sku])) {
                $sku_diff[] = sprintf('Only in FluentCart: %s', $sku);
            } elseif ($count > $woo_skus[$sku]) {
                $sku_diff[] = sprintf('%s is on %d FluentCart variations', $sku, $count);
            }
        }
        $rows[] = self::row('SKUs', count($woo['skus']), count($fc['skus']), $sku_diff);

        $order_diff = [];
        $woo_sum = 0;
        $fc_sum = 0;
        $refund_diff = [];
        foreach ($woo['orders'] as $id => $order) {
            $woo_sum += (int) $order['total'];
            if (!isset($fc['orders'][$id])) {
                $order_diff[] = sprintf('Not in FluentCart: order #%d (%s)', $id, self::money((int) $order['total']));
                continue;
            }
            $copy = $fc['orders'][$id];
            if ((int) $copy['total'] !== (int) $order['total']) {
                $order_diff[] = sprintf('Order #%d total: WooCommerce %s, FluentCart %s', $id, self::money((int) $order['total']), self::money((int) $copy['total']));
            }
            if ((int) $copy['refunded'] !== (int) $order['refunded']) {
                $refund_diff[] = sprintf('Order #%d refunded: WooCommerce %s, FluentCart %s', $id, self::money((int) $order['refunded']), self::money((int) $copy['refunded']));
            }
        }
        foreach ($fc['orders'] as $id => $copy) {
            $fc_sum += (int) $copy['total'];
            if (!isset($woo['orders'][$id])) {
                $order_diff[] = sprintf('Only in FluentCart: order #%d (%s)', $id, self::money((int) $copy['total']));
            }
        }
        $rows[] = self::row('Orders', count($woo['orders']), count($fc['orders']), $order_diff);
        $rows[] = self::row('Sum of order totals', self::money($woo_sum), self::money($fc_sum), $woo_sum === $fc_sum ? [] : [sprintf('Off by %s', self::money($fc_sum - $woo_sum))]);

        if ((int) $woo['refunds']['count'] !== (int) $fc['refunds']['count']) {
            array_unshift($refund_diff, sprintf('WooCommerce has %d refunds, FluentCart %d refund transactions', $woo['refunds']['count'], $fc['refunds']['count']));
        }
        if ((int) $woo['refunds']['total'] !== (int) $fc['refunds']['total']) {
            array_unshift($refund_diff, sprintf('Refunded in all: WooCommerce %s, FluentCart %s', self::money((int) $woo['refunds']['total']), self::money((int) $fc['refunds']['total'])));
        }
        $rows[] = self::row('Refunds', sprintf('%d (%s)', $woo['refunds']['count'], self::money((int) $woo['refunds']['total'])), sprintf('%d (%s)', $fc['refunds']['count'], self::money((int) $fc['refunds']['total'])), $refund_diff);

        $woo_codes = array_map('strtolower', $woo['coupons']);
        $fc_codes = array_map('strtolower', $fc['coupons']);
        $rows[] = self::row('Coupons', count($woo_codes), count($fc_codes), array_merge(
            array_map(fn ($code) => 'Not in FluentCart: ' . $code, array_values(array_diff($woo_codes, $fc_codes))),
            array_map(fn ($code) => 'Only in FluentCart: ' . $code, array_values(array_diff($fc_codes, $woo_codes)))
        ));

        $rows[] = self::customers($woo, $fc['customers']);

        return $rows;
    }

    private static function customers(array $woo, array $customers): array
    {
        $by_user = [];
        $by_email = [];
        foreach ($customers as $customer) {
            if ((int) $customer['user_id'] > 0) {
                $by_user[(int) $customer['user_id']][] = $customer;
            }
            $by_email[strtolower((string) $customer['email'])] = $customer;
        }
        $diff = [];
        $accounted = [];
        foreach ($woo['users'] as $user => $email) {
            $email = strtolower((string) $email);
            if (empty($by_user[$user]) && !isset($by_email[$email])) {
                $diff[] = sprintf('Not in FluentCart: user #%d %s', $user, $email);
                continue;
            }
            if (count($by_user[$user] ?? []) > 1) {
                $diff[] = sprintf('User #%d has %d FluentCart customers: %s', $user, count($by_user[$user]), implode(', ', array_column($by_user[$user], 'email')));
            }
            foreach ($by_user[$user] ?? [$by_email[$email]] as $customer) {
                $accounted[(int) $customer['id']] = true;
            }
        }
        $users = array_flip(array_map('strtolower', $woo['users']));
        $guests = 0;
        foreach (array_unique(array_map('strtolower', $woo['guests'])) as $email) {
            if (isset($users[$email])) {
                continue;
            }
            $guests++;
            if (!isset($by_email[$email])) {
                $diff[] = sprintf('Not in FluentCart: guest %s', $email);
                continue;
            }
            $accounted[(int) $by_email[$email]['id']] = true;
        }
        foreach ($customers as $customer) {
            if (!isset($accounted[(int) $customer['id']]) && ((int) $customer['user_id'] === 0 || !isset($woo['users'][(int) $customer['user_id']]))) {
                $diff[] = sprintf('Only in FluentCart: customer #%d %s', $customer['id'], $customer['email']);
            }
        }

        return self::row('Customers', sprintf('%d (%d registered, %d guests)', count($woo['users']) + $guests, count($woo['users']), $guests), count($customers), $diff);
    }

    private static function row(string $item, $woo, $fc, array $differences): array
    {
        if (count($differences) > self::LIST) {
            $more = count($differences) - self::LIST;
            $differences = array_slice($differences, 0, self::LIST);
            $differences[] = sprintf('and %d more', $more);
        }

        return ['item' => $item, 'woocommerce' => (string) $woo, 'fluentcart' => (string) $fc, 'same' => !$differences, 'differences' => $differences];
    }

    private static function counts(array $values): array
    {
        $counts = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private static function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . number_format(abs($cents) / 100, 2, '.', ',');
    }

    /** Reads both shops and compares them. */
    public static function run(): array
    {
        return self::compare(self::woo(), self::fluent());
    }

    private static function cents($amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /** WooCommerce's side, through its own API (so HPOS on or off reads the same). */
    public static function woo(): array
    {
        $data = ['products' => [], 'skus' => [], 'orders' => [], 'refunds' => ['count' => 0, 'total' => 0], 'coupons' => [], 'users' => [], 'guests' => []];
        // The statuses the add-on reads.
        $page = 1;
        do {
            $products = wc_get_products(['status' => ['publish', 'private', 'draft'], 'limit' => 200, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC']);
            foreach ($products as $product) {
                $data['products'][$product->get_id()] = $product->get_name();
                if ($product->is_type('variable')) {
                    foreach ($product->get_children() as $child) {
                        $variation = wc_get_product($child);
                        if ($variation && $variation->get_sku() !== '') {
                            $data['skus'][] = $variation->get_sku();
                        }
                    }
                } elseif ($product->get_sku() !== '') {
                    $data['skus'][] = $product->get_sku();
                }
            }
            $page++;
        } while (count($products) === 200);

        $page = 1;
        do {
            $orders = wc_get_orders(['type' => 'shop_order', 'status' => 'any', 'limit' => 200, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC']);
            foreach ($orders as $order) {
                $refunded = self::cents($order->get_total_refunded());
                $data['orders'][$order->get_id()] = ['total' => self::cents($order->get_total()), 'refunded' => $refunded];
                $data['refunds']['count'] += count($order->get_refunds());
                $data['refunds']['total'] += $refunded;
                if ($order->get_customer_id()) {
                    $user = get_userdata($order->get_customer_id());
                    if ($user) {
                        $data['users'][$user->ID] = $user->user_email;
                    }
                } elseif ($order->get_billing_email() !== '') {
                    $data['guests'][] = $order->get_billing_email();
                }
            }
            $page++;
        } while (count($orders) === 200);

        // The registered customers the add-on brings across even without orders.
        foreach (get_users(['role__in' => ['customer', 'subscriber'], 'fields' => ['ID', 'user_email']]) as $user) {
            $data['users'][(int) $user->ID] = $user->user_email;
        }
        ksort($data['users']);
        foreach (get_posts(['post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => -1]) as $coupon) {
            $data['coupons'][] = wc_format_coupon_code($coupon->post_title);
        }

        return $data;
    }

    /** FluentCart's side: what the add-on brought across from WooCommerce. */
    public static function fluent(): array
    {
        global $wpdb;
        $data = ['products' => [], 'skus' => [], 'orders' => [], 'refunds' => ['count' => 0, 'total' => 0], 'coupons' => [], 'customers' => []];
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'fct_orders'))) {
            return $data;
        }
        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT m.meta_value AS woo_id, p.ID FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = %s",
            '_wc_migrated_from',
            'fluent-products'
        ));
        foreach ((array) $links as $link) {
            $data['products'][(int) $link->woo_id] = (int) $link->ID;
        }
        if ($data['products']) {
            $ids = implode(',', array_map('intval', $data['products']));
            $data['skus'] = array_map('strval', (array) $wpdb->get_col("SELECT sku FROM {$wpdb->prefix}fct_product_variations WHERE post_id IN ({$ids}) AND sku IS NOT NULL AND sku != ''"));
        }
        $orders = $wpdb->get_results($wpdb->prepare("SELECT id, total_amount, total_refund FROM {$wpdb->prefix}fct_orders WHERE config LIKE %s AND config LIKE %s", '%migrated_from%', '%woocommerce%'));
        foreach ((array) $orders as $order) {
            $data['orders'][(int) $order->id] = ['total' => (int) $order->total_amount, 'refunded' => (int) $order->total_refund];
        }
        if ($data['orders']) {
            $ids = implode(',', array_keys($data['orders']));
            $refunds = $wpdb->get_row("SELECT COUNT(*) AS c, COALESCE(SUM(total), 0) AS t FROM {$wpdb->prefix}fct_order_transactions WHERE transaction_type = 'refund' AND order_id IN ({$ids})");
            $data['refunds'] = ['count' => (int) $refunds->c, 'total' => (int) $refunds->t];
        }
        $data['coupons'] = array_map('strval', (array) $wpdb->get_col("SELECT code FROM {$wpdb->prefix}fct_coupons"));
        $data['customers'] = (array) $wpdb->get_results("SELECT id, user_id, email FROM {$wpdb->prefix}fct_customers ORDER BY id", ARRAY_A);

        return $data;
    }
}

<?php

namespace AnyPressClient\Moving;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Subscription;

/**
 * MemberPress's Stripe subscriptions into FluentCart, so they keep renewing there: the MemberPress switch's "billing"
 * part, run only when it is asked for and FluentCart is active.
 *
 * Stripe holds the subscription and the card and goes on charging on its own schedule, whichever plugin listens. So
 * nothing is charged or changed in Stripe: each active MemberPress subscription on a Stripe gateway gets a FluentCart
 * subscription with the same Stripe subscription id (as FluentCart's own EDD migrator does), and FluentCart's Stripe
 * webhook then records each renewal Stripe charges against it. Each membership becomes a FluentCart subscription
 * product (made by FluentCart's own BulkProductInsertService) with its price and billing period; each member gets a
 * FluentCart customer, a parent order for the subscription and the subscription itself, all through FluentCart's
 * models. Undo deletes only those.
 *
 * For FluentCart to see the renewals, its Stripe settings must be connected to the same Stripe account and its webhook
 * added in Stripe; then switch off MemberPress's Stripe gateway so a renewal isn't recorded twice. PayPal and other
 * gateways' subscriptions stay in MemberPress (PayPal can't hand its subscriptions to another plugin).
 */
class MemberPressBilling
{
    /** MemberPress's billing periods, as FluentCart's intervals: [period type, length] => interval. */
    private const INTERVALS = [
        'days:1' => 'daily',
        'weeks:1' => 'weekly',
        'months:1' => 'monthly',
        'months:3' => 'quarterly',
        'months:6' => 'half_yearly',
        'months:12' => 'yearly',
        'years:1' => 'yearly',
    ];

    public static function fluentcart_active(): bool
    {
        return defined('FLUENTCART_VERSION') && class_exists(Subscription::class);
    }

    /**
     * Active MemberPress subscriptions on a Stripe gateway, by membership: membership id => list of
     * ['id', 'user_id', 'stripe_id', 'customer', 'price', 'tax', 'total', 'interval', 'times', 'next', 'created', 'mode'].
     * One whose billing period FluentCart has no interval for is listed under "unsupported" instead.
     *
     * @return array{by_membership: array<int, array<int, array>>, unsupported: array<int, array>, currency: string}
     */
    public static function stripe_subscriptions(): array
    {
        $options = (array) get_option('mepr_options', []);
        $gateways = [];
        foreach ((array) ($options['integrations'] ?? []) as $id => $gateway) {
            $gateway = (array) $gateway;
            if (($gateway['gateway'] ?? '') === 'MeprStripeGateway') {
                $test = $gateway['test_mode'] ?? false;
                $gateways[(string) ($gateway['id'] ?? $id)] = ($test === true || $test === 'on' || $test === '1' || $test === 1) ? 'test' : 'live';
            }
        }
        $out = ['by_membership' => [], 'unsupported' => [], 'currency' => strtoupper((string) ($options['currency_code'] ?? 'USD'))];
        if (!$gateways) {
            return $out;
        }
        foreach (self::active_rows(array_keys($gateways)) as $row) {
            $stripe_id = (string) $row->subscr_id;
            if (strpos($stripe_id, 'sub_') !== 0) {
                continue;
            }
            $interval = self::INTERVALS[$row->period_type . ':' . (int) $row->period] ?? '';
            $item = [
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'membership' => (int) $row->product_id,
                'stripe_id' => $stripe_id,
                'customer' => self::stripe_customer((int) $row->user_id, (string) $row->gateway),
                'price' => self::cents($row->price),
                'tax' => self::cents($row->tax_amount ?? 0),
                'total' => self::cents($row->total ?? ((float) $row->price + (float) ($row->tax_amount ?? 0))),
                'interval' => $interval,
                'times' => !empty($row->limit_cycles) ? (int) ($row->limit_cycles_num ?? 0) : 0,
                'next' => (string) ($row->next_billing ?? ''),
                'created' => (string) $row->created_at,
                'mode' => $gateways[(string) $row->gateway],
            ];
            if ($interval === '') {
                $out['unsupported'][] = $item + ['period' => (int) $row->period . ' ' . $row->period_type];
                continue;
            }
            $out['by_membership'][(int) $row->product_id][] = $item;
        }

        return $out;
    }

    /** Warnings for the switch's check, when billing is asked for. */
    public static function warnings(array $found): array
    {
        $count = array_sum(array_map('count', $found['by_membership']));
        $warnings = [];
        if (!self::fluentcart_active()) {
            $warnings[] = __('FluentCart isn\'t active, so the Stripe subscriptions stay in MemberPress. Turn FluentCart on and run this again to move them.', 'anypress-client');
            return $warnings;
        }
        $warnings[] = sprintf(
            /* translators: %d: a number of subscriptions. */
            _n(
                '%d Stripe subscription moves to FluentCart and keeps renewing in Stripe as before. Connect FluentCart\'s Stripe settings to the same Stripe account and add its webhook in Stripe, then switch off MemberPress\'s Stripe gateway so renewals aren\'t recorded twice.',
                '%d Stripe subscriptions move to FluentCart and keep renewing in Stripe as before. Connect FluentCart\'s Stripe settings to the same Stripe account and add its webhook in Stripe, then switch off MemberPress\'s Stripe gateway so renewals aren\'t recorded twice.',
                $count,
                'anypress-client'
            ),
            $count
        );
        foreach ($found['unsupported'] as $item) {
            $warnings[] = sprintf(
                /* translators: 1: a MemberPress subscription id, 2: its billing period, such as "2 weeks". */
                __('MemberPress subscription %1$d bills every %2$s, which FluentCart has no interval for, so it stays in MemberPress.', 'anypress-client'),
                $item['id'],
                $item['period']
            );
        }

        return $warnings;
    }

    /**
     * Makes the FluentCart products, customers, orders and subscriptions. Records what it made in $state['billing']
     * as it goes, so undo can take it out even after a failure part way.
     *
     * @param array<int, string> $titles membership id => title
     * @param callable           $save   saves $state
     */
    public static function carry(array $found, array $titles, array &$state, callable $save): void
    {
        $state['billing'] = ['products' => [], 'customers' => [], 'orders' => [], 'subscriptions' => []];
        foreach ($found['by_membership'] as $membership => $items) {
            $first = $items[0];
            $product = self::product((string) ($titles[$membership] ?? ('Membership ' . $membership)), $first['price'], $first['interval']);
            $state['billing']['products'][$membership] = $product;
            $save();
            foreach ($items as $item) {
                $customer = self::customer($item['user_id'], $state);
                if (!$customer) {
                    continue;
                }
                $order = Order::create([
                    'status' => 'completed',
                    'type' => 'subscription',
                    'fulfillment_type' => 'digital',
                    'customer_id' => $customer,
                    'payment_method' => 'stripe',
                    'payment_method_title' => 'Stripe',
                    'payment_status' => 'paid',
                    'currency' => $found['currency'],
                    'subtotal' => $item['price'],
                    'tax_total' => $item['tax'],
                    'total_amount' => $item['total'],
                    'total_paid' => $item['total'],
                    'mode' => $item['mode'],
                    'completed_at' => $item['created'],
                    'created_at' => $item['created'],
                    'note' => sprintf('MemberPress subscription %d (%s)', $item['id'], $item['stripe_id']),
                    'config' => ['memberpress_subscription_id' => $item['id']],
                ]);
                $state['billing']['orders'][] = (int) $order->id;
                $save();
                OrderItem::create([
                    'order_id' => (int) $order->id,
                    'post_id' => $product['product'],
                    'object_id' => $product['variation'],
                    'post_title' => (string) ($titles[$membership] ?? ''),
                    'title' => (string) ($titles[$membership] ?? ''),
                    'fulfillment_type' => 'digital',
                    'payment_type' => 'subscription',
                    'quantity' => 1,
                    'unit_price' => $item['price'],
                    'subtotal' => $item['price'],
                    'tax_amount' => $item['tax'],
                    'line_total' => $item['total'],
                    'other_info' => ['payment_type' => 'subscription', 'repeat_interval' => $item['interval']],
                ]);
                $subscription = Subscription::create([
                    'customer_id' => $customer,
                    'parent_order_id' => (int) $order->id,
                    'product_id' => $product['product'],
                    'variation_id' => $product['variation'],
                    'item_name' => (string) ($titles[$membership] ?? ''),
                    'quantity' => 1,
                    'billing_interval' => $item['interval'],
                    'signup_fee' => 0,
                    'recurring_amount' => $item['price'],
                    'recurring_tax_total' => $item['tax'],
                    'recurring_total' => $item['total'],
                    'bill_times' => $item['times'],
                    'bill_count' => 0,
                    'collection_method' => 'automatic',
                    'trial_days' => 0,
                    'next_billing_date' => $item['next'] !== '' ? $item['next'] : null,
                    'vendor_customer_id' => $item['customer'],
                    'vendor_plan_id' => '',
                    'vendor_subscription_id' => $item['stripe_id'],
                    'current_payment_method' => 'stripe',
                    'status' => 'active',
                    'config' => ['memberpress_subscription_id' => $item['id'], 'currency' => $found['currency']],
                ]);
                $state['billing']['subscriptions'][] = (int) $subscription->id;
                $save();
            }
        }
    }

    /**
     * Takes out what carry() made: subscriptions, their parent orders with their items, customers it made and its
     * products. A subscription FluentCart has already recorded a renewal for is kept, with its order, customer and
     * product: those renewals are real payments, and undo never deletes them.
     */
    public static function remove(array $billing, bool $dry_run): array
    {
        $subscriptions = array_map('intval', (array) ($billing['subscriptions'] ?? []));
        $orders = array_map('intval', (array) ($billing['orders'] ?? []));
        $customers = array_map('intval', (array) ($billing['customers'] ?? []));
        $products = array_values(array_filter(array_map(fn ($p) => (int) ($p['product'] ?? 0), (array) ($billing['products'] ?? []))));
        if (!self::fluentcart_active()) {
            return ['subscriptions' => 0, 'orders' => 0, 'customers' => 0, 'products' => 0, 'kept' => 0];
        }
        $renewed = $orders ? Order::whereIn('parent_id', $orders)->pluck('parent_id')->all() : [];
        $kept = $renewed ? Subscription::whereIn('id', $subscriptions ?: [0])->whereIn('parent_order_id', $renewed)->get() : [];
        $keep = ['subscriptions' => [], 'orders' => [], 'customers' => [], 'products' => []];
        foreach ($kept as $subscription) {
            $keep['subscriptions'][] = (int) $subscription->id;
            $keep['orders'][] = (int) $subscription->parent_order_id;
            $keep['customers'][] = (int) $subscription->customer_id;
            $keep['products'][] = (int) $subscription->product_id;
        }
        $subscriptions = array_values(array_diff($subscriptions, $keep['subscriptions']));
        $orders = array_values(array_diff($orders, $keep['orders']));
        $customers = array_values(array_diff($customers, $keep['customers']));
        $products = array_values(array_diff($products, $keep['products']));
        $removed = [
            'subscriptions' => $subscriptions ? Subscription::whereIn('id', $subscriptions)->count() : 0,
            'orders' => $orders ? Order::whereIn('id', $orders)->count() : 0,
            'customers' => $customers ? Customer::whereIn('id', $customers)->count() : 0,
            'products' => count(array_filter($products, fn ($id) => get_post($id) !== null)),
            'kept' => count($keep['subscriptions']),
        ];
        if ($dry_run) {
            return $removed;
        }
        if ($subscriptions) {
            Subscription::whereIn('id', $subscriptions)->delete();
        }
        if ($orders) {
            OrderItem::whereIn('order_id', $orders)->delete();
            Order::whereIn('id', $orders)->delete();
        }
        if ($customers) {
            Customer::whereIn('id', $customers)->delete();
        }
        foreach ($products as $id) {
            \FluentCart\Api\Resource\ProductResource::delete($id);
        }

        return $removed;
    }

    /** A draft FluentCart subscription product for a membership, made by FluentCart's own bulk insert. */
    private static function product(string $title, int $price, string $interval): array
    {
        $result = (new \FluentCart\App\Services\BulkProductInsertService())->insertChunk([[
            '_cid' => 'memberpress',
            'post_title' => $title,
            'post_status' => 'draft',
            'detail' => ['fulfillment_type' => 'digital', 'variation_type' => 'simple'],
            'variants' => [[
                'item_price' => $price,
                'other_info' => ['payment_type' => 'subscription', 'repeat_interval' => $interval, 'manage_setup_fee' => 'no'],
            ]],
        ]]);
        $id = (int) ($result['created'][0]['id'] ?? 0);
        if (!$id) {
            throw new \RuntimeException(esc_html(sprintf('FluentCart made no product for "%s": %s', $title, (string) ($result['errors'][0]['message'] ?? 'no reason given'))));
        }
        $variation = \FluentCart\App\Models\ProductVariation::where('post_id', $id)->orderBy('id')->first();

        return ['product' => $id, 'variation' => $variation ? (int) $variation->id : 0];
    }

    /** The member's FluentCart customer: theirs if they have one, else a new one (remembered for undo). */
    private static function customer(int $user_id, array &$state): int
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return 0;
        }
        $found = Customer::where('user_id', $user_id)->first() ?: Customer::where('email', $user->user_email)->first();
        if ($found) {
            return (int) $found->id;
        }
        $made = Customer::create([
            'user_id' => $user_id,
            'email' => $user->user_email,
            'first_name' => (string) $user->first_name,
            'last_name' => (string) $user->last_name,
            'status' => 'active',
        ]);
        $state['billing']['customers'][] = (int) $made->id;

        return (int) $made->id;
    }

    /** MemberPress keeps the Stripe customer id in user meta named after the gateway; the first match. */
    private static function stripe_customer(int $user_id, string $gateway): string
    {
        foreach ((array) get_user_meta($user_id) as $key => $values) {
            if (strpos((string) $key, '_mepr_stripe_customer_id_' . $gateway) === 0 && !empty($values[0])) {
                return (string) $values[0];
            }
        }

        return '';
    }

    private static function cents($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /*
     * Read straight from MemberPress's tables, like the rest of the MemberPress switch (see MemberPressImport): its
     * classes only load while MemberPress is on. Read-only, prepared, once per admin run.
     */
    private static function active_rows(array $gateways): array
    {
        global $wpdb;
        $in = implode(', ', array_fill(0, count($gateways), '%s'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above; $in is only %s placeholders.
        return (array) $wpdb->get_results($wpdb->prepare("SELECT s.*, (SELECT MAX(t.expires_at) FROM %i t WHERE t.subscription_id = s.id AND t.status IN ('complete', 'confirmed')) AS next_billing FROM %i s JOIN %i u ON u.ID = s.user_id WHERE s.status = 'active' AND s.gateway IN ($in) ORDER BY s.product_id, s.id", $wpdb->prefix . 'mepr_transactions', $wpdb->prefix . 'mepr_subscriptions', $wpdb->users, ...$gateways));
    }
}

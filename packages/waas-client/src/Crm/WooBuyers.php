<?php

namespace WaaSClient\Crm;

/**
 * WooCommerce buyers, guests included, read from the orders through WooCommerce's own order API (so it reads the
 * posts table or the HPOS order tables, whichever the shop uses). Only paid orders count: processing and completed
 * (and whatever else the shop counts as paid), plus fully refunded orders for the order history.
 *
 * One contact per buyer: a registered customer under their account email, a guest under the billing email. Name,
 * phone and billing address come from their latest order. Each product they paid for (and didn't get a full refund
 * for) becomes "Product: Past", following the Offering: Interested/Active/Past tagging model: past buyers get Past
 * only, since nothing in the order history proves they still have it.
 *
 * Marketing consent: WooCommerce keeps none itself. The only consent records read here are Mailchimp for
 * WooCommerce's checkout and account opt-in (mailchimp_woocommerce_is_subscribed, on the order or the user); MailPoet's
 * checkout opt-in lives in MailPoet's own tables and comes in through the MailPoet source. A buyer with no record
 * gets no status from this source.
 */
class WooBuyers
{
    public const SOURCE = 'WooCommerce';
    public const FIELD_ORDERS = 'woocommerce_orders';
    public const FIELD_SPENT = 'woocommerce_spent';
    /** Mailchimp for WooCommerce's opt-in meta, on the order (checkout) and the user (account page). */
    public const OPTIN_META = 'mailchimp_woocommerce_is_subscribed';
    private const PAGE = 200;

    /** Whether WooCommerce is active, so its orders can be read. */
    public static function active(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_get_orders');
    }

    /**
     * Reads the shop's paid and refunded orders.
     *
     * @return array{records: array, stats: array}
     */
    public static function collect(): array
    {
        $statuses = self::statuses();
        $orders = [];
        $users = [];
        $page = 1;
        do {
            $batch = wc_get_orders(['type' => 'shop_order', 'status' => $statuses, 'limit' => self::PAGE, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC']);
            foreach ($batch as $order) {
                if (!is_object($order) || !method_exists($order, 'get_billing_email')) {
                    continue;
                }
                $orders[] = self::normalise($order);
                $user_id = (int) $order->get_customer_id();
                if ($user_id > 0 && !isset($users[$user_id])) {
                    $user = get_userdata($user_id);
                    $users[$user_id] = [
                        'email' => $user ? (string) $user->user_email : '',
                        'optin' => $user ? get_user_meta($user_id, self::OPTIN_META, true) : '',
                    ];
                }
            }
            $page++;
        } while (count($batch) === self::PAGE);

        return self::from_orders($orders, $users);
    }

    /** WooCommerce's paid statuses (wc-less), plus refunded. */
    public static function statuses(): array
    {
        $paid = function_exists('wc_get_is_paid_statuses') ? (array) wc_get_is_paid_statuses() : ['processing', 'completed'];

        return array_values(array_unique(array_merge($paid, ['refunded'])));
    }

    /** The parts of an order the switch reads. */
    private static function normalise($order): array
    {
        $items = [];
        foreach ($order->get_items('line_item') as $item) {
            $product_id = (int) $item->get_product_id();
            $product = $product_id ? wc_get_product($product_id) : null;
            $items[] = ['product_id' => $product_id, 'name' => $product ? (string) $product->get_name() : (string) $item->get_name()];
        }
        $created = $order->get_date_created();

        return [
            'id' => (int) $order->get_id(),
            'customer_id' => (int) $order->get_customer_id(),
            'status' => (string) $order->get_status(),
            'email' => (string) $order->get_billing_email(),
            'first_name' => (string) $order->get_billing_first_name(),
            'last_name' => (string) $order->get_billing_last_name(),
            'phone' => (string) $order->get_billing_phone(),
            'address_1' => (string) $order->get_billing_address_1(),
            'address_2' => (string) $order->get_billing_address_2(),
            'city' => (string) $order->get_billing_city(),
            'state' => (string) $order->get_billing_state(),
            'postcode' => (string) $order->get_billing_postcode(),
            'country' => (string) $order->get_billing_country(),
            'ip' => (string) $order->get_customer_ip_address(),
            'date' => $created ? $created->date('Y-m-d H:i:s') : '',
            'total' => (float) $order->get_total(),
            'refunded' => (float) $order->get_total_refunded(),
            'optin' => $order->get_meta(self::OPTIN_META),
            'items' => $items,
        ];
    }

    /**
     * Turns orders into one source record per buyer.
     *
     * @param array $orders Normalised orders, oldest first.
     * @param array $users WordPress user id => ['email' => account email, 'optin' => user meta].
     * @return array{records: array, stats: array}
     */
    public static function from_orders(array $orders, array $users): array
    {
        $buyers = [];
        $skipped = 0;
        foreach ($orders as $order) {
            $user_id = (int) ($order['customer_id'] ?? 0);
            $account = $user_id > 0 ? ContactPlan::email((string) ($users[$user_id]['email'] ?? '')) : '';
            $email = $account !== '' ? $account : ContactPlan::email((string) ($order['email'] ?? ''));
            if ($email === '' || !self::valid_email($email)) {
                $skipped++;
                continue;
            }
            if (!isset($buyers[$email])) {
                $buyers[$email] = ['email' => $email, 'user_id' => $account !== '' ? $user_id : 0, 'orders' => 0, 'spent' => 0.0, 'products' => [], 'latest' => null, 'first' => '', 'optin' => null];
            }
            $buyer = &$buyers[$email];
            $total = (float) ($order['total'] ?? 0);
            $refunded = (float) ($order['refunded'] ?? 0);
            $fully_refunded = ($order['status'] ?? '') === 'refunded' || ($total > 0 && $refunded >= $total);
            if (!$fully_refunded) {
                $buyer['orders']++;
                foreach ((array) ($order['items'] ?? []) as $item) {
                    $tag = ContactPlan::past_tag((string) ($item['name'] ?? ''));
                    if ($tag !== '' && !in_array($tag, $buyer['products'], true)) {
                        $buyer['products'][] = $tag;
                    }
                }
            }
            $buyer['spent'] += max(0.0, $total - $refunded);
            if ($buyer['first'] === '' && !empty($order['date'])) {
                $buyer['first'] = (string) $order['date'];
            }
            $buyer['latest'] = $order;
            $optin = self::optin($order['optin'] ?? null);
            if ($optin !== null) {
                $buyer['optin'] = $optin;
            }
            unset($buyer);
        }

        $records = [];
        $stats = ['orders' => count($orders), 'buyers' => 0, 'registered' => 0, 'guests' => 0, 'opted_in' => 0, 'opted_out' => 0, 'refunded_only' => 0, 'skipped_orders' => $skipped, 'products' => []];
        foreach ($buyers as $email => $buyer) {
            // An account's own opt-in (its latest choice) wins over the one at an older checkout.
            if ($buyer['user_id'] > 0) {
                $user_optin = self::optin($users[$buyer['user_id']]['optin'] ?? null);
                if ($user_optin !== null) {
                    $buyer['optin'] = $user_optin;
                }
            }
            $latest = $buyer['latest'];
            $records[] = [
                'email' => $email,
                'source' => self::SOURCE,
                'contact_type' => 'customer',
                'first_name' => $latest['first_name'] ?? '',
                'last_name' => $latest['last_name'] ?? '',
                'phone' => $latest['phone'] ?? '',
                'address_line_1' => $latest['address_1'] ?? '',
                'address_line_2' => $latest['address_2'] ?? '',
                'city' => $latest['city'] ?? '',
                'state' => $latest['state'] ?? '',
                'postal_code' => $latest['postcode'] ?? '',
                'country' => $latest['country'] ?? '',
                'ip' => $latest['ip'] ?? '',
                'created_at' => $buyer['first'],
                'status' => $buyer['optin'],
                'tags' => $buyer['products'],
                'lists' => [],
                'custom' => [self::FIELD_ORDERS => $buyer['orders'], self::FIELD_SPENT => number_format($buyer['spent'], 2, '.', '')],
            ];
            $stats['buyers']++;
            $stats[$buyer['user_id'] > 0 ? 'registered' : 'guests']++;
            if ($buyer['optin'] === 'subscribed') {
                $stats['opted_in']++;
            } elseif ($buyer['optin'] === 'unsubscribed') {
                $stats['opted_out']++;
            }
            if (!$buyer['products']) {
                $stats['refunded_only']++;
            }
            foreach ($buyer['products'] as $tag) {
                $stats['products'][$tag] = ($stats['products'][$tag] ?? 0) + 1;
            }
        }
        ksort($stats['products']);

        return ['records' => $records, 'stats' => $stats];
    }

    /**
     * Mailchimp for WooCommerce's opt-in value as a status: "subscribed" for a yes, "unsubscribed" when they
     * unsubscribed, null for no answer (or a plain no at checkout, which is not an unsubscribe).
     */
    public static function optin($value): ?string
    {
        if ($value === true || $value === 1) {
            return 'subscribed';
        }
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';
        if (in_array($value, ['1', 'yes', 'true', 'subscribed'], true)) {
            return 'subscribed';
        }
        if ($value === 'unsubscribed') {
            return 'unsubscribed';
        }

        return null;
    }

    private static function valid_email(string $email): bool
    {
        return function_exists('is_email') ? (bool) is_email($email) : (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

<?php

namespace WaaSClient\Shop;

/**
 * Merges FluentCart customers that belong to the same WordPress user.
 *
 * The FluentCart Migrator add-on finds customers by billing email only, so a registered customer who ordered with
 * another billing email ends up as two FluentCart customers with the same user_id, their history split between
 * them. This keeps one (the one with the account's email, else the oldest), moves the others' orders, addresses
 * and everything else that points at a customer onto it, removes the emptied rows, and has the add-on recount the
 * customers' stats. Each merge is recorded, so undo puts the rows back where they were.
 *
 * It moves the same rows as FluentCart's own CustomerIdentity\CustomerMerger, with the same address rule (the kept
 * customer's primary addresses stay primary), and fires the same fluent_cart/customer_resources_moved action so
 * FluentCart Pro moves its licences. That merger itself can't be used here: it only folds a customer with no
 * WordPress user into one with a user (both of these have the same user), runs only on InnoDB tables (never on
 * SQLite), and keeps the emptied customer, which would leave a stray customer per merge.
 */
class CustomerMerge
{
    /** FluentCart tables that point at a customer: table => [key, customer column, type column]. */
    private const RESOURCES = [
        'fct_orders' => ['id', 'customer_id', null],
        'fct_subscriptions' => ['id', 'customer_id', null],
        'fct_order_download_permissions' => ['id', 'customer_id', null],
        'fct_carts' => ['cart_hash', 'customer_id', null],
        'fct_customer_addresses' => ['id', 'customer_id', null],
        'fct_customer_meta' => ['id', 'customer_id', null],
        'fct_product_reviews' => ['id', 'customer_id', null],
        'fct_label_relationships' => ['id', 'labelable_id', 'labelable_type'],
        'fct_activity' => ['id', 'module_id', 'module_type'],
        'fct_licenses' => ['id', 'customer_id', null],
    ];
    private const CUSTOMER_MODEL = 'FluentCart\App\Models\Customer';

    /**
     * Which customers to merge into which.
     *
     * @param array $customers Rows with id, user_id and email.
     * @param array $user_emails WordPress user id => the account's email.
     * @return array<int, array{user_id: int, keep: int, merge: int[], emails: string[]}>
     */
    public static function plan(array $customers, array $user_emails): array
    {
        $by_user = [];
        foreach ($customers as $customer) {
            $user = (int) ($customer['user_id'] ?? 0);
            if ($user > 0) {
                $by_user[$user][] = ['id' => (int) $customer['id'], 'email' => (string) ($customer['email'] ?? '')];
            }
        }
        $plan = [];
        foreach ($by_user as $user => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            usort($rows, fn ($a, $b) => $a['id'] <=> $b['id']);
            $account = strtolower(trim((string) ($user_emails[$user] ?? '')));
            $keep = $rows[0];
            foreach ($rows as $row) {
                if ($account !== '' && strtolower(trim($row['email'])) === $account) {
                    $keep = $row;
                    break;
                }
            }
            $merge = array_values(array_filter($rows, fn ($row) => $row['id'] !== $keep['id']));
            $plan[] = [
                'user_id' => $user,
                'keep' => $keep['id'],
                'merge' => array_column($merge, 'id'),
                'emails' => array_merge([$keep['email']], array_column($merge, 'email')),
            ];
        }

        return $plan;
    }

    /**
     * @param array $state The switch's state; 'merges' keeps each removed customer and the rows moved off it.
     * @return array<int, array> One row per WordPress user whose customers were (or would be) merged.
     */
    public static function merge(array &$state, bool $dry_run): array
    {
        global $wpdb;
        $customers = $wpdb->get_results("SELECT id, user_id, email FROM {$wpdb->prefix}fct_customers WHERE user_id > 0", ARRAY_A);
        $emails = [];
        foreach ((array) $customers as $customer) {
            $user = get_userdata((int) $customer['user_id']);
            if ($user) {
                $emails[(int) $customer['user_id']] = $user->user_email;
            }
        }
        $plan = self::plan((array) $customers, $emails);
        $report = [];
        foreach ($plan as $merge) {
            $orders = 0;
            foreach ($merge['merge'] as $id) {
                $orders += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE customer_id = %d", $id));
            }
            $report[] = [
                'user_id' => $merge['user_id'],
                'customer' => $merge['keep'],
                'emails' => implode(', ', array_unique($merge['emails'])),
                'merged' => count($merge['merge']),
                'orders moved' => $orders,
                'status' => $dry_run ? 'will be merged' : 'merged',
            ];
            if ($dry_run) {
                continue;
            }
            foreach ($merge['merge'] as $id) {
                $state['merges'][$id] = self::move($id, $merge['keep']);
            }
        }
        if ($plan && !$dry_run) {
            self::recount();
        }

        return $report;
    }

    /** Moves everything off customer $from onto $into and removes $from. Returns what undo needs. */
    private static function move(int $from, int $into): array
    {
        global $wpdb;
        $record = ['into' => $into, 'row' => $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}fct_customers WHERE id = %d", $from), ARRAY_A), 'moved' => [], 'demoted' => []];

        // The kept customer's primary address of each type stays primary; otherwise the moved one keeps its place.
        $primary = array_map('strval', (array) $wpdb->get_col($wpdb->prepare("SELECT type FROM {$wpdb->prefix}fct_customer_addresses WHERE customer_id = %d AND is_primary = 1", $into)));
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, type FROM {$wpdb->prefix}fct_customer_addresses WHERE customer_id = %d AND is_primary = 1 ORDER BY id", $from)) as $address) {
            if (in_array((string) $address->type, $primary, true)) {
                $record['demoted'][] = (int) $address->id;
            } else {
                $primary[] = (string) $address->type;
            }
        }
        if ($record['demoted']) {
            $wpdb->query("UPDATE {$wpdb->prefix}fct_customer_addresses SET is_primary = 0 WHERE id IN (" . implode(',', $record['demoted']) . ')');
        }

        foreach (self::resources() as $table => [$key, $column, $type]) {
            $where = $type ? $wpdb->prepare("{$column} = %d AND {$type} = %s", $from, self::CUSTOMER_MODEL) : $wpdb->prepare("{$column} = %d", $from);
            $keys = array_map('strval', (array) $wpdb->get_col("SELECT {$key} FROM {$wpdb->prefix}{$table} WHERE {$where}"));
            if (!$keys) {
                continue;
            }
            $record['moved'][$table] = $keys;
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}{$table} SET {$column} = %d WHERE {$where}", $into));
        }
        // FluentCart's own hook after a customer's rows move (FluentCart Pro moves its licences on it).
        do_action('fluent_cart/customer_resources_moved', ['from_customer_id' => $from, 'to_customer_id' => $into]);
        $wpdb->delete($wpdb->prefix . 'fct_customers', ['id' => $from]);

        return $record;
    }

    /** Puts merged customers back with their own orders, addresses and the rest. */
    public static function undo(array &$state, bool $dry_run): int
    {
        global $wpdb;
        $count = count($state['merges']);
        if ($dry_run || !$count) {
            return $count;
        }
        foreach ($state['merges'] as $id => $record) {
            if (!empty($record['row']) && !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fct_customers WHERE id = %d", (int) $id))) {
                $wpdb->insert($wpdb->prefix . 'fct_customers', $record['row']);
            }
            foreach ($record['moved'] as $table => $keys) {
                if (!isset(self::RESOURCES[$table]) || !$keys) {
                    continue;
                }
                [$key, $column] = self::RESOURCES[$table];
                $list = implode(',', array_map(fn ($value) => $wpdb->prepare('%s', $value), $keys));
                $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}{$table} SET {$column} = %d WHERE {$key} IN ({$list})", (int) $id));
            }
            if (!empty($record['demoted'])) {
                $wpdb->query("UPDATE {$wpdb->prefix}fct_customer_addresses SET is_primary = 1 WHERE id IN (" . implode(',', array_map('intval', $record['demoted'])) . ')');
            }
        }
        $state['merges'] = [];
        self::recount();

        return $count;
    }

    /** The resource tables this site has (fct_licenses only with FluentCart Pro). */
    private static function resources(): array
    {
        global $wpdb;

        return array_filter(self::RESOURCES, fn ($table) => $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $table)) === $wpdb->prefix . $table, ARRAY_FILTER_USE_KEY);
    }

    /** The add-on's own recount of each customer's order count, lifetime value and purchase dates. */
    private static function recount(): void
    {
        if (class_exists('FluentCartMigrator\Classes\MigratorService')) {
            (new \FluentCartMigrator\Classes\MigratorService())->recountCustomers();
        }
    }
}

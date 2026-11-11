<?php

namespace AnyPressClient\Shop;

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
 * SQLite), and keeps the emptied customer, which would leave a stray customer per merge. So the rows are moved here
 * through FluentCart's own models and query builder, the same way its merger moves them.
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
    private const ADDRESS_MODEL = 'FluentCart\App\Models\CustomerAddresses';

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
        $model = self::CUSTOMER_MODEL;
        $customers = array_map(fn ($row) => $row->getAttributes(), $model::where('user_id', '>', 0)->get(['id', 'user_id', 'email'])->all());
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
                $orders += (int) \FluentCart\App\Models\Order::where('customer_id', $id)->count();
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
        $customer = self::CUSTOMER_MODEL;
        $address = self::ADDRESS_MODEL;
        $row = $customer::find($from);
        $record = ['into' => $into, 'row' => $row ? $row->getAttributes() : null, 'moved' => [], 'demoted' => []];

        // The kept customer's primary address of each type stays primary; otherwise the moved one keeps its place.
        $primary = array_map('strval', $address::where('customer_id', $into)->where('is_primary', 1)->pluck('type')->all());
        foreach ($address::where('customer_id', $from)->where('is_primary', 1)->orderBy('id')->get(['id', 'type']) as $moved) {
            if (in_array((string) $moved->type, $primary, true)) {
                $record['demoted'][] = (int) $moved->id;
            } else {
                $primary[] = (string) $moved->type;
            }
        }
        if ($record['demoted']) {
            $address::whereIn('id', $record['demoted'])->update(['is_primary' => 0]);
        }

        foreach (self::resources() as $table => [$key, $column, $type]) {
            $keys = array_map('strval', self::pointing_at($table, $from)->pluck($key)->all());
            if (!$keys) {
                continue;
            }
            $record['moved'][$table] = $keys;
            self::pointing_at($table, $from)->update([$column => $into]);
        }
        // FluentCart's own hook after a customer's rows move (FluentCart Pro moves its licences on it).
        do_action('fluent_cart/customer_resources_moved', ['from_customer_id' => $from, 'to_customer_id' => $into]);
        $customer::where('id', $from)->delete();

        return $record;
    }

    /** Rows of a resource table that point at this customer, through FluentCart's query builder. */
    private static function pointing_at(string $table, int $customer)
    {
        [, $column, $type] = self::RESOURCES[$table];
        $query = \FluentCart\App\App::db()->table($table)->where($column, $customer);

        return $type ? $query->where($type, self::CUSTOMER_MODEL) : $query;
    }

    /** Puts merged customers back with their own orders, addresses and the rest. */
    public static function undo(array &$state, bool $dry_run): int
    {
        $count = count($state['merges']);
        if ($dry_run || !$count) {
            return $count;
        }
        $customer = self::CUSTOMER_MODEL;
        foreach ($state['merges'] as $id => $record) {
            if (!empty($record['row']) && !$customer::find((int) $id)) {
                \FluentCart\App\App::db()->table('fct_customers')->insert($record['row']);
            }
            foreach ($record['moved'] as $table => $keys) {
                if (!isset(self::RESOURCES[$table]) || !$keys) {
                    continue;
                }
                [$key, $column] = self::RESOURCES[$table];
                \FluentCart\App\App::db()->table($table)->whereIn($key, array_map('strval', $keys))->update([$column => (int) $id]);
            }
            if (!empty($record['demoted'])) {
                $address = self::ADDRESS_MODEL;
                $address::whereIn('id', array_map('intval', $record['demoted']))->update(['is_primary' => 1]);
            }
        }
        $state['merges'] = [];
        self::recount();

        return $count;
    }

    /** The resource tables this site has (fct_licenses only with FluentCart Pro). */
    private static function resources(): array
    {
        return array_filter(self::RESOURCES, fn ($table) => \FluentCart\Framework\Database\Schema::hasTable($table), ARRAY_FILTER_USE_KEY);
    }

    /** The add-on's own recount of each customer's order count, lifetime value and purchase dates. */
    private static function recount(): void
    {
        if (class_exists('FluentCartMigrator\Classes\MigratorService')) {
            (new \FluentCartMigrator\Classes\MigratorService())->recountCustomers();
        }
    }
}

<?php

namespace WaaSClient\Shop;

use WP_Error;

/**
 * The shop switch: moves a website's WooCommerce shop to FluentCart.
 *
 * 1. Checks WooCommerce, FluentCart and the FluentCart Migrator add-on are active, and reports the WooCommerce
 *    storage (HPOS on or off), what's in the shop, and whether subscriptions are in use.
 * 2. Runs the add-on's own WooCommerce migration (products and store settings, tax rates, coupons, orders,
 *    customers without orders, recount), through its PHP classes, the same steps as its `--all` command.
 * 3. Fills the add-on's gaps: product gallery images, shipping zones and methods, and FluentCart customers split
 *    across one WordPress user.
 * 4. Lists what the owner must check by hand (product tags, tax rates that differ) and compares WooCommerce with
 *    FluentCart: products, SKUs, orders, the sum of order totals, refunds, coupons and customers.
 *
 * Running it again adds nothing twice: the add-on skips the steps it has finished (its own progress option), and
 * each fix here only does what is still missing. WooCommerce is never deactivated, deleted or changed.
 */
class ShopSwitch
{
    /** What the fixes changed (galleries before, copied zone and method ids, merged customers). Not autoloaded. */
    public const OPTION = 'waas_client_shop_switch';
    private const LOCK = 'waas_client_shop_switch_lock';
    /** The add-on's progress on the WooCommerce source. */
    public const ADDON_STATE = '__fluent_cart_woocommerce_migration_steps';
    public const ADDON_CLASS = 'FluentCartMigrator\Classes\WooCommerce\WooSourceMigrator';
    public const ADDON_FILE = 'fluent-cart-migrator/fluent-cart-migrator.php';
    public const ADDON_PAGE = 'https://fluentcart.com/fluentcart-addons';
    public const ADDON_ZIP = 'https://addons-cdn.fluentcart.com/fluent-cart-migrator.zip';

    /**
     * Switches the shop, or with $dry_run, only checks it and says what the switch would do.
     *
     * @return array|WP_Error The report.
     */
    public function run(bool $dry_run = false)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('waas_shop_unauthorized', __('Only an administrator can switch the shop.', 'waas-client'), ['status' => 403]);
        }
        $preflight = self::preflight();
        if (!$dry_run && !$preflight['ready']) {
            return new WP_Error('waas_shop_not_ready', implode(' ', $preflight['blockers']), ['status' => 409, 'preflight' => $preflight]);
        }
        if (!$dry_run && !$this->lock()) {
            return new WP_Error('waas_shop_busy', __('A shop switch is already running on this website. Try again in a few minutes.', 'waas-client'), ['status' => 409]);
        }

        try {
            $report = [
                'dry_run' => $dry_run,
                'preflight' => $preflight,
                'migration' => [],
                'fixes' => [],
                'review' => [],
                'compare' => [],
                'notes' => [],
            ];
            if (!$preflight['ready']) {
                $report['notes'][] = __('Nothing can be switched until the items under "blockers" are fixed.', 'waas-client');
                return $report;
            }

            $state = self::state();
            if ($dry_run) {
                $report['migration'] = self::addon_progress();
            } else {
                $report['migration'] = $this->migrate();
                if (is_wp_error($report['migration'])) {
                    return $report['migration'];
                }
            }

            $report['fixes']['gallery_images'] = GalleryImages::copy($state, $dry_run);
            $report['fixes']['shipping'] = ShippingZones::copy($state, $dry_run);
            $report['fixes']['customers'] = CustomerMerge::merge($state, $dry_run);
            if (!$dry_run) {
                self::save($state);
            }

            $report['review']['product_tags'] = ShopReview::tags();
            $report['review']['tax_rates'] = ShopReview::tax_rates();
            $report['compare'] = ShopCompare::run();

            if ($dry_run && !$report['migration']['done']) {
                $report['notes'][] = __('The add-on hasn\'t copied the shop yet, so the gallery images, customers and comparison show FluentCart as it is now. Run the switch to copy everything.', 'waas-client');
            }
            if ($report['review']['product_tags']) {
                $report['notes'][] = __('FluentCart has no product tags (only categories and brands). The tagged products are listed under review; add them to a category if the tags matter.', 'waas-client');
            }
            if ($report['review']['tax_rates']) {
                $report['notes'][] = __('Some WooCommerce tax rates differ from the FluentCart rates the add-on used. Nothing was changed: check each one under FluentCart → Settings → Taxes.', 'waas-client');
            }
            $report['notes'][] = __('WooCommerce and its data are left as they were. Once you\'re happy with FluentCart, you can turn WooCommerce off.', 'waas-client');

            return $report;
        } finally {
            if (!$dry_run) {
                $this->unlock();
            }
        }
    }

    /**
     * Undoes the fixes: puts back the galleries, removes the copied shipping zones and methods, and splits the
     * merged customers again. What the add-on copied stays: it has no undo outside its developer mode.
     *
     * @return array|WP_Error
     */
    public function undo(bool $dry_run = false)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('waas_shop_unauthorized', __('Only an administrator can undo the shop switch.', 'waas-client'), ['status' => 403]);
        }
        if (!$dry_run && !$this->lock()) {
            return new WP_Error('waas_shop_busy', __('A shop switch is already running on this website. Try again in a few minutes.', 'waas-client'), ['status' => 409]);
        }

        try {
            $state = self::state();
            $report = [
                'dry_run' => $dry_run,
                'undo' => true,
                'gallery_images' => GalleryImages::undo($state, $dry_run),
                'shipping' => ShippingZones::undo($state, $dry_run),
                'customers' => CustomerMerge::undo($state, $dry_run),
                'notes' => [],
            ];
            if (!$dry_run) {
                self::save($state);
            }
            $report['notes'][] = __('The products, orders, customers, coupons and tax rates the FluentCart Migrator add-on copied stay in FluentCart. WooCommerce was never changed.', 'waas-client');

            return $report;
        } finally {
            if (!$dry_run) {
                $this->unlock();
            }
        }
    }

    /** Whether the switch can run, and what's in the WooCommerce shop. */
    public static function preflight(): array
    {
        $woo = class_exists('WooCommerce');
        $fluent = defined('FLUENTCART_VERSION');
        $addon = class_exists(self::ADDON_CLASS) && defined('FLUENTCART_MIGRATOR_VERSION');
        $report = [
            'ready' => false,
            'woocommerce' => $woo ? (defined('WC_VERSION') ? WC_VERSION : 'active') : null,
            'fluentcart' => $fluent ? FLUENTCART_VERSION : null,
            'migrator_addon' => $addon ? FLUENTCART_MIGRATOR_VERSION : null,
            'blockers' => [],
            'hpos' => null,
            'counts' => [],
            'subscriptions' => ['in_use' => false, 'count' => 0, 'plugin' => false],
            'warnings' => [],
        ];

        if (!$woo) {
            $report['blockers'][] = __('WooCommerce isn\'t active. Its data can only be read while it is.', 'waas-client');
        }
        if (!$fluent) {
            $report['blockers'][] = __('FluentCart isn\'t active. Install and turn on FluentCart (free, wordpress.org) first; the shop is copied into it.', 'waas-client');
        }
        if (!$addon) {
            $installed = function_exists('get_plugins') && array_key_exists(self::ADDON_FILE, get_plugins());
            $report['blockers'][] = $installed
                ? __('The FluentCart Migrator add-on is installed but not active. Turn it on under Plugins.', 'waas-client')
                /* translators: 1: FluentCart add-ons page, 2: download link. */
                : sprintf(__('The FluentCart Migrator add-on isn\'t installed. It is free from FluentCart\'s add-ons page (%1$s), download: %2$s. Install and activate it, then run this again.', 'waas-client'), self::ADDON_PAGE, self::ADDON_ZIP);
        } elseif (strpos((string) FLUENTCART_MIGRATOR_VERSION, 'beta') !== false) {
            /* translators: %s: add-on version. */
            $report['warnings'][] = sprintf(__('The FluentCart Migrator add-on is a beta (%s).', 'waas-client'), FLUENTCART_MIGRATOR_VERSION);
        }
        $report['ready'] = !$report['blockers'];
        if (!$woo) {
            return $report;
        }

        $report['hpos'] = self::hpos();
        $report['counts'] = self::counts();
        $report['subscriptions'] = self::subscriptions();
        if ($report['subscriptions']['in_use']) {
            $report['warnings'][] = __('WooCommerce Subscriptions is in use. The add-on copies the subscriptions, but their renewals only keep charging if FluentCart has the same payment gateway set up with the same account (FluentCart Pro for subscriptions).', 'waas-client');
        }

        return $report;
    }

    private static function hpos(): array
    {
        $enabled = class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        return [
            'enabled' => $enabled,
            'storage' => $enabled ? 'HPOS (wc_orders tables)' : 'posts (wp_posts)',
            'sync' => get_option('woocommerce_custom_orders_table_data_sync_enabled') === 'yes',
        ];
    }

    private static function counts(): array
    {
        $total = fn ($result) => (int) ($result->total ?? 0);
        $products = wc_get_products(['status' => ['publish', 'private', 'draft'], 'limit' => 1, 'paginate' => true, 'return' => 'ids']);
        $variable = wc_get_products(['status' => ['publish', 'private', 'draft'], 'type' => 'variable', 'limit' => 1, 'paginate' => true, 'return' => 'ids']);
        $orders = [];
        foreach (array_keys(wc_get_order_statuses()) as $status) {
            $count = $total(wc_get_orders(['type' => 'shop_order', 'status' => $status, 'limit' => 1, 'paginate' => true, 'return' => 'ids']));
            if ($count) {
                $orders[str_replace('wc-', '', $status)] = $count;
            }
        }
        global $wpdb;
        $guests = $total(wc_get_orders(['type' => 'shop_order', 'status' => 'any', 'customer_id' => 0, 'limit' => 1, 'paginate' => true, 'return' => 'ids']));
        $customers = new \WP_User_Query(['role__in' => ['customer', 'subscriber'], 'number' => 1, 'count_total' => true, 'fields' => 'ID']);

        return [
            'products' => $total($products),
            'variable_products' => $total($variable),
            'variations' => (int) (wp_count_posts('product_variation')->publish ?? 0),
            'orders' => array_sum($orders),
            'orders_by_status' => $orders,
            'guest_orders' => $guests,
            'refunds' => $total(wc_get_orders(['type' => 'shop_order_refund', 'status' => 'any', 'limit' => 1, 'paginate' => true, 'return' => 'ids'])),
            'coupons' => (int) (wp_count_posts('shop_coupon')->publish ?? 0),
            'registered_customers' => (int) $customers->get_total(),
            'tax_rates' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates"),
            'shipping_zones' => count(ShippingZones::woo_zones()),
            'product_tags' => (int) wp_count_terms(['taxonomy' => 'product_tag', 'hide_empty' => false]),
        ];
    }

    private static function subscriptions(): array
    {
        $plugin = class_exists('WC_Subscriptions') || function_exists('wcs_get_subscriptions');
        $count = 0;
        if ($plugin) {
            $result = wc_get_orders(['type' => 'shop_subscription', 'status' => 'any', 'limit' => 1, 'paginate' => true, 'return' => 'ids']);
            $count = (int) ($result->total ?? 0);
        }

        return ['in_use' => $count > 0, 'count' => $count, 'plugin' => $plugin];
    }

    /** The add-on's progress, without running it. */
    private static function addon_progress(): array
    {
        $state = get_option(self::ADDON_STATE, []);
        $state = is_array($state) ? $state : [];
        $steps = [];
        foreach (['products', 'tax_rates', 'coupons', 'payments', 'missing_customers', 'recount'] as $step) {
            $steps[$step] = ($state[$step] ?? '') === 'yes' ? 'done' : 'not run';
        }

        return ['done' => ($state['payments'] ?? '') === 'yes', 'steps' => $steps];
    }

    /**
     * Runs the add-on's WooCommerce migration to the end, the same steps as `wp fluent_cart_migrator
     * migrate_from_woo --all`. Each step the add-on has finished is skipped.
     *
     * @return array|WP_Error
     */
    private function migrate()
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        wp_raise_memory_limit('admin');
        $class = self::ADDON_CLASS;
        $source = new $class();
        $steps = [];

        $migrated = 0;
        $failed = 0;
        $skipped = false;
        do {
            $result = $source->migrateProducts($source->getProductResumePage(), 100, 0);
            if (is_wp_error($result)) {
                return $result;
            }
            $migrated += (int) ($result['migrated'] ?? 0);
            $failed += (int) ($result['failed'] ?? 0);
            $skipped = !empty($result['skipped']);
        } while (!empty($result['has_more']));
        $steps['products'] = $skipped ? 'already done' : sprintf('%d copied, %d failed', $migrated, $failed);

        $result = $source->migrateTaxRates();
        $steps['tax_rates'] = is_wp_error($result) ? 'failed: ' . $result->get_error_message()
            : (!empty($result['skipped']) ? ((string) ($result['message'] ?? '') ?: 'already done') : sprintf('%d mapped for %s', $result['mapped'] ?? 0, implode(', ', $result['countries'] ?? [])));

        $result = $source->migrateCoupons();
        $steps['coupons'] = is_wp_error($result) ? 'failed: ' . $result->get_error_message()
            : (!empty($result['skipped']) ? 'already done' : sprintf('%d of %d copied', $result['migrated'] ?? 0, $result['total'] ?? 0));

        $processed = 0;
        do {
            $result = $source->migratePayments($source->getPaymentResumePage(), 500, 0);
            if (is_wp_error($result)) {
                return $result;
            }
            $processed += (int) ($result['processed'] ?? 0);
        } while (!empty($result['has_more']));
        $steps['orders'] = !empty($result['skipped']) ? 'already done' : sprintf('%d processed', $processed);

        $result = $source->migrateMissingCustomers();
        $steps['customers_without_orders'] = is_wp_error($result) ? 'failed: ' . $result->get_error_message() : sprintf('%d copied', $result['migrated'] ?? 0);

        foreach ($source->getRecountSubsteps() as $substep) {
            $source->recountStats($substep);
        }
        $steps['recount'] = 'done';

        $logs = $source->getLogs();
        $not_migrated = [];
        foreach ($logs['groups'] ?? [] as $group) {
            $not_migrated[] = sprintf('%s: %d %s(s). %s', $group['title'] ?? '', $group['count'] ?? 0, $group['type'] ?? 'record', $group['hint'] ?? '');
        }

        return ['done' => true, 'steps' => $steps, 'not_migrated' => $not_migrated, 'totals' => $logs['totals'] ?? []];
    }

    public static function state(): array
    {
        $state = get_option(self::OPTION);
        $state = is_array($state) ? $state : [];

        return [
            'gallery' => is_array($state['gallery'] ?? null) ? $state['gallery'] : [],
            'zones' => is_array($state['zones'] ?? null) ? $state['zones'] : [],
            'methods' => is_array($state['methods'] ?? null) ? $state['methods'] : [],
            'merges' => is_array($state['merges'] ?? null) ? $state['merges'] : [],
        ];
    }

    private static function save(array $state): void
    {
        update_option(self::OPTION, $state, false);
    }

    /** One run at a time (the command and the ability together must not copy twice). */
    private function lock(): bool
    {
        if (add_option(self::LOCK, time(), '', false)) {
            return true;
        }
        // A run that died leaves its lock; it lapses after 30 minutes (a large shop takes a while).
        if ((int) get_option(self::LOCK) < time() - 30 * MINUTE_IN_SECONDS) {
            delete_option(self::LOCK);
            return add_option(self::LOCK, time(), '', false);
        }

        return false;
    }

    private function unlock(): void
    {
        delete_option(self::LOCK);
    }
}

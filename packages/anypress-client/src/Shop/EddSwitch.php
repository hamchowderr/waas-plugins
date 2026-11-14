<?php

namespace AnyPressClient\Shop;

use AnyPressClient\Core\FluentPlugin;
use WP_Error;

/**
 * The shop switch from Easy Digital Downloads 3: moves an EDD shop to FluentCart.
 *
 * 1. Checks EDD 3, FluentCart and the FluentCart Migrator add-on are active (and FluentCart Pro when EDD Software
 *    Licensing is in use, which the add-on needs for the licences), and reports what's in the shop.
 * 2. Runs the add-on's own EDD migration (store settings and products, tax rates, coupons, orders with their
 *    transactions, subscriptions and licences, customers without orders, recount), through its PHP classes.
 * 3. Swaps EDD's shortcodes and blocks on pages for FluentCart's (EddPages), which the add-on doesn't touch: each
 *    changed post keeps a revision and its old content, so undo puts it back.
 *
 * Running it again adds nothing twice: the add-on skips the steps it has finished (its own progress option), and
 * pages already switched have nothing left to swap. EDD and its data are never changed or turned off.
 */
class EddSwitch
{
    /** The pages this switch changed, with their old content. Not autoloaded. */
    public const OPTION = 'anypress_client_edd_switch';
    private const LOCK = 'anypress_client_edd_switch_lock';
    /** The add-on's progress on the EDD source. */
    public const ADDON_STATE = '__fluent_cart_edd3_migration_steps';
    public const ADDON_CLASS = 'FluentCartMigrator\Classes\MigratorService';

    /** @return array|WP_Error */
    public function run(bool $dry_run = false)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('anypress_shop_unauthorized', __('Only an administrator can switch the shop.', 'anypress-client'), ['status' => 403]);
        }
        $off = FluentPlugin::turn_on('fluent-cart/fluent-cart.php', 'FluentCart', defined('FLUENTCART_VERSION'));
        if ($off) {
            return $off;
        }
        ShopSwitch::turn_on_addon();
        $preflight = self::preflight();
        if (!$dry_run && !$preflight['ready']) {
            return new WP_Error('anypress_shop_not_ready', implode(' ', $preflight['blockers']), ['status' => 409, 'preflight' => $preflight]);
        }
        if (!$dry_run && !$this->lock()) {
            return new WP_Error('anypress_shop_busy', __('A shop switch is already running on this website. Try again in a few minutes.', 'anypress-client'), ['status' => 409]);
        }

        try {
            $report = ['dry_run' => $dry_run, 'from' => 'edd', 'preflight' => $preflight, 'migration' => [], 'pages' => [], 'not_switched' => [], 'notes' => []];
            if (!$preflight['ready']) {
                $report['notes'][] = __('Nothing can be switched until the items under "blockers" are fixed.', 'anypress-client');
                return $report;
            }
            $report['migration'] = $dry_run ? self::addon_progress() : $this->migrate();
            if (is_wp_error($report['migration'])) {
                return $report['migration'];
            }

            $state = self::state();
            EddPages::switch_posts($state, $report, $dry_run);
            if (!$dry_run) {
                update_option(self::OPTION, $state, false);
            }
            if ($dry_run && !$report['migration']['done']) {
                $report['notes'][] = __('The add-on hasn\'t copied the shop yet, so pages whose products aren\'t in FluentCart yet are listed as not switched. Run the switch to copy everything.', 'anypress-client');
            }
            if ($preflight['counts']['subscriptions'] ?? 0) {
                $report['notes'][] = __('EDD Recurring subscriptions are copied, but their renewals only keep charging if FluentCart has the same payment gateway set up with the same Stripe or PayPal account (FluentCart Pro for subscriptions). Switch EDD\'s gateway off once FluentCart\'s is connected, so nobody is charged twice.', 'anypress-client');
            }
            $report['notes'][] = __('Easy Digital Downloads and its data are left as they were. Once you\'re happy with FluentCart, you can turn EDD off.', 'anypress-client');

            return $report;
        } finally {
            if (!$dry_run) {
                $this->unlock();
            }
        }
    }

    /**
     * Puts back the EDD shortcodes and blocks on every page the switch changed. What the add-on copied stays in
     * FluentCart: it has no undo outside its developer mode.
     *
     * @return array|WP_Error
     */
    public function undo(bool $dry_run = false)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('anypress_shop_unauthorized', __('Only an administrator can undo the shop switch.', 'anypress-client'), ['status' => 403]);
        }
        if (!$dry_run && !$this->lock()) {
            return new WP_Error('anypress_shop_busy', __('A shop switch is already running on this website. Try again in a few minutes.', 'anypress-client'), ['status' => 409]);
        }
        try {
            $state = self::state();
            $report = ['dry_run' => $dry_run, 'undo' => true, 'from' => 'edd', 'pages' => [], 'notes' => []];
            EddPages::restore_posts($state, $report, $dry_run);
            if (!$dry_run) {
                update_option(self::OPTION, $state, false);
            }
            $report['notes'][] = __('The products, orders, customers, coupons, subscriptions and licences the FluentCart Migrator add-on copied stay in FluentCart. EDD was never changed.', 'anypress-client');

            return $report;
        } finally {
            if (!$dry_run) {
                $this->unlock();
            }
        }
    }

    /** Whether the switch can run, and what's in the EDD shop. */
    public static function preflight(): array
    {
        $edd = defined('EDD_PLUGIN_FILE') && defined('EDD_VERSION');
        $fluent = defined('FLUENTCART_VERSION');
        $addon = class_exists(self::ADDON_CLASS) && defined('FLUENTCART_MIGRATOR_VERSION');
        $report = [
            'ready' => false,
            'edd' => $edd ? EDD_VERSION : null,
            'fluentcart' => $fluent ? FLUENTCART_VERSION : null,
            'migrator_addon' => $addon ? FLUENTCART_MIGRATOR_VERSION : null,
            'blockers' => [],
            'counts' => [],
            'warnings' => [],
        ];
        if (!$edd) {
            $report['blockers'][] = __('Easy Digital Downloads isn\'t active. Its shop can only be read while it is.', 'anypress-client');
        } elseif (version_compare(EDD_VERSION, '3.0', '<')) {
            $report['blockers'][] = __('Easy Digital Downloads is older than 3.0. Update it to 3 (its own upgrade moves the orders into its new tables), then run this again.', 'anypress-client');
        }
        if (!$fluent) {
            $report['blockers'][] = __('FluentCart isn\'t active. Install and turn on FluentCart (free, wordpress.org) first; the shop is copied into it.', 'anypress-client');
        }
        if (!$addon) {
            $report['blockers'][] = ShopSwitch::addon_blocker();
        }
        if ($edd && $fluent && $addon && !$report['blockers']) {
            $source = self::source();
            $can = $source->canMigrate();
            if (is_wp_error($can)) {
                $report['blockers'][] = $can->get_error_message();
            }
            $stats = $source->getStats();
            $report['counts'] = [
                'products' => (int) ($stats['products_count'] ?? 0),
                'orders' => (int) ($stats['orders_count'] ?? 0),
                'transactions' => (int) ($stats['transactions_count'] ?? 0),
                'customers' => (int) ($stats['customers_count'] ?? 0),
                'coupons' => (int) ($stats['coupons_count'] ?? 0),
                'subscriptions' => (int) ($stats['subscriptions_count'] ?? 0),
                'licenses' => (int) ($stats['licenses_count'] ?? 0),
                'gateways' => array_values(array_map('strval', array_keys((array) ($stats['gateways'] ?? [])))),
            ];
            if (strpos((string) FLUENTCART_MIGRATOR_VERSION, 'beta') !== false) {
                /* translators: %s: add-on version. */
                $report['warnings'][] = sprintf(__('The FluentCart Migrator add-on is a beta (%s).', 'anypress-client'), FLUENTCART_MIGRATOR_VERSION);
            }
        }
        $report['ready'] = !$report['blockers'];

        return $report;
    }

    private static function source()
    {
        $class = self::ADDON_CLASS;

        return new $class();
    }

    private static function addon_progress(): array
    {
        $state = get_option(self::ADDON_STATE, []);
        $state = is_array($state) ? $state : [];
        $steps = [];
        foreach (['products', 'tax_rates', 'coupons', 'payments'] as $step) {
            $steps[$step] = ($state[$step] ?? '') === 'yes' ? 'done' : 'not run';
        }

        return ['done' => ($state['payments'] ?? '') === 'yes', 'steps' => $steps];
    }

    /**
     * Runs the add-on's EDD migration to the end, the same steps as its admin screen. Each step the add-on has
     * finished is skipped.
     *
     * @return array|WP_Error
     */
    private function migrate()
    {
        if (function_exists('set_time_limit')) {
            // A switch copies a whole plugin's data in one admin request.
            @set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
        }
        wp_raise_memory_limit('admin');
        $source = self::source();
        $steps = [];
        $done = fn ($result, string $text) => is_wp_error($result) ? 'failed: ' . $result->get_error_message() : (!empty($result['skipped']) ? 'already done' : $text);

        $result = $source->migrateProducts();
        if (is_wp_error($result)) {
            return $result;
        }
        $steps['products'] = $done($result, sprintf('%d copied, %d failed', $result['migrated'] ?? 0, $result['failed'] ?? 0));
        $result = $source->migrateTaxRates();
        $steps['tax_rates'] = $done($result, 'done');
        $result = $source->migrateCoupons();
        $steps['coupons'] = $done($result, sprintf('%d copied', $result['migrated'] ?? 0));

        $processed = 0;
        do {
            $result = $source->migratePayments($source->getPaymentResumePage(), 500, 0);
            if (is_wp_error($result)) {
                return $result;
            }
            $processed += (int) ($result['processed'] ?? 0);
        } while (!empty($result['has_more']));
        $steps['orders'] = $done($result, sprintf('%d processed', $processed));

        $result = $source->migrateMissingCustomers();
        $steps['customers_without_orders'] = $done($result, sprintf('%d copied', $result['migrated'] ?? 0));
        foreach ($source->getRecountSubsteps() as $substep) {
            $source->recountStats($substep);
        }
        $steps['recount'] = 'done';

        // The add-on's log of orders it couldn't copy (or copied with a gap): EDD order id => its message.
        $logs = $source->getLogs();
        $not_migrated = [];
        foreach ((array) ($logs['logs'] ?? []) as $order => $log) {
            $not_migrated[] = sprintf('EDD order %s: %s', $order, is_array($log) ? (string) ($log['message'] ?? '') : (string) $log);
        }

        return ['done' => true, 'steps' => $steps, 'not_migrated' => $not_migrated];
    }

    public static function state(): array
    {
        $state = get_option(self::OPTION);
        $state = is_array($state) ? $state : [];

        return ['pages' => is_array($state['pages'] ?? null) ? $state['pages'] : []];
    }

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

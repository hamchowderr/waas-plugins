<?php

namespace WaaSClient\Shop;

use WP_CLI;

/**
 * Moves this website's WooCommerce shop to FluentCart.
 */
class ShopCommand
{
    private ShopSwitch $switch;

    public function __construct(?ShopSwitch $switch = null)
    {
        $this->switch = $switch ?? new ShopSwitch();
    }

    /**
     * Copies the WooCommerce shop to FluentCart with the FluentCart Migrator add-on, then fills its gaps.
     *
     * Checks WooCommerce, FluentCart and the FluentCart Migrator add-on are active and reports the order storage
     * (HPOS on or off), what's in the shop and whether subscriptions are in use. Then runs the add-on's WooCommerce
     * migration, copies the product gallery images and the shipping zones and methods it leaves out, and merges
     * FluentCart customers split across one WordPress user. Lists the product tags and tax rates to check by hand
     * (rates are never changed) and compares WooCommerce with FluentCart: products, SKUs, orders, the sum of order
     * totals, refunds, coupons and customers. Running it again adds nothing twice. WooCommerce is never changed,
     * deactivated or deleted.
     *
     * Run it as an administrator (--user=<admin login>).
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Only check the shop and report what the switch would do. Changes nothing.
     *
     * [--undo]
     * : Undo this command's fixes: put back the galleries, remove the copied shipping zones and split the merged
     * customers again. What the add-on copied stays in FluentCart.
     *
     * [--format=<format>]
     * : How to show the report.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp waas shop switch --user=admin --dry-run
     *     wp waas shop switch --user=admin
     *     wp waas shop switch --user=admin --undo
     *
     * @subcommand switch
     */
    public function switch_(array $args, array $assoc_args): void
    {
        if (!current_user_can('manage_options')) {
            WP_CLI::error('Run this as an administrator: add --user=<admin login>.');
        }
        $dry_run = (bool) WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
        $undo = (bool) WP_CLI\Utils\get_flag_value($assoc_args, 'undo', false);
        $format = WP_CLI\Utils\get_flag_value($assoc_args, 'format', 'table') === 'json' ? 'json' : 'table';

        $report = $undo ? $this->switch->undo($dry_run) : $this->switch->run($dry_run);
        if (is_wp_error($report)) {
            $data = $report->get_error_data();
            if ($format === 'table' && is_array($data) && isset($data['preflight'])) {
                self::print_preflight($data['preflight']);
            }
            WP_CLI::error($report->get_error_message());
        }
        if ($format === 'json') {
            WP_CLI::line((string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        if ($undo) {
            WP_CLI::line(sprintf('Galleries put back: %d. Shipping zones removed: %d (methods: %d). Customers split again: %d.', $report['gallery_images'], $report['shipping']['zones'], $report['shipping']['methods'], $report['customers']));
            array_map([WP_CLI::class, 'line'], array_map([self::class, 'plain'], $report['notes']));
            WP_CLI::success($dry_run ? 'Dry run: nothing was changed.' : 'The fixes are undone.');
            return;
        }
        self::print_report($report);
        if (!$report['preflight']['ready']) {
            WP_CLI::error('The shop can\'t be switched yet.');
        }
        WP_CLI::success($dry_run ? 'Dry run: nothing was changed.' : 'Shop switched to FluentCart.');
    }

    private static function plain($text): string
    {
        return wp_strip_all_tags((string) $text);
    }

    private static function print_preflight(array $preflight): void
    {
        WP_CLI::line(sprintf(
            'WooCommerce %s, FluentCart %s, FluentCart Migrator %s',
            self::plain($preflight['woocommerce'] ?? 'not active'),
            self::plain($preflight['fluentcart'] ?? 'not active'),
            self::plain($preflight['migrator_addon'] ?? 'not active')
        ));
        if (!empty($preflight['hpos'])) {
            WP_CLI::line('Orders stored in: ' . self::plain($preflight['hpos']['storage']) . ($preflight['hpos']['sync'] ? ' (kept in sync with the other storage)' : ''));
        }
        if (!empty($preflight['counts'])) {
            $counts = $preflight['counts'];
            $statuses = implode(', ', array_map(fn ($status, $count) => $status . ' ' . $count, array_keys($counts['orders_by_status']), $counts['orders_by_status']));
            WP_CLI::line(sprintf(
                'Products %d (%d variable, %d variations), orders %d (%s; %d by guests), refunds %d, coupons %d, registered customers %d, tax rates %d, shipping zones %d, product tags %d',
                $counts['products'], $counts['variable_products'], $counts['variations'], $counts['orders'], $statuses ?: 'none', $counts['guest_orders'],
                $counts['refunds'], $counts['coupons'], $counts['registered_customers'], $counts['tax_rates'], $counts['shipping_zones'], $counts['product_tags']
            ));
            $subs = $preflight['subscriptions'];
            WP_CLI::line('Subscriptions: ' . ($subs['in_use'] ? $subs['count'] . ' (WooCommerce Subscriptions)' : ($subs['plugin'] ? 'none (WooCommerce Subscriptions is active)' : 'not in use')));
        }
        foreach ($preflight['blockers'] as $blocker) {
            WP_CLI::warning(self::plain($blocker));
        }
        foreach ($preflight['warnings'] as $warning) {
            WP_CLI::warning(self::plain($warning));
        }
        WP_CLI::line('');
    }

    private static function print_report(array $report): void
    {
        self::print_preflight($report['preflight']);
        if (!$report['preflight']['ready']) {
            return;
        }

        WP_CLI::line('FluentCart Migrator');
        WP_CLI\Utils\format_items('table', array_map(fn ($step, $result) => ['step' => $step, 'result' => self::plain($result)], array_keys($report['migration']['steps']), $report['migration']['steps']), ['step', 'result']);
        foreach ($report['migration']['not_migrated'] ?? [] as $line) {
            WP_CLI::warning('Not migrated: ' . self::plain($line));
        }
        WP_CLI::line('');

        $fixes = $report['fixes'];
        $gallery = $fixes['gallery_images'];
        WP_CLI::line(sprintf('Gallery images: %d added to %d products%s', $gallery['images'], $gallery['products'], $report['dry_run'] ? ' (dry run)' : ''));
        foreach ($gallery['missing'] as $line) {
            WP_CLI::warning(self::plain($line));
        }
        if ($fixes['shipping']['zones']) {
            WP_CLI::line('Shipping zones');
            WP_CLI\Utils\format_items('table', array_map(fn ($zone) => [
                'zone' => self::plain($zone['zone']),
                'region' => self::plain($zone['region']),
                'methods' => self::plain(implode('; ', $zone['methods'])),
                'status' => self::plain($zone['status']),
            ], $fixes['shipping']['zones']), ['zone', 'region', 'methods', 'status']);
        }
        foreach ($fixes['shipping']['lost'] as $line) {
            WP_CLI::warning('Shipping: ' . self::plain($line));
        }
        if ($fixes['customers']) {
            WP_CLI::line('Customers merged');
            WP_CLI\Utils\format_items('table', array_map(fn ($row) => array_map([self::class, 'plain'], $row), $fixes['customers']), ['user_id', 'customer', 'emails', 'merged', 'orders moved', 'status']);
        }
        WP_CLI::line('');

        if ($report['review']['product_tags']) {
            WP_CLI::line('Product tags (FluentCart has none; check by hand)');
            WP_CLI\Utils\format_items('table', array_map(fn ($row) => array_map([self::class, 'plain'], $row), $report['review']['product_tags']), ['id', 'product', 'tags']);
        }
        if ($report['review']['tax_rates']) {
            WP_CLI::line('Tax rates that differ (not changed; check by hand)');
            WP_CLI\Utils\format_items('table', array_map(fn ($row) => [
                'place' => self::plain($row['place']),
                'woocommerce' => self::plain($row['woocommerce']),
                'fluentcart' => self::plain($row['fluentcart']),
                'differences' => self::plain(implode(' ', $row['differences'])),
            ], $report['review']['tax_rates']), ['place', 'woocommerce', 'fluentcart', 'differences']);
        }
        WP_CLI::line('');

        WP_CLI::line('WooCommerce and FluentCart compared');
        WP_CLI\Utils\format_items('table', array_map(fn ($row) => [
            'item' => $row['item'],
            'woocommerce' => self::plain($row['woocommerce']),
            'fluentcart' => self::plain($row['fluentcart']),
            'same' => $row['same'] ? 'yes' : 'no',
        ], $report['compare']), ['item', 'woocommerce', 'fluentcart', 'same']);
        foreach ($report['compare'] as $row) {
            foreach ($row['differences'] as $difference) {
                WP_CLI::line('  ' . $row['item'] . ': ' . self::plain($difference));
            }
        }
        WP_CLI::line('');
        foreach ($report['notes'] as $note) {
            WP_CLI::line(self::plain($note));
        }
    }
}

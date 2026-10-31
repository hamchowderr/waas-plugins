<?php

namespace WaaSClient\Moving;

use WP_CLI;

/**
 * `wp waas support switch`, `wp waas affiliate switch` and `wp waas community switch`: one command per Fluent
 * plugin importer (see NativeImport).
 */
class ImportCommand
{
    private NativeImport $import;

    public function __construct(NativeImport $import)
    {
        $this->import = $import;
    }

    /**
     * Brings this website's help desk, affiliate program or community into Fluent Support, FluentAffiliate or
     * FluentCommunity with that plugin's own importer.
     *
     * support: Awesome Support, SupportCandy or JS Help Desk tickets with their replies, attachments, customers and
     * agents. affiliate: AffiliateWP, Affiliates Manager, Solid Affiliate, SliceWP or Ultimate Affiliate Pro
     * affiliates, referrals, customers, payouts and visits (into an empty FluentAffiliate only). community:
     * BuddyPress or BuddyBoss groups, members, posts, comments and reactions (into an empty FluentCommunity only).
     *
     * It checks first, holds back email while it runs, then shows what the source holds next to what came across.
     * The source plugin and its data are never changed. Run it as an administrator (--user=<admin login>).
     *
     * ## OPTIONS
     *
     * [--from=<source>]
     * : The source plugin, when more than one is active (see the dry run for the names).
     *
     * [--dry-run]
     * : Only check and report what the switch would do. Changes nothing.
     *
     * [--undo]
     * : Remove what the switch added.
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
     *     wp waas support switch --user=admin --dry-run
     *     wp waas affiliate switch --user=admin --from=slicewp
     *     wp waas community switch --user=admin --undo
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
        $from = (string) WP_CLI\Utils\get_flag_value($assoc_args, 'from', '');

        $report = $undo ? $this->import->undo($dry_run) : $this->import->run($dry_run, $from);
        if (is_wp_error($report)) {
            $data = $report->get_error_data();
            if ($format === 'table' && is_array($data) && isset($data['preflight'])) {
                self::print_preflight($data['preflight']);
            }
            WP_CLI::error(self::plain($report->get_error_message()));
        }
        if ($format === 'json') {
            WP_CLI::line((string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        if ($undo) {
            $removed = array_map(fn ($item, $count) => $item . ' ' . $count, array_keys($report['removed']), $report['removed']);
            WP_CLI::line(($dry_run ? 'Would remove: ' : 'Removed: ') . ($removed ? implode(', ', $removed) : 'nothing') . '.');
            array_map([WP_CLI::class, 'line'], array_map([self::class, 'plain'], $report['notes']));
            WP_CLI::success($dry_run ? 'Dry run: nothing was changed.' : 'The switch is undone.');
            return;
        }
        self::print_preflight($report['preflight']);
        if (!$report['preflight']['ready']) {
            WP_CLI::error('This can\'t be switched yet.');
        }
        $columns = $dry_run ? ['item', 'source', 'before'] : ['item', 'source', 'before', 'after', 'added', 'missing'];
        WP_CLI\Utils\format_items('table', $report['compare'], $columns);
        WP_CLI::line('');
        if ($report['skipped']) {
            WP_CLI::line(sprintf('Skipped by the importer (already there, or nothing to import): %d.', $report['skipped']));
        }
        foreach ($report['fixed'] as $line) {
            WP_CLI::line(self::plain($line));
        }
        foreach ($report['lost'] as $line) {
            WP_CLI::warning('Not brought across: ' . self::plain($line));
        }
        foreach ($report['notes'] as $note) {
            WP_CLI::line(self::plain($note));
        }
        WP_CLI::success($dry_run ? 'Dry run: nothing was changed.' : 'Brought across into ' . $report['preflight']['target'] . '.');
    }

    private static function plain($text): string
    {
        return wp_strip_all_tags((string) $text);
    }

    private static function print_preflight(array $preflight): void
    {
        $found = [];
        foreach ($preflight['sources'] as $slug => $source) {
            if ($source['found']) {
                $found[] = $source['name'] . ' (' . $slug . ')';
            }
        }
        WP_CLI::line(sprintf(
            '%s %s. Found: %s.',
            $preflight['target'],
            $preflight['target_version'] !== '' ? $preflight['target_version'] : 'not active',
            $found ? implode(', ', $found) : 'no source plugin'
        ));
        foreach ($preflight['blockers'] as $blocker) {
            WP_CLI::warning(self::plain($blocker));
        }
        foreach ($preflight['warnings'] as $warning) {
            WP_CLI::warning(self::plain($warning));
        }
        WP_CLI::line('');
    }
}

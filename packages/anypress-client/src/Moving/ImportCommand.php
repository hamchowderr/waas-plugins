<?php

namespace AnyPressClient\Moving;

use WP_CLI;

/**
 * `wp anypress support switch`, `wp anypress affiliate switch`, `wp anypress community switch`,
 * `wp anypress course switch`, `wp anypress circle switch` and `wp anypress skool switch`: one command per switch
 * (see NativeImport).
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
     * course: LearnDash, Tutor LMS or LifterLMS courses with their outline, students and progress, as FluentCommunity
     * courses (--from=learndash, tutor or lifterlms when more than one is here).
     * circle: a Circle community through Circle's Admin API: discussion spaces with their members and moderators,
     * space groups, topics, posts, comments and replies, course spaces as courses with their sections, lessons and
     * students, members as WordPress users (into an empty FluentCommunity). skool: a Skool group's members from the
     * members CSV its admin exports from Skool's Members tab, as WordPress users in one FluentCommunity space or course,
     * tagged in FluentCRM with their answers to the membership questions (rows without an email are skipped). calendly: a Calendly account through
     * Calendly's API: availability schedules, event types as FluentBooking booking types and upcoming bookings.
     * memberpress: everyone with an active
     * MemberPress membership into the FluentCommunity space or course for it, tagged in FluentCRM; with --billing, active Stripe
     * subscriptions move to FluentCart and keep renewing in Stripe; other payments stay.
     *
     * It checks first, holds back email while it runs, then shows what the source holds next to what came across.
     * The source plugin and its data are never changed. Run it as an administrator (--user=<admin login>).
     *
     * ## OPTIONS
     *
     * [--from=<source>]
     * : The source plugin, when more than one is active (see the dry run for the names).
     *
     * [--token=<token>]
     * : circle and calendly only: the Circle community's Admin V2 API token, or a Calendly personal access token. Or
     * set CIRCLE_API_TOKEN or CALENDLY_API_TOKEN, which keeps it out of the shell history. Used for this run only,
     * never saved.
     *
     * [--file=<path>]
     * : skool only: the members CSV from the Skool group's Members tab (Export). Read for this run only, never saved.
     *
     * [--space=<id>]
     * : skool only: the FluentCommunity space or course the members join. Left out, a new private space called
     * "Skool members" is made.
     *
     * [--map=<pairs>]
     * : memberpress only: which FluentCommunity space or course each membership opens, as
     * <membership id>:<space or course id> pairs separated by commas. A membership left out gets a new private space.
     *
     * [--billing]
     * : memberpress only: also move active Stripe subscriptions to FluentCart, where they keep renewing in Stripe.
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
     *     wp anypress support switch --user=admin --dry-run
     *     wp anypress affiliate switch --user=admin --from=slicewp
     *     wp anypress community switch --user=admin --undo
     *     wp anypress course switch --user=admin --dry-run
     *     wp anypress course switch --user=admin --from=tutor
     *     CIRCLE_API_TOKEN=... wp anypress circle switch --user=admin --dry-run
     *     CALENDLY_API_TOKEN=... wp anypress calendly switch --user=admin
     *     wp anypress skool switch --user=admin --file=members.csv --dry-run
     *     wp anypress skool switch --user=admin --file=members.csv --space=12
     *     wp anypress memberpress switch --user=admin --map=12:5,14:9
     *     wp anypress memberpress switch --user=admin --billing --dry-run
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

        $token = (string) WP_CLI\Utils\get_flag_value($assoc_args, 'token', (string) getenv($this->import->key() === 'calendly' ? 'CALENDLY_API_TOKEN' : 'CIRCLE_API_TOKEN'));
        $map = [];
        foreach (array_filter(explode(',', (string) WP_CLI\Utils\get_flag_value($assoc_args, 'map', ''))) as $pair) {
            [$membership, $space] = array_pad(explode(':', $pair, 2), 2, 0);
            $map[(int) $membership] = (int) $space;
        }
        $billing = (bool) WP_CLI\Utils\get_flag_value($assoc_args, 'billing', false);
        $csv = self::csv((string) WP_CLI\Utils\get_flag_value($assoc_args, 'file', ''));
        $space = (int) WP_CLI\Utils\get_flag_value($assoc_args, 'space', 0);
        $this->import->configure(array_intersect_key(['token' => $token, 'map' => $map, 'billing' => $billing, 'csv' => $csv, 'space' => $space], $this->import->inputs()));
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

    /** The file given with --file, read through WordPress's filesystem API, or '' without one. */
    private static function csv(string $path): string
    {
        if ($path === '') {
            return '';
        }
        global $wp_filesystem;
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();
        $contents = $wp_filesystem && $wp_filesystem->is_readable($path) ? $wp_filesystem->get_contents($path) : false;
        if (!is_string($contents)) {
            WP_CLI::error(sprintf('Can\'t read %s.', $path));
        }

        return $contents;
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

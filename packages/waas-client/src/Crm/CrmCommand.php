<?php

namespace WaaSClient\Crm;

use WP_CLI;

/**
 * Brings this website's buyers and email-list subscribers into FluentCRM.
 */
class CrmCommand
{
    private CrmSwitch $switch;

    public function __construct(?CrmSwitch $switch = null)
    {
        $this->switch = $switch ?? new CrmSwitch();
    }

    /**
     * Brings WooCommerce buyers, MailPoet subscribers and Newsletter plugin subscribers into FluentCRM.
     *
     * WooCommerce buyers, guests included, come from the paid orders (HPOS on or off): name, email, phone, billing
     * address, order count and total spent (custom fields), and a "Product: Past" tag for each product bought.
     * MailPoet and Newsletter subscribers come with their lists and statuses (unsubscribed and bounced stay that way).
     * A contact is subscribed only where a source holds a consent record; buyers without one are transactional
     * (order emails only). No email is sent. Running it again changes nothing already there. The source plugins are
     * never changed.
     *
     * Run it as an administrator (--user=<admin login>).
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Only check and report what the switch would do. Changes nothing.
     *
     * [--undo]
     * : Remove the contacts, tags, lists and fields this command created, and put back what it changed on contacts
     * that were already in FluentCRM.
     *
     * [--without-consent=<status>]
     * : Status for buyers no source holds a consent record for.
     * ---
     * default: transactional
     * options:
     *   - transactional
     *   - unsubscribed
     * ---
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
     *     wp waas crm switch --user=admin --dry-run
     *     wp waas crm switch --user=admin
     *     wp waas crm switch --user=admin --undo
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
        $without = (string) WP_CLI\Utils\get_flag_value($assoc_args, 'without-consent', 'transactional');

        $report = $undo ? $this->switch->undo($dry_run) : $this->switch->run($dry_run, $without);
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
            WP_CLI::line(sprintf(
                'Contacts removed: %d. Contacts put back: %d. Tags removed: %d. Lists removed: %d. Fields removed: %d.',
                $report['contacts_removed'], $report['contacts_restored'], $report['tags_removed'], $report['lists_removed'], $report['fields_removed']
            ));
            array_map([WP_CLI::class, 'line'], array_map([self::class, 'plain'], $report['notes']));
            WP_CLI::success($dry_run ? 'Dry run: nothing was changed.' : 'The CRM switch is undone.');
            return;
        }
        self::print_report($report);
        if (!$report['preflight']['ready']) {
            WP_CLI::error('The CRM can\'t be switched yet.');
        }
        WP_CLI::success($dry_run ? 'Dry run: nothing was changed.' : 'Contacts brought into FluentCRM.');
    }

    private static function plain($text): string
    {
        return wp_strip_all_tags((string) $text);
    }

    private static function print_preflight(array $preflight): void
    {
        $sources = $preflight['sources'];
        WP_CLI::line(sprintf(
            'FluentCRM %s. WooCommerce %s, MailPoet %s, Newsletter %s.',
            self::plain($preflight['fluentcrm'] ?? 'not active'),
            $sources['woocommerce']['active'] ? 'active (orders in ' . ($sources['woocommerce']['hpos'] ? 'HPOS tables' : 'posts') . ')' : 'not active',
            $sources['mailpoet']['found'] ? 'found' : 'not found',
            $sources['newsletter']['found'] ? 'found' : 'not found'
        ));
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

        $sources = [];
        if (isset($report['sources']['woocommerce'])) {
            $s = $report['sources']['woocommerce'];
            $sources[] = ['source' => 'WooCommerce', 'people' => $s['buyers'], 'details' => sprintf('%d orders read; %d registered, %d guests; %d opted in, %d opted out; %d products tagged', $s['orders'], $s['registered'], $s['guests'], $s['opted_in'], $s['opted_out'], count($s['products']))];
        }
        if (isset($report['sources']['mailpoet'])) {
            $s = $report['sources']['mailpoet'];
            $sources[] = ['source' => 'MailPoet', 'people' => $s['subscribers'], 'details' => self::counts($s['by_status']) . '; ' . $s['trashed'] . ' trashed left out; lists: ' . (self::counts($s['lists']) ?: 'none')];
        }
        if (isset($report['sources']['newsletter'])) {
            $s = $report['sources']['newsletter'];
            $sources[] = ['source' => 'Newsletter', 'people' => $s['subscribers'], 'details' => self::counts($s['by_status']) . '; lists: ' . (self::counts($s['lists']) ?: 'none')];
        }
        WP_CLI\Utils\format_items('table', $sources, ['source', 'people', 'details']);
        WP_CLI::line('');

        $c = $report['contacts'];
        WP_CLI::line(sprintf('Contacts: %d (%s %d, updated %d, unchanged %d, failed %d). Statuses: %s.', $c['total'], $report['dry_run'] ? 'new' : 'created', $c['created'], $c['updated'], $c['unchanged'], $c['failed'], self::counts($c['by_status'])));
        WP_CLI::line('Consent: ' . self::plain($report['consent']['rule']));
        WP_CLI::line(sprintf('Tags created: %s. Lists created: %s. Fields created: %s.', self::names($report['tags']['created']), self::names($report['lists']['created']), self::names($report['fields']['created'])));
        if ($c['interested_removed']) {
            WP_CLI::line(sprintf('"Interested" tags removed from buyers who now have "Past": %d.', $c['interested_removed']));
        }
        WP_CLI::line('');
        if ($report['rows']) {
            WP_CLI\Utils\format_items('table', array_map(fn ($row) => array_map([self::class, 'plain'], $row), $report['rows']), ['email', 'sources', 'status', 'consent', 'tags', 'lists', 'action']);
            WP_CLI::line('');
        }
        foreach ($report['lost'] as $line) {
            WP_CLI::warning('Not brought across: ' . self::plain($line));
        }
        foreach ($report['notes'] as $note) {
            WP_CLI::line(self::plain($note));
        }
    }

    private static function counts(array $counts): string
    {
        return implode(', ', array_map(fn ($key, $count) => $key . ' ' . $count, array_keys($counts), $counts));
    }

    private static function names(array $names): string
    {
        return $names ? implode(', ', array_map([self::class, 'plain'], $names)) : 'none';
    }
}

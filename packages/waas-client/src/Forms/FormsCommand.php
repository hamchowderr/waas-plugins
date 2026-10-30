<?php

namespace WaaSClient\Forms;

use WP_CLI;

/**
 * Moves this website's forms to Fluent Forms.
 */
class FormsCommand
{
    private FormsSwitch $switch;

    public function __construct(?FormsSwitch $switch = null)
    {
        $this->switch = $switch ?? new FormsSwitch();
    }

    /**
     * Converts Contact Form 7, WPForms (Lite) and Ninja Forms forms to Fluent Forms and switches the pages over.
     *
     * Converts each form and its entries with Fluent Forms' own importer and fixes its known mistakes, reports every
     * field and setting that didn't come across, then swaps the old shortcodes and blocks in pages, posts, templates,
     * template parts, synced patterns and widgets for the Fluent Forms ones. Each changed page keeps a revision.
     * Running it again changes nothing already done. The old form plugins are never changed, deactivated or deleted.
     *
     * Run it as an administrator (--user=<admin login>), with Fluent Forms and the old form plugins active.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Only report what would be converted, dropped and switched. Changes nothing.
     *
     * [--undo]
     * : Put the old shortcodes and blocks back where the switch replaced them. The Fluent Forms forms and entries stay.
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
     *     wp waas forms switch --user=admin --dry-run
     *     wp waas forms switch --user=admin
     *     wp waas forms switch --user=admin --undo
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
            WP_CLI::error($report->get_error_message());
        }
        if ($format === 'json') {
            WP_CLI::line((string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        self::print_report($report);
        if ($undo) {
            WP_CLI::success($dry_run ? 'Dry run: nothing was changed.' : 'The old shortcodes and blocks are back.');
        } else {
            WP_CLI::success($dry_run ? 'Dry run: nothing was changed.' : 'Forms switched to Fluent Forms.');
        }
    }

    private static function print_report(array $report): void
    {
        $plain = fn ($text) => wp_strip_all_tags((string) $text);
        if (!empty($report['forms'])) {
            WP_CLI::line('Forms');
            WP_CLI\Utils\format_items('table', array_map(fn ($form) => [
                'from' => $form['source'] . ' #' . $form['source_id'],
                'title' => $plain($form['title']),
                'fluent form' => $form['fluent_id'] ? '#' . $form['fluent_id'] : '-',
                'status' => $form['status'],
                'entries' => $form['entries']['imported'] . ' of ' . $form['entries']['found'],
                'dropped fields' => count($form['dropped_fields']),
            ], $report['forms']), ['from', 'title', 'fluent form', 'status', 'entries', 'dropped fields']);

            foreach ($report['forms'] as $form) {
                $lines = [];
                foreach ($form['dropped_fields'] as $field) {
                    $lines[] = sprintf('  Dropped: %s (%s%s). %s', $plain($field['name'] ?: $field['label']), $plain($field['type']), $field['label'] && $field['label'] !== $field['name'] ? ', "' . $plain($field['label']) . '"' : '', $plain($field['reason']));
                }
                foreach ($form['lost'] as $lost) {
                    $lines[] = '  Not carried over: ' . $plain($lost);
                }
                foreach ($form['notes'] ?? [] as $note) {
                    $lines[] = '  Note: ' . $plain($note);
                }
                if ($form['entries']['note'] !== '') {
                    $lines[] = '  Entries: ' . $plain($form['entries']['note']);
                }
                if ($lines) {
                    WP_CLI::line('');
                    WP_CLI::line(sprintf('%s #%s "%s"', $form['source'], $form['source_id'], $plain($form['title'])));
                    array_map([WP_CLI::class, 'line'], $lines);
                }
            }
            WP_CLI::line('');
        }
        if (!empty($report['pages'])) {
            WP_CLI::line('Pages');
            WP_CLI\Utils\format_items('table', array_map(fn ($page) => [
                'id' => $page['id'],
                'type' => $page['type'],
                'title' => $plain($page['title']),
                'forms' => $page['forms'],
                'status' => $page['status'],
            ], $report['pages']), ['id', 'type', 'title', 'forms', 'status']);
            WP_CLI::line('');
        }
        if (!empty($report['widgets'])) {
            WP_CLI::line('Widgets');
            WP_CLI\Utils\format_items('table', $report['widgets'], ['widget', 'forms', 'status']);
            WP_CLI::line('');
        }
        foreach ($report['not_switched'] ?? [] as $item) {
            WP_CLI::warning(sprintf('Not switched in %s: %s. %s', $plain($item['where']), $plain($item['what']), $plain($item['why'])));
        }
        foreach ($report['notes'] ?? [] as $note) {
            WP_CLI::line($plain($note));
        }
    }
}

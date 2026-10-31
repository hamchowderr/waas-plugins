<?php

namespace WaaSClient\Moving;

use WP_Error;

/**
 * A switch that runs a Fluent plugin's own importer on a moved-in website: Fluent Support (help desk tickets),
 * FluentAffiliate (affiliates and referrals) and FluentCommunity (a BuddyPress or BuddyBoss community).
 *
 * The Fluent plugin does the import; this adds what its importer leaves to the person running it:
 * - a check first: the Fluent plugin is active, the source plugin is found, and (where the importer would mix the
 *   two or wipe first) the Fluent plugin holds nothing yet;
 * - counts from the source before and from the Fluent plugin after, side by side, so a short import shows;
 * - no email while it runs (wp_mail is held);
 * - one run at a time;
 * - an undo that removes only what the import added.
 * The source plugin and its data are never changed: none of the importers' "delete the source" steps is called.
 */
abstract class NativeImport
{
    private int $blocked = 0;

    /** Short name: support, affiliate or community. */
    abstract public function key(): string;

    /** The Fluent plugin's name. */
    abstract public function target_name(): string;

    /** The Fluent plugin's version, or '' when it isn't active. */
    abstract protected function target_version(): string;

    /**
     * Source plugins this switch can import from, found or not: slug => ['name', 'found' => bool, 'counts' => [..]].
     * Counts are only read for a found source.
     */
    abstract protected function sources(): array;

    /** What the Fluent plugin holds now: item => count. */
    abstract protected function target_counts(): array;

    /** Why this can't run on top of what the Fluent plugin already holds, or '' when it can. */
    abstract protected function target_blocker(array $counts): string;

    /**
     * Runs the importer from $source and returns what undo needs to know, plus 'skipped' (int) and 'fixed' (what
     * this put right after the importer, one sentence each).
     */
    abstract protected function import(string $source, array $before): array;

    /** Source count key => target count key, for the comparison. */
    abstract protected function compare_keys(): array;

    /** Removes what import() added, as recorded; returns item => count removed. */
    abstract protected function remove(array $state, bool $dry_run): array;

    /** What the run will do to the Fluent plugin besides the import, to say before it runs. */
    protected function target_warnings(): array
    {
        return [];
    }

    /** Source plugins this can't import from (plugin file => name), to warn about. */
    protected function unsupported(): array
    {
        return [];
    }

    /** What the importer doesn't bring across, for this source. */
    abstract protected function lost(string $source): array;

    public function option(): string
    {
        return 'waas_client_' . $this->key() . '_import';
    }

    private function lock_option(): string
    {
        return $this->option() . '_lock';
    }

    /** Whether the switch can run, and what each source holds. */
    public function preflight(string $from = ''): array
    {
        $version = $this->target_version();
        $report = [
            'ready' => false,
            'target' => $this->target_name(),
            'target_version' => $version,
            'sources' => [],
            'source' => '',
            'target_counts' => [],
            'blockers' => [],
            'warnings' => [],
        ];
        if ($version === '') {
            /* translators: %s: a plugin name such as Fluent Support. */
            $report['blockers'][] = sprintf(__('%s isn\'t active. Turn it on first.', 'waas-client'), $this->target_name());
        }
        $found = [];
        foreach ($this->sources() as $slug => $source) {
            $report['sources'][$slug] = $source;
            if ($source['found']) {
                $found[] = $slug;
            }
        }
        foreach ($this->unsupported() as $file => $name) {
            if (self::plugin_active($file)) {
                /* translators: 1: a plugin name, 2: a plugin name such as Fluent Support. */
                $report['warnings'][] = sprintf(__('%1$s is active, and %2$s has no importer for it. Its data stays where it is.', 'waas-client'), $name, $this->target_name());
            }
        }
        if ($from !== '' && !isset($report['sources'][$from])) {
            /* translators: 1: what was asked for, 2: the list of sources. */
            $report['blockers'][] = sprintf(__('"%1$s" isn\'t a source this switch knows. Use one of: %2$s.', 'waas-client'), $from, implode(', ', array_keys($report['sources'])));
        } elseif ($from !== '' && !in_array($from, $found, true)) {
            /* translators: %s: a plugin name. */
            $report['blockers'][] = sprintf(__('%s isn\'t active on this website.', 'waas-client'), $report['sources'][$from]['name']);
        } elseif ($from === '' && count($found) > 1) {
            /* translators: %s: the list of sources. */
            $report['blockers'][] = sprintf(__('More than one source is active (%s). Pick one with "from".', 'waas-client'), implode(', ', $found));
        } elseif (!$found) {
            /* translators: %s: the list of plugin names. */
            $report['blockers'][] = sprintf(__('None of these is active, so there is nothing to bring across: %s.', 'waas-client'), implode(', ', array_column($report['sources'], 'name')));
        } else {
            $report['source'] = $from !== '' ? $from : $found[0];
        }
        if ($version !== '') {
            $report['target_counts'] = $this->target_counts();
            $state = $this->state();
            if (!empty($state['source'])) {
                /* translators: %s: a date. */
                $report['blockers'][] = sprintf(__('This switch already ran here (%s). Undo it first to run it again.', 'waas-client'), (string) ($state['at'] ?? ''));
            } else {
                $report['warnings'] = array_merge($report['warnings'], $this->target_warnings());
                $blocker = $this->target_blocker($report['target_counts']);
                if ($blocker !== '') {
                    $report['blockers'][] = $blocker;
                }
            }
        }
        if ($version !== '' && $report['source'] !== '' && !array_filter($report['sources'][$report['source']]['counts'])) {
            /* translators: %s: a plugin name. */
            $report['blockers'][] = sprintf(__('%s has nothing to bring across.', 'waas-client'), $report['sources'][$report['source']]['name']);
        }
        $report['ready'] = !$report['blockers'];

        return $report;
    }

    /**
     * Runs the switch, or with $dry_run, only says what it would do.
     *
     * @return array|WP_Error The report.
     */
    public function run(bool $dry_run = false, string $from = '')
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('waas_import_unauthorized', __('Only an administrator can run this switch.', 'waas-client'), ['status' => 403]);
        }
        $from = sanitize_key($from);
        $preflight = $this->preflight($from);
        $report = [
            'dry_run' => $dry_run,
            'preflight' => $preflight,
            'compare' => [],
            'skipped' => 0,
            'fixed' => [],
            'emails_blocked' => 0,
            'lost' => $preflight['source'] !== '' ? $this->lost($preflight['source']) : [],
            'notes' => [],
        ];
        if (!$preflight['ready']) {
            if (!$dry_run) {
                return new WP_Error('waas_import_not_ready', implode(' ', $preflight['blockers']), ['status' => 409, 'preflight' => $preflight]);
            }
            $report['notes'][] = __('Nothing can be switched until the items under "blockers" are fixed.', 'waas-client');
            return $report;
        }
        $source = $preflight['source'];
        $counts = $preflight['sources'][$source]['counts'];
        if ($dry_run) {
            $report['compare'] = $this->comparison($counts, $preflight['target_counts'], null);
            $report['notes'][] = __('Dry run: nothing was changed.', 'waas-client');
            return $report;
        }
        if (!$this->lock()) {
            return new WP_Error('waas_import_busy', __('This switch is already running on this website. Try again in a few minutes.', 'waas-client'), ['status' => 409]);
        }
        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            wp_raise_memory_limit('admin');
            add_filter('pre_wp_mail', [$this, 'hold_mail'], PHP_INT_MAX);
            // Recorded before the import starts, so undo can still find what a run that dies half way added.
            $state = ['source' => $source, 'at' => gmdate('Y-m-d H:i:s') . ' UTC', 'before' => $preflight['target_counts'], 'done' => false];
            update_option($this->option(), $state, false);
            try {
                $added = $this->import($source, $preflight['target_counts']);
            } catch (\Throwable $error) {
                return new WP_Error('waas_import_failed', sprintf(
                    /* translators: 1: a plugin name, 2: an error message. */
                    __('%1$s\'s importer stopped: %2$s Undo takes out what it added so far.', 'waas-client'),
                    $this->target_name(),
                    $error->getMessage()
                ), ['status' => 500]);
            } finally {
                remove_filter('pre_wp_mail', [$this, 'hold_mail'], PHP_INT_MAX);
            }
            $after = $this->target_counts();
            $state = array_merge($state, $added, ['after' => $after, 'done' => true]);
            update_option($this->option(), $state, false);

            $report['skipped'] = (int) ($added['skipped'] ?? 0);
            $report['fixed'] = array_values((array) ($added['fixed'] ?? []));
            $report['compare'] = $this->comparison($counts, $preflight['target_counts'], $after);
            $report['emails_blocked'] = $this->blocked;
            $short = array_filter($report['compare'], fn ($row) => $row['missing'] > 0);
            $report['notes'][] = $short
                /* translators: %s: a list of items, such as tickets. */
                ? sprintf(__('Fewer came across than the source holds for: %s. See "missing".', 'waas-client'), implode(', ', array_column($short, 'item')))
                : __('Everything the source holds for these items came across.', 'waas-client');
            $report['notes'][] = __('The source plugin and its data were not changed. Turn it off once you have checked the result.', 'waas-client');

            return $report;
        } finally {
            $this->unlock();
        }
    }

    /**
     * Removes what the switch added, or with $dry_run, says what it would remove.
     *
     * @return array|WP_Error
     */
    public function undo(bool $dry_run = false)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('waas_import_unauthorized', __('Only an administrator can undo this switch.', 'waas-client'), ['status' => 403]);
        }
        if ($this->target_version() === '') {
            /* translators: %s: a plugin name. */
            return new WP_Error('waas_import_not_ready', sprintf(__('%s isn\'t active, so the switch can\'t be undone. Turn it on first.', 'waas-client'), $this->target_name()), ['status' => 409]);
        }
        $state = $this->state();
        if (empty($state['source'])) {
            return ['dry_run' => $dry_run, 'undo' => true, 'removed' => [], 'notes' => [__('The switch hasn\'t run here, so there is nothing to undo.', 'waas-client')]];
        }
        if (!$dry_run && !$this->lock()) {
            return new WP_Error('waas_import_busy', __('This switch is already running on this website. Try again in a few minutes.', 'waas-client'), ['status' => 409]);
        }
        try {
            $removed = $this->remove($state, $dry_run);
            if (!$dry_run) {
                delete_option($this->option());
            }

            return [
                'dry_run' => $dry_run,
                'undo' => true,
                'removed' => $removed,
                'notes' => [__('Only what the switch added was removed. The source plugin and its data were never changed.', 'waas-client')],
            ];
        } finally {
            if (!$dry_run) {
                $this->unlock();
            }
        }
    }

    /** What the switch recorded when it ran. */
    public function state(): array
    {
        $state = get_option($this->option());

        return is_array($state) ? $state : [];
    }

    /** @internal Holds back any email during the run. */
    public function hold_mail($return)
    {
        $this->blocked++;

        return false;
    }

    private function comparison(array $source, array $before, ?array $after): array
    {
        $rows = [];
        foreach ($this->compare_keys() as $from => $to) {
            $in_source = (int) ($source[$from] ?? 0);
            $row = ['item' => $from, 'source' => $in_source, 'before' => (int) ($before[$to] ?? 0)];
            if ($after !== null) {
                $row['after'] = (int) ($after[$to] ?? 0);
                $row['added'] = $row['after'] - $row['before'];
                $row['missing'] = max(0, $in_source - $row['added']);
            } else {
                $row['missing'] = 0;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    protected static function plugin_active(string $file): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active($file);
    }

    protected static function table_exists(string $table): bool
    {
        global $wpdb;
        $name = $wpdb->prefix . $table;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name))) === $name;
    }

    /** Rows in a table of this site (prefix added), 0 when it doesn't exist. */
    protected static function count(string $table, string $where = '1=1', array $args = []): int
    {
        global $wpdb;
        if (!self::table_exists($table)) {
            return 0;
        }
        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$where}";

        return (int) $wpdb->get_var($args ? $wpdb->prepare($sql, $args) : $sql); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    protected static function max_id(string $table, string $column = 'id'): int
    {
        global $wpdb;
        if (!self::table_exists($table)) {
            return 0;
        }

        return (int) $wpdb->get_var("SELECT MAX({$column}) FROM {$wpdb->prefix}{$table}"); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /** Deletes rows of a table whose $column is in $ids; returns how many (or would). */
    protected static function delete_in(string $table, string $column, array $ids, bool $dry_run, string $extra = ''): int
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !self::table_exists($table)) {
            return 0;
        }
        $total = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', $chunk);
            $where = "{$column} IN ({$in})" . ($extra !== '' ? " AND {$extra}" : '');
            $total += $dry_run
                ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$where}") // phpcs:ignore WordPress.DB.PreparedSQL
                : (int) $wpdb->query("DELETE FROM {$wpdb->prefix}{$table} WHERE {$where}"); // phpcs:ignore WordPress.DB.PreparedSQL
        }

        return $total;
    }

    /** Deletes rows of a table with $after < id <= $upto; returns how many (or would). */
    protected static function delete_range(string $table, int $after, int $upto, bool $dry_run, string $column = 'id', string $extra = ''): int
    {
        global $wpdb;
        if ($upto <= $after || !self::table_exists($table)) {
            return 0;
        }
        $where = $wpdb->prepare("{$column} > %d AND {$column} <= %d", $after, $upto) . ($extra !== '' ? " AND {$extra}" : ''); // phpcs:ignore WordPress.DB.PreparedSQL

        return $dry_run
            ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$where}") // phpcs:ignore WordPress.DB.PreparedSQL
            : (int) $wpdb->query("DELETE FROM {$wpdb->prefix}{$table} WHERE {$where}"); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /** One run at a time (the command and the ability together must not import twice). */
    private function lock(): bool
    {
        if (add_option($this->lock_option(), time(), '', false)) {
            return true;
        }
        // A run that died leaves its lock; it lapses after 30 minutes.
        if ((int) get_option($this->lock_option()) < time() - 30 * MINUTE_IN_SECONDS) {
            delete_option($this->lock_option());
            return add_option($this->lock_option(), time(), '', false);
        }

        return false;
    }

    private function unlock(): void
    {
        delete_option($this->lock_option());
    }
}

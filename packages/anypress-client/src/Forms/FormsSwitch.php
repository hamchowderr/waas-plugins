<?php

namespace AnyPressClient\Forms;

use AnyPressClient\Core\FluentPlugin;
use AnyPressClient\Forms\Migrators\Cf7Migrator;
use AnyPressClient\Forms\Migrators\GravityMigrator;
use AnyPressClient\Forms\Migrators\NinjaMigrator;
use AnyPressClient\Forms\Migrators\WpFormsMigrator;
use WP_Error;

/**
 * The forms switch: moves a website's Contact Form 7, WPForms (Lite), Ninja Forms and Gravity Forms forms to Fluent Forms.
 *
 * 1. Converts each form, and its entries, with Fluent Forms' own importer (Fluent Forms → Tools → Migrator), through
 *    the subclasses in Migrators/ that put its known mistakes right.
 * 2. Reports each form's dropped fields, by name and type, and anything else that didn't come across.
 * 3. Swaps the old shortcodes and blocks for the Fluent Forms ones in posts, pages, templates, template parts,
 *    synced patterns and widgets. Each changed post keeps a revision, and the old content and the id map are kept
 *    in an option, so undo() puts the old shortcodes back.
 *
 * Running it again changes nothing that was already done: converted forms are skipped (Fluent Forms' importer
 * would update the same form anyway, not add a second), entries are only brought into a Fluent Forms form that has
 * none, and pages already switched have nothing left to swap. It never deletes, deactivates or changes the old
 * plugins or their forms, so the old forms keep working until the owner turns the old plugin off.
 */
class FormsSwitch
{
    /** What was converted and switched (the id map, and each changed post's original content). Not autoloaded. */
    public const OPTION = 'anypress_client_forms_switch';
    /** The Fluent Forms ids with conditional confirmations (read when such a form is sent; see ConditionalConfirmations). */
    public const CONDITIONAL_OPTION = 'anypress_client_forms_conditional';
    private const LOCK = 'anypress_client_forms_switch_lock';
    /** Stands in for a Fluent Forms id not made yet, when a dry run counts what a switch would change. */
    private const NOT_YET = 999999999;

    private const SOURCES = [
        'contactform7' => ['label' => 'Contact Form 7', 'parent' => 'FluentForm\App\Services\Migrator\Classes\ContactForm7Migrator', 'class' => Cf7Migrator::class],
        'wpforms' => ['label' => 'WPForms', 'parent' => 'FluentForm\App\Services\Migrator\Classes\WpFormsMigrator', 'class' => WpFormsMigrator::class],
        'ninja_forms' => ['label' => 'Ninja Forms', 'parent' => 'FluentForm\App\Services\Migrator\Classes\NinjaFormsMigrator', 'class' => NinjaMigrator::class],
        'gravityform' => ['label' => 'Gravity Forms', 'parent' => 'FluentForm\App\Services\Migrator\Classes\GravityFormsMigrator', 'class' => GravityMigrator::class],
    ];

    /**
     * Converts the forms and switches the pages over, or with $dry_run, only says what it would do.
     *
     * @return array|WP_Error The report.
     */
    public function run(bool $dry_run = false)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('anypress_forms_unauthorized', __('Only an administrator can switch the forms.', 'anypress-client'), ['status' => 403]);
        }
        $off = FluentPlugin::turn_on('fluentform/fluentform.php', 'Fluent Forms', self::fluent_active());
        if ($off) {
            return $off;
        }
        if (!self::fluent_active()) {
            return new WP_Error('anypress_forms_not_initialized', __('Fluent Forms isn\'t active on this website. Turn it on first; the forms are converted into it.', 'anypress-client'), ['status' => 409]);
        }
        if (!$dry_run && !$this->lock()) {
            return new WP_Error('anypress_forms_data_unavailable', __('A forms switch is already running on this website. Try again in a few minutes.', 'anypress-client'), ['status' => 409]);
        }

        try {
            $state = self::state();
            $report = [
                'dry_run' => $dry_run,
                'fluent_forms_pro' => self::pro(),
                'forms' => [],
                'pages' => [],
                'widgets' => [],
                'not_switched' => [],
                'notes' => [],
            ];
            $sources = $this->sources();
            if (!$sources) {
                $report['notes'][] = __('Contact Form 7, WPForms, Ninja Forms and Gravity Forms aren\'t active here, so there are no forms to switch. (Their forms can only be read while the plugin is active.)', 'anypress-client');
            }

            foreach ($sources as $type => $source) {
                foreach ($this->migrator($type)->sourceForms() as $item) {
                    $result = $this->convert($type, $item, $state, $dry_run);
                    $report['forms'][] = $result;
                    if (!$dry_run) {
                        self::save($state);
                    }
                }
            }

            $map = $this->map($state, $dry_run ? $report['forms'] : []);
            $switcher = new PageSwitcher($map);
            FormPlacements::switch_posts($switcher, $state, $report, $dry_run);
            FormPlacements::switch_widgets($switcher, $state, $report, $dry_run);
            FormPlacements::page_builders($report);

            if (!$dry_run) {
                self::save($state);
                FluentFormsStore::remember_conditional($state);
            }
            if (!self::pro()) {
                $report['notes'][] = __('Fluent Forms Pro isn\'t active: phone, file upload, slider, multi-step and repeater fields can\'t be brought in without it.', 'anypress-client');
            }
            $report['notes'][] = __('The old form plugins and their forms are left as they were. Once you\'re happy with the Fluent Forms copies, you can turn the old plugins off.', 'anypress-client');

            return $report;
        } finally {
            if (!$dry_run) {
                $this->unlock();
            }
        }
    }

    /**
     * Puts back the old shortcodes and blocks in every post and widget the switch changed. The Fluent Forms forms
     * and their entries stay, so switching again later uses the same forms.
     *
     * @return array|WP_Error
     */
    public function undo(bool $dry_run = false)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('anypress_forms_unauthorized', __('Only an administrator can undo the forms switch.', 'anypress-client'), ['status' => 403]);
        }
        if (!$dry_run && !$this->lock()) {
            return new WP_Error('anypress_forms_data_unavailable', __('A forms switch is already running on this website. Try again in a few minutes.', 'anypress-client'), ['status' => 409]);
        }

        try {
            $state = self::state();
            $report = ['dry_run' => $dry_run, 'undo' => true, 'pages' => [], 'widgets' => [], 'notes' => []];

            FormPlacements::restore_posts($state, $report, $dry_run);
            FormPlacements::restore_widgets($state, $report, $dry_run);

            if (!$dry_run) {
                self::save($state);
            }
            if (!$report['pages'] && !$report['widgets']) {
                $report['notes'][] = __('Nothing to put back: no page or widget was switched.', 'anypress-client');
            }
            $report['notes'][] = __('The Fluent Forms forms and their entries are kept, and the old form plugins were never changed.', 'anypress-client');

            return $report;
        } finally {
            if (!$dry_run) {
                $this->unlock();
            }
        }
    }

    /** Converts one source form (or, in a dry run, works out what converting it would drop) and reports on it. */
    private function convert(string $type, $item, array &$state, bool $dry_run): array
    {
        $migrator = $this->migrator($type);
        $id = $migrator->sourceId($item);
        $key = $type . ':' . $id;
        $row = [
            'source' => self::SOURCES[$type]['label'],
            'source_type' => $type,
            'source_id' => is_numeric($id) ? (int) $id : (string) $id,
            'title' => $migrator->sourceName($item),
            'fluent_id' => null,
            'status' => '',
            'dropped_fields' => [],
            'lost' => [],
            'notes' => [],
            'entries' => ['found' => FormEntries::count($type, $id), 'imported' => 0, 'note' => ''],
        ];

        // Already done by an earlier run (and the Fluent Forms copy is still there): report it again, change nothing.
        $done = $state['forms'][$key] ?? null;
        if ($done && FluentFormsStore::exists((int) $done['fluent_id'])) {
            return array_merge($row, $done['report'], ['fluent_id' => (int) $done['fluent_id'], 'status' => 'already converted']);
        }

        if ($dry_run) {
            $fields = $migrator->getFields($item);
            $row['status'] = 'will be converted';
            $row['fluent_id'] = FluentFormsStore::id_for($type, $id);
            $row['dropped_fields'] = DroppedFields::find($type, FormLosses::source_fields($type, $migrator, $item), is_array($fields) ? $fields : [], $migrator->unsupported(), self::pro());
            if ($type === 'contactform7') {
                $migrator->preview($item);
            }
            [$row['lost'], $row['notes']] = FormLosses::find($type, $migrator, $item, $row['dropped_fields']);
            $row['entries']['note'] = FormEntries::none($type, $row['entries']['found']) ?? FormEntries::note($type, $item, $row['entries']['found'], null);
            return $row;
        }

        $answer = ImporterCall::capture(fn () => $migrator->import_forms([(string) $id]));
        $fluent_id = FluentFormsStore::id_for($type, $id);
        if (empty($answer['status']) || !$fluent_id) {
            $row['status'] = 'failed';
            $row['lost'][] = sprintf(
                /* translators: %s: the importer's message */
                __('Fluent Forms couldn\'t convert this form: %s', 'anypress-client'),
                wp_strip_all_tags((string) ($answer['message'] ?? $answer['data']['message'] ?? __('no reason given', 'anypress-client')))
            );
            return $row;
        }
        $row['fluent_id'] = $fluent_id;
        $row['status'] = 'converted';
        FluentFormsStore::remove_junk_notifications($fluent_id);
        if ($type === 'wpforms') {
            FluentFormsStore::remove_unconditional_confirmations($fluent_id);
        }

        $unsupported = array_values(array_unique(array_merge($migrator->unsupported(), array_map('strval', (array) ($answer['unsupported_fields'] ?? [])))));
        $row['dropped_fields'] = DroppedFields::find($type, FormLosses::source_fields($type, $migrator, $item), FluentFormsStore::fields($fluent_id), $unsupported, self::pro());
        $row['entries'] = FormEntries::import($type, $migrator, $item, $fluent_id, $row['entries']['found']);
        [$row['lost'], $row['notes']] = FormLosses::find($type, $migrator, $item, $row['dropped_fields']);

        $state['forms'][$key] = [
            'source' => $type,
            'source_id' => $row['source_id'],
            'fluent_id' => $fluent_id,
            'hash' => $type === 'contactform7' ? (string) get_post_meta((int) $id, '_hash', true) : '',
            'title' => $row['title'],
            'converted_at' => gmdate('c'),
            'report' => ['title' => $row['title'], 'dropped_fields' => $row['dropped_fields'], 'lost' => $row['lost'], 'notes' => $row['notes'], 'entries' => $row['entries']],
        ];

        return $row;
    }

    /** The converted forms, for PageSwitcher. In a dry run, forms still to convert count with a stand-in id. */
    private function map(array $state, array $planned): array
    {
        $map = [];
        foreach ($state['forms'] as $form) {
            if (FluentFormsStore::exists((int) $form['fluent_id'])) {
                $map[$form['source']][] = ['id' => $form['source_id'], 'hash' => $form['hash'] ?? '', 'title' => $form['title'] ?? '', 'fluent' => (int) $form['fluent_id']];
            }
        }
        foreach ($planned as $row) {
            if ($row['status'] === 'will be converted') {
                $map[$row['source_type']][] = [
                    'id' => $row['source_id'],
                    'hash' => $row['source_type'] === 'contactform7' ? (string) get_post_meta((int) $row['source_id'], '_hash', true) : '',
                    'title' => $row['title'],
                    'fluent' => $row['fluent_id'] ?: self::NOT_YET,
                ];
            }
        }

        return $map;
    }

    /** The source plugins that are active (Fluent Forms' importer reads each through the plugin itself). */
    private function sources(): array
    {
        $active = [];
        foreach (self::SOURCES as $type => $source) {
            if (class_exists($source['parent']) && $this->migrator($type)->exist()) {
                $active[$type] = $source;
            }
        }

        return $active;
    }

    private function migrator(string $type)
    {
        $class = self::SOURCES[$type]['class'];

        return new $class();
    }

    public static function fluent_active(): bool
    {
        return defined('FLUENTFORM') && function_exists('wpFluent') && class_exists(self::SOURCES['contactform7']['parent']);
    }

    public static function pro(): bool
    {
        return defined('FLUENTFORMPRO');
    }

    public static function state(): array
    {
        $state = get_option(self::OPTION);
        $state = is_array($state) ? $state : [];

        return [
            'forms' => is_array($state['forms'] ?? null) ? $state['forms'] : [],
            'pages' => is_array($state['pages'] ?? null) ? $state['pages'] : [],
            'widgets' => is_array($state['widgets'] ?? null) ? $state['widgets'] : [],
        ];
    }

    private static function save(array $state): void
    {
        update_option(self::OPTION, $state, false);
    }

    /** One run at a time (a second click, or the command and the ability together, must not convert twice). */
    private function lock(): bool
    {
        if (add_option(self::LOCK, time(), '', false)) {
            return true;
        }
        // A run that died (a fatal error, a killed process) leaves its lock; it lapses after 15 minutes.
        if ((int) get_option(self::LOCK) < time() - 15 * MINUTE_IN_SECONDS) {
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

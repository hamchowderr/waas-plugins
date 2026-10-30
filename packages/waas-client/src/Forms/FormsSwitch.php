<?php

namespace WaaSClient\Forms;

use WaaSClient\Forms\Migrators\Cf7Migrator;
use WaaSClient\Forms\Migrators\NinjaMigrator;
use WaaSClient\Forms\Migrators\WpFormsMigrator;
use WP_Error;

/**
 * The forms switch: moves a website's Contact Form 7, WPForms (Lite) and Ninja Forms forms to Fluent Forms.
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
    public const OPTION = 'waas_client_forms_switch';
    /** The Fluent Forms ids with conditional confirmations (read when such a form is sent; see ConditionalConfirmations). */
    public const CONDITIONAL_OPTION = 'waas_client_forms_conditional';
    private const LOCK = 'waas_client_forms_switch_lock';
    /** Fluent Forms' own map of imported forms: Fluent id => imported_form_id, form_type. */
    private const FLUENT_MAP = '__ff_imorted_forms_map';
    /** Stands in for a Fluent Forms id not made yet, when a dry run counts what a switch would change. */
    private const NOT_YET = 999999999;

    private const SOURCES = [
        'contactform7' => ['label' => 'Contact Form 7', 'parent' => 'FluentForm\App\Services\Migrator\Classes\ContactForm7Migrator', 'class' => Cf7Migrator::class],
        'wpforms' => ['label' => 'WPForms', 'parent' => 'FluentForm\App\Services\Migrator\Classes\WpFormsMigrator', 'class' => WpFormsMigrator::class],
        'ninja_forms' => ['label' => 'Ninja Forms', 'parent' => 'FluentForm\App\Services\Migrator\Classes\NinjaFormsMigrator', 'class' => NinjaMigrator::class],
    ];

    /** Post types that are the form plugins' own records, or can't hold a form. */
    private const SKIP_POST_TYPES = [
        'revision', 'attachment', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request',
        'wpcf7_contact_form', 'flamingo_contact', 'flamingo_inbound', 'flamingo_outbound', 'wpforms', 'wpforms-template', 'wpforms_log', 'nf_sub',
    ];

    /** Widget options whose text can hold a shortcode or block, and the instance fields that do. */
    private const WIDGETS = ['widget_text' => ['text'], 'widget_custom_html' => ['content'], 'widget_block' => ['content']];

    /**
     * Converts the forms and switches the pages over, or with $dry_run, only says what it would do.
     *
     * @return array|WP_Error The report.
     */
    public function run(bool $dry_run = false)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('waas_forms_unauthorized', __('Only an administrator can switch the forms.', 'waas-client'), ['status' => 403]);
        }
        if (!self::fluent_active()) {
            return new WP_Error('waas_forms_not_initialized', __('Fluent Forms isn\'t active on this website. Turn it on first; the forms are converted into it.', 'waas-client'), ['status' => 409]);
        }
        if (!$dry_run && !$this->lock()) {
            return new WP_Error('waas_forms_data_unavailable', __('A forms switch is already running on this website. Try again in a few minutes.', 'waas-client'), ['status' => 409]);
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
                $report['notes'][] = __('Contact Form 7, WPForms and Ninja Forms aren\'t active here, so there are no forms to switch. (Their forms can only be read while the plugin is active.)', 'waas-client');
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
            $this->switch_posts($switcher, $state, $report, $dry_run);
            $this->switch_widgets($switcher, $state, $report, $dry_run);
            $this->page_builders($report);

            if (!$dry_run) {
                self::save($state);
                $this->remember_conditional($state);
            }
            if (!self::pro()) {
                $report['notes'][] = __('Fluent Forms Pro isn\'t active: phone, file upload, slider, multi-step and repeater fields can\'t be brought in without it.', 'waas-client');
            }
            $report['notes'][] = __('The old form plugins and their forms are left as they were. Once you\'re happy with the Fluent Forms copies, you can turn the old plugins off.', 'waas-client');

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
            return new WP_Error('waas_forms_unauthorized', __('Only an administrator can undo the forms switch.', 'waas-client'), ['status' => 403]);
        }
        if (!$dry_run && !$this->lock()) {
            return new WP_Error('waas_forms_data_unavailable', __('A forms switch is already running on this website. Try again in a few minutes.', 'waas-client'), ['status' => 409]);
        }

        try {
            $state = self::state();
            $report = ['dry_run' => $dry_run, 'undo' => true, 'pages' => [], 'widgets' => [], 'notes' => []];

            foreach ($state['pages'] as $id => $page) {
                $post = get_post((int) $id);
                if (!$post) {
                    unset($state['pages'][$id]);
                    continue;
                }
                $content = (string) $post->post_content;
                // Untouched since the switch: the original exactly. Edited since: only the swapped forms go back.
                $old = md5($content) === ($page['written'] ?? '')
                    ? (string) $page['original']
                    : PageSwitcher::revert($content, $page['replacements'] ?? []);
                $report['pages'][] = self::post_row($post, count($page['replacements'] ?? []), $dry_run ? 'will be put back' : 'put back');
                if ($dry_run || $old === $content) {
                    continue;
                }
                $saved = $this->save_post($post, $old);
                if (is_wp_error($saved)) {
                    $report['pages'][count($report['pages']) - 1]['status'] = 'failed: ' . $saved->get_error_message();
                    continue;
                }
                unset($state['pages'][$id]);
            }

            foreach ($state['widgets'] as $option => $instances) {
                $value = get_option($option);
                if (!is_array($value)) {
                    unset($state['widgets'][$option]);
                    continue;
                }
                foreach ($instances as $instance => $fields) {
                    foreach ($fields as $field => $change) {
                        $current = $value[$instance][$field] ?? null;
                        if (!is_string($current)) {
                            continue;
                        }
                        $value[$instance][$field] = md5($current) === ($change['written'] ?? '')
                            ? (string) $change['original']
                            : PageSwitcher::revert($current, $change['replacements'] ?? []);
                        $report['widgets'][] = ['widget' => $option . '-' . $instance, 'forms' => count($change['replacements'] ?? []), 'status' => $dry_run ? 'will be put back' : 'put back'];
                    }
                }
                if (!$dry_run) {
                    update_option($option, $value);
                    unset($state['widgets'][$option]);
                }
            }

            if (!$dry_run) {
                self::save($state);
            }
            if (!$report['pages'] && !$report['widgets']) {
                $report['notes'][] = __('Nothing to put back: no page or widget was switched.', 'waas-client');
            }
            $report['notes'][] = __('The Fluent Forms forms and their entries are kept, and the old form plugins were never changed.', 'waas-client');

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
            'entries' => ['found' => $this->entry_count($type, $id), 'imported' => 0, 'note' => ''],
        ];

        // Already done by an earlier run (and the Fluent Forms copy is still there): report it again, change nothing.
        $done = $state['forms'][$key] ?? null;
        if ($done && $this->fluent_form_exists((int) $done['fluent_id'])) {
            return array_merge($row, $done['report'], ['fluent_id' => (int) $done['fluent_id'], 'status' => 'already converted']);
        }

        if ($dry_run) {
            $fields = $migrator->getFields($item);
            $row['status'] = 'will be converted';
            $row['fluent_id'] = $this->fluent_id_for($type, $id);
            $row['dropped_fields'] = DroppedFields::find($type, $this->source_fields($type, $migrator, $item), is_array($fields) ? $fields : [], $migrator->unsupported(), self::pro());
            if ($type === 'contactform7') {
                $migrator->preview($item);
            }
            [$row['lost'], $row['notes']] = $this->losses($type, $migrator, $item, $row['dropped_fields']);
            $row['entries']['note'] = $this->no_entries($type, $row['entries']['found']) ?? $this->entries_note($type, $item, $row['entries']['found'], null);
            return $row;
        }

        $answer = $this->capture(fn () => $migrator->import_forms([(string) $id]));
        $fluent_id = $this->fluent_id_for($type, $id);
        if (empty($answer['status']) || !$fluent_id) {
            $row['status'] = 'failed';
            $row['lost'][] = sprintf(
                /* translators: %s: the importer's message */
                __('Fluent Forms couldn\'t convert this form: %s', 'waas-client'),
                wp_strip_all_tags((string) ($answer['message'] ?? $answer['data']['message'] ?? __('no reason given', 'waas-client')))
            );
            return $row;
        }
        $row['fluent_id'] = $fluent_id;
        $row['status'] = 'converted';
        $this->remove_junk_notifications($fluent_id);
        if ($type === 'wpforms') {
            $this->remove_unconditional_confirmations($fluent_id);
        }

        $unsupported = array_values(array_unique(array_merge($migrator->unsupported(), array_map('strval', (array) ($answer['unsupported_fields'] ?? [])))));
        $row['dropped_fields'] = DroppedFields::find($type, $this->source_fields($type, $migrator, $item), $this->fluent_fields($fluent_id), $unsupported, self::pro());
        $row['entries'] = $this->import_entries($type, $migrator, $item, $fluent_id, $row['entries']['found']);
        [$row['lost'], $row['notes']] = $this->losses($type, $migrator, $item, $row['dropped_fields']);

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

    /** Brings in the form's entries when the Fluent Forms copy has none (so later submissions are never replaced). */
    private function import_entries(string $type, $migrator, $item, int $fluent_id, int $found): array
    {
        $id = $migrator->sourceId($item);
        $entries = ['found' => $found, 'imported' => 0, 'note' => ''];
        $none = $this->no_entries($type, $found);
        if ($none !== null) {
            $entries['note'] = $none;
            return $entries;
        }
        $existing = $this->submission_count($fluent_id);
        if ($existing > 0) {
            $entries['imported'] = $existing;
            $entries['note'] = __('The Fluent Forms form already has entries, so none were brought in again.', 'waas-client');
            return $entries;
        }

        $answer = $this->capture(fn () => $migrator->insertEntries($fluent_id, (string) $id));
        $entries['imported'] = $this->submission_count($fluent_id);
        if (empty($answer['status'])) {
            $entries['note'] = wp_strip_all_tags((string) ($answer['message'] ?? $answer['data']['message'] ?? ''));
            return $entries;
        }
        if ($type === 'contactform7') {
            $this->restore_cf7_details($fluent_id, $migrator->entryDetails);
        }
        $entries['note'] = $this->entries_note($type, $item, $found, $entries['imported']);

        return $entries;
    }

    /** Why a form has no entries to bring in, or null when it has some. */
    private function no_entries(string $type, int $found): ?string
    {
        if ($type === 'contactform7' && !class_exists('Flamingo_Inbound_Message')) {
            return __('No entries: Contact Form 7 keeps none without Flamingo.', 'waas-client');
        }
        if ($type === 'wpforms' && !(function_exists('wpforms') && wpforms()->is_pro())) {
            return __('No entries: Fluent Forms can only read WPForms entries from WPForms Pro.', 'waas-client');
        }

        return $found === 0 ? __('No entries to bring in.', 'waas-client') : null;
    }

    /** The IP, browser and page each CF7 message was sent from (Flamingo keeps them; the importer leaves them empty). */
    private function restore_cf7_details(int $fluent_id, array $details): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fluentform_submissions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own table, read once after the import.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE form_id = %d ORDER BY id ASC", $fluent_id));
        if (count($ids) !== count($details)) {
            return;
        }
        foreach ($ids as $i => $submission) {
            $wpdb->update($table, [
                'ip' => substr(sanitize_text_field($details[$i]['ip']), 0, 45),
                'browser' => substr(sanitize_text_field($details[$i]['browser']), 0, 45),
                'source_url' => esc_url_raw($details[$i]['source_url']),
            ], ['id' => (int) $submission]);
        }
    }

    private function entries_note(string $type, $item, int $found, ?int $imported): string
    {
        $cap = (int) apply_filters('fluentform/entry_migration_max_limit', 1000, $type, $found, 0);
        if ($type !== 'contactform7' && $cap > 0 && $found > $cap) {
            return sprintf(
                /* translators: 1: the limit, 2: how many entries there are */
                __('Fluent Forms brings in only the first %1$s of %2$s entries per form.', 'waas-client'),
                number_format_i18n($cap),
                number_format_i18n($found)
            );
        }
        if ($imported !== null && $imported < $found) {
            return sprintf(
                /* translators: 1: entries brought in, 2: entries found */
                __('Only %1$s of %2$s entries came across.', 'waas-client'),
                number_format_i18n($imported),
                number_format_i18n($found)
            );
        }
        if ($type === 'contactform7') {
            $files = class_exists('WPCF7_ContactForm') && ($form = \WPCF7_ContactForm::get_instance($item['ID'])) && $form->scan_form_tags(['basetype' => 'file']);

            return $files
                ? __('Entries keep the date each was sent, its IP address and page. Files sent with the form aren\'t in them: Flamingo keeps only the file names.', 'waas-client')
                : __('Entries keep the date each was sent, its IP address and page.', 'waas-client');
        }

        return __('Entries keep the date each was sent and their order. The visitor\'s IP address and browser aren\'t carried over.', 'waas-client');
    }

    /**
     * What the form had that its Fluent Forms copy doesn't, besides the dropped fields, and other things to know.
     *
     * @return array{0: string[], 1: string[]} [lost, notes]
     */
    private function losses(string $type, $migrator, $item, array $dropped): array
    {
        $lost = [];
        $notes = [];
        if ($type === 'contactform7') {
            $contact_form = class_exists('WPCF7_ContactForm') ? \WPCF7_ContactForm::get_instance($item['ID']) : null;
            $dropped_names = array_filter(array_column($dropped, 'name'));
            $mentioned = [];
            foreach (['mail', 'mail_2'] as $prop) {
                $mail = $contact_form ? $contact_form->prop($prop) : null;
                if (!is_array($mail) || ($prop === 'mail_2' && empty($mail['active']))) {
                    continue;
                }
                $text = implode(' ', array_map('strval', array_intersect_key($mail, array_flip(['subject', 'sender', 'recipient', 'body', 'additional_headers']))));
                foreach ($dropped_names as $name) {
                    if (preg_match('/\[(?:_raw_)?' . preg_quote($name, '/') . '\]/', $text)) {
                        $mentioned[] = '[' . $name . ']';
                    }
                }
            }
            if ($mentioned) {
                $lost[] = sprintf(
                    /* translators: %s: mail tags such as [your-phone] */
                    __('The emails mention fields that weren\'t brought in, which now show nothing: %s', 'waas-client'),
                    implode(', ', array_unique($mentioned))
                );
            }
            if ($migrator->unknownTags) {
                $lost[] = sprintf(
                    /* translators: %s: mail tags such as [_post_author] */
                    __('Mail tags Fluent Forms has no equivalent for were left out of the emails: %s', 'waas-client'),
                    implode(', ', array_unique($migrator->unknownTags))
                );
            }
            foreach (array_unique($migrator->mailNotes) as $note) {
                $lost[] = $note;
            }
            // Messages changed from CF7's own wording, other than "sent" (which becomes the confirmation).
            $messages = $contact_form ? (array) $contact_form->prop('messages') : [];
            $defaults = function_exists('wpcf7_messages') ? wp_list_pluck(wpcf7_messages(), 'default') : [];
            $custom = [];
            foreach ($messages as $key => $message) {
                if ($key !== 'mail_sent_ok' && isset($defaults[$key]) && trim((string) $message) !== trim((string) $defaults[$key])) {
                    $custom[] = $key;
                }
            }
            if ($custom) {
                $lost[] = sprintf(
                    /* translators: %s: CF7 message names such as validation_error */
                    __('Your own wording for these Contact Form 7 messages isn\'t carried over (Fluent Forms uses its own): %s', 'waas-client'),
                    implode(', ', $custom)
                );
            }
        }
        if ($type === 'wpforms') {
            $rules = [];
            foreach ((array) ($item['fields'] ?? []) as $field) {
                if (!empty($field['conditional_logic'])) {
                    $rules[] = (string) ($field['label'] ?? ('#' . ($field['id'] ?? '')));
                }
            }
            if ($rules) {
                $lost[] = sprintf(
                    /* translators: %s: field labels */
                    __('Show/hide rules on these fields aren\'t carried over, so they always show: %s', 'waas-client'),
                    implode(', ', $rules)
                );
            }
            $confirmations = (array) ($item['settings']['confirmations'] ?? []);
            $conditional = array_filter($confirmations, fn ($c) => !empty($c['conditional_logic']));
            if ($conditional && !self::pro()) {
                $notes[] = __('Fluent Forms runs conditional confirmations (such as a redirect for one answer) only with Pro; WaaS Client runs them for this form while Pro is off.', 'waas-client');
            }
        }
        if ($dropped) {
            $notes[] = __('Entries keep the values of dropped fields, but the form no longer asks for them.', 'waas-client');
        }

        return [$lost, $notes];
    }

    /**
     * The source form's fields, each with the name Fluent Forms' importer gives it.
     *
     * @return array<int, array{name: string, label: string, type: string, layout?: bool}>
     */
    private function source_fields(string $type, $migrator, $item): array
    {
        $fields = [];
        if ($type === 'contactform7') {
            foreach ($migrator->tags($item['ID']) as $tag) {
                if ($tag->basetype === 'submit' || $tag->name === '') {
                    continue;
                }
                $fields[] = ['name' => (string) $tag->name, 'label' => (string) $tag->name, 'type' => (string) $tag->basetype];
            }
        }
        if ($type === 'wpforms') {
            foreach ((array) ($item['fields'] ?? []) as $field) {
                $field_type = (string) ($field['type'] ?? '');
                // The first and last page breaks only mark where the pages start and end.
                if ($field_type === 'pagebreak' && in_array($field['position'] ?? '', ['top', 'bottom'], true)) {
                    continue;
                }
                $fields[] = [
                    'name' => $field_type . '_' . ($field['id'] ?? ''),
                    'label' => (string) ($field['label'] ?? ''),
                    'type' => $field_type,
                    'layout' => in_array($field_type, ['divider', 'html', 'pagebreak', 'content', 'layout', 'entry-preview'], true),
                ];
            }
        }
        if ($type === 'ninja_forms') {
            foreach ((array) ($item['fields'] ?? []) as $field) {
                $field_type = (string) ($field['type'] ?? '');
                if ($field_type === 'submit') {
                    continue;
                }
                $name = (string) ($field['key'] ?? '');
                $fields[] = [
                    'name' => $name !== '' ? $name : str_replace('.', '_', (string) ($field['id'] ?? '')),
                    'label' => (string) ($field['label'] ?? ''),
                    'type' => $field_type,
                    'layout' => in_array($field_type, ['hr', 'html'], true),
                ];
            }
        }

        return $fields;
    }

    /** Swaps the forms in every post-like record (pages, posts, templates, template parts, synced patterns…). */
    private function switch_posts(PageSwitcher $switcher, array &$state, array &$report, bool $dry_run): void
    {
        global $wpdb;
        $likes = [];
        $values = [];
        foreach (PageSwitcher::NEEDLES as $needle) {
            $likes[] = 'post_content LIKE %s';
            $values[] = '%' . $wpdb->esc_like($needle) . '%';
        }
        $types = implode(', ', array_fill(0, count(self::SKIP_POST_TYPES), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ({$types}) AND post_status NOT IN ('trash', 'auto-draft', 'inherit') AND (" . implode(' OR ', $likes) . ') ORDER BY ID ASC';
        $ids = $wpdb->get_col($wpdb->prepare($sql, array_merge(self::SKIP_POST_TYPES, $values)));

        foreach ($ids as $id) {
            $post = get_post((int) $id);
            if (!$post) {
                continue;
            }
            $result = $switcher->rewrite((string) $post->post_content);
            foreach ($result['missing'] as $what) {
                $report['not_switched'][] = [
                    'where' => self::where($post),
                    'what' => $what,
                    'why' => __('That form wasn\'t converted (it may have been deleted, or its plugin is off).', 'waas-client'),
                ];
            }
            if (!$result['replacements']) {
                continue;
            }
            $count = count($result['replacements']);
            if ($dry_run) {
                $report['pages'][] = self::post_row($post, $count, 'will be switched');
                continue;
            }
            $saved = $this->save_post($post, $result['content']);
            if (is_wp_error($saved)) {
                $report['pages'][] = self::post_row($post, $count, 'failed: ' . $saved->get_error_message());
                continue;
            }
            $before = $state['pages'][$post->ID] ?? ['original' => (string) $post->post_content, 'replacements' => []];
            $state['pages'][$post->ID] = [
                'original' => $before['original'],
                'replacements' => array_merge($before['replacements'], self::slim($result['replacements'])),
                'written' => md5($result['content']),
            ];
            $report['pages'][] = self::post_row($post, $count, 'switched');
        }
    }

    /** Swaps the forms in Text, Custom HTML and block widgets. */
    private function switch_widgets(PageSwitcher $switcher, array &$state, array &$report, bool $dry_run): void
    {
        foreach (self::WIDGETS as $option => $keys) {
            $value = get_option($option);
            if (!is_array($value)) {
                continue;
            }
            $changed = false;
            foreach ($value as $instance => $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                foreach ($keys as $field) {
                    if (!isset($widget[$field]) || !is_string($widget[$field])) {
                        continue;
                    }
                    $result = $switcher->rewrite($widget[$field]);
                    foreach ($result['missing'] as $what) {
                        $report['not_switched'][] = ['where' => $option . '-' . $instance, 'what' => $what, 'why' => __('That form wasn\'t converted.', 'waas-client')];
                    }
                    if (!$result['replacements']) {
                        continue;
                    }
                    $report['widgets'][] = ['widget' => $option . '-' . $instance, 'forms' => count($result['replacements']), 'status' => $dry_run ? 'will be switched' : 'switched'];
                    if ($dry_run) {
                        continue;
                    }
                    $before = $state['widgets'][$option][$instance][$field] ?? ['original' => $widget[$field], 'replacements' => []];
                    $state['widgets'][$option][$instance][$field] = [
                        'original' => $before['original'],
                        'replacements' => array_merge($before['replacements'], self::slim($result['replacements'])),
                        'written' => md5($result['content']),
                    ];
                    $value[$instance][$field] = $result['content'];
                    $changed = true;
                }
            }
            if ($changed) {
                update_option($option, $value);
            }
        }
    }

    /** Page builders keep their own copy of a page (Elementor, Beaver Builder): those forms are listed, not changed. */
    private function page_builders(array &$report): void
    {
        global $wpdb;
        $likes = [];
        $values = ['_elementor_data', '_fl_builder_data'];
        foreach (PageSwitcher::NEEDLES as $needle) {
            $likes[] = 'meta_value LIKE %s';
            $values[] = '%' . $wpdb->esc_like($needle) . '%';
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
        $sql = "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND (" . implode(' OR ', $likes) . ')';
        foreach ($wpdb->get_col($wpdb->prepare($sql, $values)) as $id) {
            $post = get_post((int) $id);
            if ($post && $post->post_type !== 'revision') {
                $report['not_switched'][] = [
                    'where' => self::where($post),
                    'what' => __('A form inside a page builder\'s layout', 'waas-client'),
                    'why' => __('Page builders keep their own copy of the page: swap the form in the builder\'s editor.', 'waas-client'),
                ];
            }
        }
    }

    /** Saves new content for a post, keeping the old version as a revision first. */
    private function save_post(\WP_Post $post, string $content)
    {
        if (post_type_supports($post->post_type, 'revisions')) {
            wp_save_post_revision($post->ID);
        }

        return wp_update_post(['ID' => $post->ID, 'post_content' => wp_slash($content)], true);
    }

    /** The converted forms, for PageSwitcher. In a dry run, forms still to convert count with a stand-in id. */
    private function map(array $state, array $planned): array
    {
        $map = [];
        foreach ($state['forms'] as $form) {
            if ($this->fluent_form_exists((int) $form['fluent_id'])) {
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

    /** How many entries the source plugin holds for a form. */
    private function entry_count(string $type, $id): int
    {
        if ($type === 'contactform7') {
            $post = get_post((int) $id);
            if (!$post || !class_exists('Flamingo_Inbound_Message')) {
                return 0;
            }
            return method_exists('Flamingo_Inbound_Message', 'count')
                ? (int) \Flamingo_Inbound_Message::count(['channel' => $post->post_name])
                : count(\Flamingo_Inbound_Message::find(['channel' => $post->post_name, 'posts_per_page' => -1]));
        }
        if ($type === 'wpforms') {
            return function_exists('wpforms') && wpforms()->is_pro() && isset(wpforms()->entry)
                ? (int) wpforms()->entry->get_entries(['form_id' => (int) $id], true)
                : 0;
        }
        if ($type === 'ninja_forms' && function_exists('Ninja_Forms')) {
            $subs = Ninja_Forms()->form((int) $id)->get_subs();
            return is_array($subs) ? count($subs) : 0;
        }

        return 0;
    }

    /**
     * Runs a Fluent Forms importer method, which answers with wp_send_json() and then exits, and returns its answer.
     * For the call, WordPress treats the request as an admin-ajax one, so wp_send_json() ends in wp_die(), whose
     * ajax handler here throws instead of exiting.
     */
    private function capture(callable $call): array
    {
        $handler = fn () => function () {
            throw new SendJsonStop();
        };
        add_filter('wp_doing_ajax', '__return_true', PHP_INT_MAX);
        add_filter('wp_die_ajax_handler', $handler, PHP_INT_MAX);
        $level = ob_get_level();
        ob_start();
        try {
            $call();
        } catch (SendJsonStop $stop) {
            // The importer has answered.
        } finally {
            $output = '';
            while (ob_get_level() > $level) {
                $output = ob_get_clean() . $output;
            }
            remove_filter('wp_doing_ajax', '__return_true', PHP_INT_MAX);
            remove_filter('wp_die_ajax_handler', $handler, PHP_INT_MAX);
        }
        $answer = json_decode(trim($output), true);
        if (!is_array($answer)) {
            return ['status' => false, 'message' => wp_strip_all_tags($output)];
        }
        // wp_send_json_error() wraps its data: ['success' => false, 'data' => [...]].
        if (array_key_exists('success', $answer) && !array_key_exists('status', $answer)) {
            $answer['status'] = (bool) $answer['success'];
        }

        return $answer;
    }

    /** The Fluent Forms id Fluent Forms' importer made for a source form. */
    private function fluent_id_for(string $type, $id): ?int
    {
        $map = get_option(self::FLUENT_MAP);
        foreach (is_array($map) ? $map : [] as $fluent_id => $source) {
            if (($source['form_type'] ?? '') === $type && (string) ($source['imported_form_id'] ?? '') === (string) $id && $this->fluent_form_exists((int) $fluent_id)) {
                return (int) $fluent_id;
            }
        }

        return null;
    }

    private function fluent_form_exists(int $id): bool
    {
        global $wpdb;

        return $id > 0 && (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fluentform_forms WHERE id = %d", $id));
    }

    private function fluent_fields(int $id): array
    {
        global $wpdb;
        $json = $wpdb->get_var($wpdb->prepare("SELECT form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id = %d", $id));
        $fields = is_string($json) ? json_decode($json, true) : null;

        return is_array($fields) ? $fields : [];
    }

    private function submission_count(int $id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions WHERE form_id = %d", $id));
    }

    /**
     * Deletes the notification rows that aren't notifications. Fluent Forms' CF7 importer (and any earlier run of it
     * from Fluent Forms → Tools) writes one row per setting of a single notification ("Admin Notification Email", [], "").
     */
    private function remove_junk_notifications(int $id): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fluentform_form_meta';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, value FROM {$table} WHERE form_id = %d AND meta_key = 'notifications'", $id));
        foreach ($rows as $row) {
            $value = json_decode((string) $row->value, true);
            if (!is_array($value) || !isset($value['sendTo'])) {
                $wpdb->delete($table, ['id' => (int) $row->id]);
            }
        }
    }

    /**
     * Deletes extra confirmations that have no rules: the main confirmation already covers every submission, so they
     * never show. (An earlier import through Fluent Forms → Tools leaves the real default message here.)
     */
    private function remove_unconditional_confirmations(int $id): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fluentform_form_meta';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, value FROM {$table} WHERE form_id = %d AND meta_key = 'confirmations'", $id));
        foreach ($rows as $row) {
            $value = json_decode((string) $row->value, true);
            if (empty($value['conditionals']['status'])) {
                $wpdb->delete($table, ['id' => (int) $row->id]);
            }
        }
    }

    /** Keeps the short list of converted Fluent Forms forms that have conditional confirmations. */
    private function remember_conditional(array $state): void
    {
        global $wpdb;
        $ids = [];
        foreach ($state['forms'] as $form) {
            $values = $wpdb->get_col($wpdb->prepare(
                "SELECT value FROM {$wpdb->prefix}fluentform_form_meta WHERE form_id = %d AND meta_key = 'confirmations'",
                (int) $form['fluent_id']
            ));
            foreach ($values as $value) {
                $confirmation = json_decode((string) $value, true);
                if (!empty($confirmation['conditionals']['status'])) {
                    $ids[] = (int) $form['fluent_id'];
                    break;
                }
            }
        }
        update_option(self::CONDITIONAL_OPTION, array_values(array_unique($ids)), false);
    }

    private static function slim(array $replacements): array
    {
        return array_map(fn ($r) => ['from' => $r['from'], 'to' => $r['to']], $replacements);
    }

    private static function post_row(\WP_Post $post, int $forms, string $status): array
    {
        return ['id' => $post->ID, 'type' => $post->post_type, 'title' => html_entity_decode(get_the_title($post), ENT_QUOTES), 'forms' => $forms, 'status' => $status];
    }

    private static function where(\WP_Post $post): string
    {
        return sprintf('%s "%s" (%d)', $post->post_type, html_entity_decode(get_the_title($post), ENT_QUOTES), $post->ID);
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

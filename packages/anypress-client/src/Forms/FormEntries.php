<?php

namespace AnyPressClient\Forms;

/**
 * A source form's entries in the forms switch: how many the old plugin holds, bringing them into the Fluent Forms
 * copy, and the note that says what came across.
 */
final class FormEntries
{
    /** How many entries the source plugin holds for a form. */
    public static function count(string $type, $id): int
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
        if ($type === 'gravityform' && class_exists('GFAPI')) {
            return (int) \GFAPI::count_entries((int) $id);
        }
        if ($type === 'ninja_forms' && function_exists('Ninja_Forms')) {
            $subs = Ninja_Forms()->form((int) $id)->get_subs();
            return is_array($subs) ? count($subs) : 0;
        }

        return 0;
    }

    /** Brings in the form's entries when the Fluent Forms copy has none (so later submissions are never replaced). */
    public static function import(string $type, $migrator, $item, int $fluent_id, int $found): array
    {
        $id = $migrator->sourceId($item);
        $entries = ['found' => $found, 'imported' => 0, 'note' => ''];
        $none = self::none($type, $found);
        if ($none !== null) {
            $entries['note'] = $none;
            return $entries;
        }
        $existing = FluentFormsStore::submissions($fluent_id);
        if ($existing > 0) {
            $entries['imported'] = $existing;
            $entries['note'] = __('The Fluent Forms form already has entries, so none were brought in again.', 'anypress-client');
            return $entries;
        }

        $answer = ImporterCall::capture(fn () => $migrator->insertEntries($fluent_id, (string) $id));
        $entries['imported'] = FluentFormsStore::submissions($fluent_id);
        if (empty($answer['status'])) {
            $entries['note'] = wp_strip_all_tags((string) ($answer['message'] ?? $answer['data']['message'] ?? ''));
            return $entries;
        }
        if ($type === 'contactform7' || $type === 'gravityform') {
            self::restore_details($fluent_id, $migrator->entryDetails);
        }
        $entries['note'] = self::note($type, $item, $found, $entries['imported']);

        return $entries;
    }

    /** Why a form has no entries to bring in, or null when it has some. */
    public static function none(string $type, int $found): ?string
    {
        if ($type === 'contactform7' && !class_exists('Flamingo_Inbound_Message')) {
            return __('No entries: Contact Form 7 keeps none without Flamingo.', 'anypress-client');
        }
        if ($type === 'wpforms' && !(function_exists('wpforms') && wpforms()->is_pro())) {
            return __('No entries: Fluent Forms can only read WPForms entries from WPForms Pro.', 'anypress-client');
        }

        return $found === 0 ? __('No entries to bring in.', 'anypress-client') : null;
    }

    public static function note(string $type, $item, int $found, ?int $imported): string
    {
        $cap = (int) apply_filters('fluentform/entry_migration_max_limit', 1000, $type, $found, 0);
        if ($type !== 'contactform7' && $cap > 0 && $found > $cap) {
            return sprintf(
                /* translators: 1: the limit, 2: how many entries there are */
                __('Fluent Forms brings in only the first %1$s of %2$s entries per form.', 'anypress-client'),
                number_format_i18n($cap),
                number_format_i18n($found)
            );
        }
        if ($imported !== null && $imported < $found) {
            return sprintf(
                /* translators: 1: entries brought in, 2: entries found */
                __('Only %1$s of %2$s entries came across.', 'anypress-client'),
                number_format_i18n($imported),
                number_format_i18n($found)
            );
        }
        if ($type === 'contactform7') {
            $form = class_exists('WPCF7_ContactForm') ? \WPCF7_ContactForm::get_instance($item['ID']) : null;
            $files = $form && $form->scan_form_tags(['basetype' => 'file']);

            return $files
                ? __('Entries keep the date each was sent, its IP address and page. Files sent with the form aren\'t in them: Flamingo keeps only the file names.', 'anypress-client')
                : __('Entries keep the date each was sent, its IP address and page.', 'anypress-client');
        }

        if ($type === 'gravityform') {
            return __('Entries keep the date each was sent, their order, and the visitor\'s IP address, browser and page.', 'anypress-client');
        }

        return __('Entries keep the date each was sent and their order. The visitor\'s IP address and browser aren\'t carried over.', 'anypress-client');
    }

    /**
     * The IP, browser and page each entry was sent from (Flamingo and Gravity Forms keep them; the importer leaves
     * them empty).
     */
    private static function restore_details(int $fluent_id, array $details): void
    {
        $ids = \FluentForm\App\Models\Submission::where('form_id', $fluent_id)->orderBy('id', 'ASC')->pluck('id')->all();
        if (count($ids) !== count($details)) {
            return;
        }
        foreach (array_values($ids) as $i => $submission) {
            \FluentForm\App\Models\Submission::where('id', (int) $submission)->update([
                'ip' => substr(sanitize_text_field($details[$i]['ip']), 0, 45),
                'browser' => substr(sanitize_text_field($details[$i]['browser']), 0, 45),
                'source_url' => esc_url_raw($details[$i]['source_url']),
            ]);
        }
    }
}

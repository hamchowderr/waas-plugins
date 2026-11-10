<?php

namespace AnyPressClient\Forms;

/**
 * Reads and tidies the forms Fluent Forms' importer made: which Fluent Forms form a source form became, its fields
 * and entry count, and the importer's leftover notification and confirmation rows. Part of the forms switch.
 */
final class FluentFormsStore
{
    /** Fluent Forms' own map of imported forms: Fluent id => imported_form_id, form_type. */
    private const FLUENT_MAP = '__ff_imorted_forms_map';

    /** The Fluent Forms id Fluent Forms' importer made for a source form. */
    public static function id_for(string $type, $id): ?int
    {
        $map = get_option(self::FLUENT_MAP);
        foreach (is_array($map) ? $map : [] as $fluent_id => $source) {
            if (($source['form_type'] ?? '') === $type && (string) ($source['imported_form_id'] ?? '') === (string) $id && self::exists((int) $fluent_id)) {
                return (int) $fluent_id;
            }
        }

        return null;
    }

    public static function exists(int $id): bool
    {
        global $wpdb;

        return $id > 0 && (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fluentform_forms WHERE id = %d", $id));
    }

    public static function fields(int $id): array
    {
        global $wpdb;
        $json = $wpdb->get_var($wpdb->prepare("SELECT form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id = %d", $id));
        $fields = is_string($json) ? json_decode($json, true) : null;

        return is_array($fields) ? $fields : [];
    }

    public static function submissions(int $id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions WHERE form_id = %d", $id));
    }

    /**
     * Deletes the notification rows that aren't notifications. Fluent Forms' CF7 importer (and any earlier run of it
     * from Fluent Forms → Tools) writes one row per setting of a single notification ("Admin Notification Email", [], "").
     */
    public static function remove_junk_notifications(int $id): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fluentform_form_meta';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, value FROM %i WHERE form_id = %d AND meta_key = 'notifications'", $table, $id));
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
    public static function remove_unconditional_confirmations(int $id): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fluentform_form_meta';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, value FROM %i WHERE form_id = %d AND meta_key = 'confirmations'", $table, $id));
        foreach ($rows as $row) {
            $value = json_decode((string) $row->value, true);
            if (empty($value['conditionals']['status'])) {
                $wpdb->delete($table, ['id' => (int) $row->id]);
            }
        }
    }

    /** Keeps the short list of converted Fluent Forms forms that have conditional confirmations. */
    public static function remember_conditional(array $state): void
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
        update_option(FormsSwitch::CONDITIONAL_OPTION, array_values(array_unique($ids)), false);
    }
}

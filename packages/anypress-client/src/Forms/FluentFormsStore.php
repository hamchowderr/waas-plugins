<?php

namespace AnyPressClient\Forms;

/**
 * Reads and tidies the forms Fluent Forms' importer made: which Fluent Forms form a source form became, its fields
 * and entry count, and the importer's leftover notification and confirmation rows, all through Fluent Forms' own
 * models (Form, FormMeta, Submission). Part of the forms switch.
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
        return $id > 0 && (bool) \FluentForm\App\Models\Form::find($id);
    }

    public static function fields(int $id): array
    {
        $form = \FluentForm\App\Models\Form::find($id);
        $fields = $form ? json_decode((string) $form->form_fields, true) : null;

        return is_array($fields) ? $fields : [];
    }

    public static function submissions(int $id): int
    {
        return (int) \FluentForm\App\Models\Submission::where('form_id', $id)->count();
    }

    /** A form's meta rows of one key, oldest first. */
    public static function meta(int $id, string $key): array
    {
        $rows = [];
        foreach (\FluentForm\App\Models\FormMeta::where('form_id', $id)->where('meta_key', $key)->orderBy('id', 'ASC')->get() as $row) {
            $rows[(int) $row->id] = (string) $row->value;
        }

        return $rows;
    }

    /**
     * Deletes the notification rows that aren't notifications. Fluent Forms' CF7 importer (and any earlier run of it
     * from Fluent Forms → Tools) writes one row per setting of a single notification ("Admin Notification Email", [], "").
     */
    public static function remove_junk_notifications(int $id): void
    {
        foreach (self::meta($id, 'notifications') as $row => $value) {
            $value = json_decode($value, true);
            if (!is_array($value) || !isset($value['sendTo'])) {
                \FluentForm\App\Models\FormMeta::where('id', $row)->delete();
            }
        }
    }

    /**
     * Deletes extra confirmations that have no rules: the main confirmation already covers every submission, so they
     * never show. (An earlier import through Fluent Forms → Tools leaves the real default message here.)
     */
    public static function remove_unconditional_confirmations(int $id): void
    {
        foreach (self::meta($id, 'confirmations') as $row => $value) {
            $value = json_decode($value, true);
            if (empty($value['conditionals']['status'])) {
                \FluentForm\App\Models\FormMeta::where('id', $row)->delete();
            }
        }
    }

    /** Keeps the short list of converted Fluent Forms forms that have conditional confirmations. */
    public static function remember_conditional(array $state): void
    {
        $ids = [];
        foreach ($state['forms'] as $form) {
            foreach (self::meta((int) $form['fluent_id'], 'confirmations') as $value) {
                $confirmation = json_decode($value, true);
                if (!empty($confirmation['conditionals']['status'])) {
                    $ids[] = (int) $form['fluent_id'];
                    break;
                }
            }
        }
        update_option(FormsSwitch::CONDITIONAL_OPTION, array_values(array_unique($ids)), false);
    }
}

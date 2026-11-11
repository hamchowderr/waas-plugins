<?php

namespace AnyPressClient\Forms;

/**
 * Corrections for Fluent Forms' Gravity Forms importer (Fluent Forms 6.2), kept as plain functions so they can be
 * tested without WordPress:
 * - name and address parts: the importer copies Gravity Forms' "isHidden" as "visible", so hidden parts show and
 *   shown parts are only right by luck; and a required address makes Address Line 2 required too;
 * - a name field in the "simple" (one box) format has no parts, which the importer can't read: it becomes a text field;
 * - dates keep their Gravity Forms format (the importer always uses Fluent Forms' default), times their 12 or 24 hours;
 * - restrictions: the entry limit, its period and every message are written as true or false, not their values;
 * - the default confirmation is the main one (the importer takes whichever comes first);
 * - only notifications sent when the form is submitted come across (not those for other events).
 */
final class GravityFixes
{
    /** Gravity Forms' date formats as Fluent Forms' (PHP-style) ones. */
    private const DATE_FORMATS = [
        'mdy' => 'm/d/Y',
        'dmy' => 'd/m/Y',
        'dmy_dash' => 'd-m-Y',
        'dmy_dot' => 'd.m.Y',
        'ymd_slash' => 'Y/m/d',
        'ymd_dash' => 'Y-m-d',
        'ymd_dot' => 'Y.m.d',
    ];

    /** Gravity Forms' entry limit periods as Fluent Forms' ('' is all time). */
    private const PERIODS = ['' => 'total', 'day' => 'day', 'week' => 'week', 'month' => 'month', 'year' => 'year'];

    /** Fluent Forms' address parts, in Gravity Forms' input order. */
    public const ADDRESS_PARTS = ['address_line_1', 'address_line_2', 'city', 'state', 'zip', 'country'];

    /** Name parts and their index in Gravity Forms' inputs (prefix, first, middle, last, suffix). */
    private const NAME_PARTS = ['first_name' => 1, 'middle_name' => 2, 'last_name' => 3];

    /**
     * The form's fields as plain arrays, made readable for the importer: a one-box name field becomes a text field.
     *
     * @param array $fields Gravity Forms' fields (GF_Field objects or arrays).
     * @return array<int, array>
     */
    public static function readable_fields(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $field = (array) $field;
            if (($field['type'] ?? '') === 'name' && !is_array($field['inputs'] ?? null)) {
                $field['type'] = 'text';
                $field['maxLength'] = $field['maxLength'] ?? '';
            }
            $out[] = $field;
        }

        return $out;
    }

    /** The name Fluent Forms' importer gives a Gravity Forms field or input (its label and id). */
    public static function input_name(array $field): string
    {
        return str_replace('-', '_', sanitize_title(($field['label'] ?? '') . '-' . ($field['id'] ?? '')));
    }

    /**
     * Puts the converted fields right, wherever they sit (the importer may wrap them in column containers).
     *
     * @param array $fluent Fluent Forms fields, as the importer made them.
     * @param array $source Gravity Forms fields as arrays (readable_fields()).
     */
    public static function fields(array $fluent, array $source): array
    {
        $by_name = [];
        foreach ($source as $field) {
            $by_name[self::input_name($field)] = $field;
        }
        $fix = function ($node) use (&$fix, $by_name) {
            if (!is_array($node)) {
                return $node;
            }
            $name = (string) ($node['attributes']['name'] ?? '');
            if (isset($node['element']) && isset($by_name[$name])) {
                return self::field($node, $by_name[$name]);
            }
            foreach ($node as $key => $child) {
                if (is_array($child)) {
                    $node[$key] = $fix($child);
                }
            }
            return $node;
        };

        return $fix($fluent);
    }

    private static function field(array $node, array $source): array
    {
        $inputs = array_values(is_array($source['inputs'] ?? null) ? $source['inputs'] : []);
        switch ($node['element']) {
            case 'input_name':
                foreach (self::NAME_PARTS as $part => $index) {
                    if (isset($node['fields'][$part]) && isset($inputs[$index])) {
                        $node['fields'][$part]['settings']['visible'] = empty($inputs[$index]['isHidden']);
                    }
                }
                break;
            case 'address':
                foreach (self::ADDRESS_PARTS as $index => $part) {
                    if (!isset($node['fields'][$part])) {
                        continue;
                    }
                    if (isset($inputs[$index])) {
                        $node['fields'][$part]['settings']['visible'] = empty($inputs[$index]['isHidden']);
                    }
                    if ($part === 'address_line_2') {
                        $node['fields'][$part]['settings']['validation_rules']['required']['value'] = false;
                    }
                }
                break;
            case 'input_date':
                $node['settings']['date_format'] = ($source['type'] ?? '') === 'time'
                    ? ((string) ($source['timeFormat'] ?? '12') === '24' ? 'H:i' : 'h:i K')
                    : (self::DATE_FORMATS[(string) ($source['dateFormat'] ?? 'mdy')] ?? 'm/d/Y');
                break;
        }

        return $node;
    }

    /** The form with its default confirmation first and only the notifications sent on submission. */
    public static function ordered_form(array $form): array
    {
        $confirmations = array_values(is_array($form['confirmations'] ?? null) ? $form['confirmations'] : []);
        usort($confirmations, fn ($a, $b) => (int) !empty($b['isDefault']) <=> (int) !empty($a['isDefault']));
        $form['confirmations'] = $confirmations;
        $notifications = is_array($form['notifications'] ?? null) ? $form['notifications'] : [];
        $form['notifications'] = array_values(array_filter($notifications, fn ($n) => in_array((string) ($n['event'] ?? 'form_submission'), ['', 'form_submission'], true)));

        return $form;
    }

    /** Fluent Forms' restrictions, with Gravity Forms' own limits, schedule and messages. */
    public static function restrictions(array $restrictions, array $form): array
    {
        $text = fn ($key) => trim((string) ($form[$key] ?? ''));
        if ($text('requireLoginMessage') !== '') {
            $restrictions['requireLogin']['requireLoginMsg'] = $text('requireLoginMessage');
        }
        $restrictions['limitNumberOfEntries']['numberOfEntries'] = !empty($form['limitEntries']) ? (int) ($form['limitEntriesCount'] ?? 0) : null;
        $restrictions['limitNumberOfEntries']['period'] = self::PERIODS[(string) ($form['limitEntriesPeriod'] ?? '')] ?? 'total';
        if ($text('limitEntriesMessage') !== '') {
            $restrictions['limitNumberOfEntries']['limitReachedMsg'] = $text('limitEntriesMessage');
        }
        $restrictions['scheduleForm']['start'] = self::schedule_time($form, 'scheduleStart');
        $restrictions['scheduleForm']['end'] = self::schedule_time($form, 'scheduleEnd');
        if ($text('schedulePendingMessage') !== '') {
            $restrictions['scheduleForm']['pendingMsg'] = $text('schedulePendingMessage');
        }
        if ($text('scheduleMessage') !== '') {
            $restrictions['scheduleForm']['expiredMsg'] = $text('scheduleMessage');
        }

        return $restrictions;
    }

    /** A Gravity Forms schedule date (mm/dd/yyyy, with its hour, minute and am/pm) as "Y-m-d H:i:s", or null. */
    public static function schedule_time(array $form, string $key): ?string
    {
        if (empty($form['scheduleForm']) || !preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim((string) ($form[$key] ?? '')), $m)) {
            return null;
        }
        $hour = (int) ($form[$key . 'Hour'] ?? 12) % 12;
        if (strtolower((string) ($form[$key . 'Ampm'] ?? 'am')) === 'pm') {
            $hour += 12;
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:00', (int) $m[3], (int) $m[1], (int) $m[2], $hour, (int) ($form[$key . 'Minute'] ?? 0));
    }

    /** An address entry's parts, by Fluent Forms' part name, leaving out the empty ones. */
    public static function address_value(array $inputs, array $entry): array
    {
        $value = [];
        foreach (array_values($inputs) as $index => $input) {
            $part = self::ADDRESS_PARTS[$index] ?? null;
            $given = $part ? trim((string) ($entry[(string) ($input['id'] ?? '')] ?? '')) : '';
            if ($given !== '') {
                $value[$part] = $given;
            }
        }

        return $value;
    }

    /**
     * The source form's fields for the dropped-fields report, by the name the importer gives each one.
     *
     * @return array<int, array{name: string, label: string, type: string, layout?: bool}>
     */
    public static function source_fields(array $form): array
    {
        $fields = [];
        foreach (self::readable_fields((array) ($form['fields'] ?? [])) as $field) {
            $type = (string) ($field['type'] ?? '');
            $fields[] = [
                'name' => self::input_name($field),
                'label' => (string) ($field['label'] ?? ''),
                'type' => $type,
                'layout' => in_array($type, ['html', 'section', 'page'], true),
            ];
        }

        return $fields;
    }

    /** Labels of the fields that show or hide by a rule (the importer doesn't carry field rules over). */
    public static function conditional_fields(array $form): array
    {
        $labels = [];
        foreach (self::readable_fields((array) ($form['fields'] ?? [])) as $field) {
            $logic = $field['conditionalLogic'] ?? null;
            if (is_array($logic) && !empty($logic['rules'])) {
                $labels[] = (string) ($field['label'] ?? ('#' . ($field['id'] ?? '')));
            }
        }

        return $labels;
    }
}

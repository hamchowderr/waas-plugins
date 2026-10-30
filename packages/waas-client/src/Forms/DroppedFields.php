<?php

namespace WaaSClient\Forms;

/**
 * Works out which fields of a source form didn't make it into its Fluent Forms copy. The migrator only lists some
 * of them (for Contact Form 7 it lists none), so this compares the source form's own fields with the fields of the
 * converted form, by the name the migrator gives each one.
 */
class DroppedFields
{
    /** Source field types that only come across when Fluent Forms Pro is active. */
    public const NEEDS_PRO = [
        'contactform7' => ['tel', 'range', 'file'],
        'wpforms' => ['phone', 'number-slider', 'file-upload', 'pagebreak', 'richtext'],
        'ninja_forms' => ['phone', 'repeater'],
    ];

    /**
     * @param array    $source      The source form's fields: name (what Fluent Forms would call it), label, type,
     *                              and layout => true for fields that hold no value (dividers, HTML, page breaks).
     * @param array    $fluent      The converted form's form_fields (decoded).
     * @param string[] $unsupported Labels the migrator itself reported as unsupported.
     * @return array<int, array{name: string, label: string, type: string, reason: string}>
     */
    public static function find(string $source_type, array $source, array $fluent, array $unsupported, bool $pro): array
    {
        $names = self::fluent_names($fluent);
        $dropped = [];
        foreach ($source as $field) {
            $name = (string) ($field['name'] ?? '');
            $label = (string) ($field['label'] ?? '');
            $type = (string) ($field['type'] ?? '');
            $kept = !empty($field['layout'])
                ? !in_array($label, $unsupported, true) || $label === ''
                : ($name !== '' && in_array($name, $names, true));
            if ($kept) {
                continue;
            }
            $dropped[] = [
                'name' => $name,
                'label' => $label,
                'type' => $type,
                'reason' => self::reason($source_type, $type, $pro),
            ];
        }

        return $dropped;
    }

    public static function reason(string $source_type, string $type, bool $pro): string
    {
        if (!$pro && in_array($type, self::NEEDS_PRO[$source_type] ?? [], true)) {
            return 'Needs Fluent Forms Pro.';
        }
        if ($type === 'quiz') {
            return 'Fluent Forms has no quiz question.';
        }

        return 'Fluent Forms\' importer has no matching field.';
    }

    /** Every field name in a Fluent Forms form, including those inside containers, steps and name fields. */
    public static function fluent_names(array $fluent): array
    {
        $names = [];
        $walk = function ($node) use (&$walk, &$names) {
            if (!is_array($node)) {
                return;
            }
            if (isset($node['element'], $node['attributes']['name']) && is_string($node['attributes']['name']) && $node['attributes']['name'] !== '') {
                $names[] = $node['attributes']['name'];
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($fluent);

        return array_values(array_unique($names));
    }
}

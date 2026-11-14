<?php
/**
 * Plugin Name: Gravity Forms (test stand-in)
 * Description: Test only. Gravity Forms is a paid plugin, not on wordpress.org, so the forms switch test can't install
 *              it. This stands in for the part of its PHP API that Fluent Forms' Gravity Forms importer and AnyPress
 *              Client read (GFForms, GFAPI, GFFormsModel, GF_Field), with Gravity Forms' own array shapes, reading its
 *              forms, entries and add-on feeds from the gf_test_forms, gf_test_entries and gf_test_feeds options.
 * Version: 2.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class GFForms
{
    public static $version = '2.9.0';
}

/** A Gravity Forms field: its settings as public properties, readable as an array too. */
#[AllowDynamicProperties]
class GF_Field implements ArrayAccess
{
    public function __construct($data = [])
    {
        foreach ((array) $data as $key => $value) {
            $this->$key = $value;
        }
    }

    public function offsetExists($offset): bool
    {
        return isset($this->$offset);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->$offset ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        $this->$offset = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->$offset);
    }

    /** As Gravity Forms: one input's value, or a multi-input field's parts joined. */
    public function get_value_export($entry, $input_id = '', $use_text = false, $is_csv = false)
    {
        $input_id = $input_id === '' ? (string) $this->id : (string) $input_id;
        if (strpos($input_id, '.') === false && is_array($this->inputs ?? null) && in_array($this->type, ['name', 'address', 'checkbox'], true)) {
            $parts = [];
            foreach ($this->inputs as $input) {
                $value = trim((string) rgar($entry, (string) $input['id']));
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
            return implode($this->type === 'checkbox' ? ', ' : ' ', $parts);
        }

        return rgar($entry, $input_id);
    }
}

function rgar($array, $key, $default = '')
{
    return is_array($array) && isset($array[$key]) ? $array[$key] : $default;
}

class GFAPI
{
    private static function stored(string $option): array
    {
        $value = get_option($option, []);
        return is_array($value) ? $value : [];
    }

    private static function hydrate(array $form): array
    {
        $form['fields'] = array_map(fn ($field) => new GF_Field($field), (array) ($form['fields'] ?? []));
        return $form;
    }

    public static function get_forms($active = true, $trash = false, $sort_column = 'id', $sort_dir = 'ASC')
    {
        return array_values(array_map([self::class, 'hydrate'], self::stored('gf_test_forms')));
    }

    public static function get_form($form_id)
    {
        foreach (self::stored('gf_test_forms') as $form) {
            if ((int) $form['id'] === (int) $form_id) {
                return self::hydrate($form);
            }
        }
        return false;
    }

    private static function entries($form_id): array
    {
        return array_values(array_filter(self::stored('gf_test_entries'), fn ($e) => (int) $e['form_id'] === (int) $form_id && ($e['status'] ?? 'active') === 'active'));
    }

    public static function count_entries($form_ids, $search_criteria = [])
    {
        return count(self::entries($form_ids));
    }

    public static function get_entries($form_ids, $search_criteria = [], $sorting = null, $paging = null, &$total_count = null)
    {
        $entries = self::entries($form_ids);
        usort($entries, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);
        if (strtoupper((string) ($sorting['direction'] ?? 'DESC')) === 'DESC') {
            $entries = array_reverse($entries);
        }
        $total_count = count($entries);
        return array_slice($entries, (int) ($paging['offset'] ?? 0), (int) ($paging['page_size'] ?? 20));
    }

    public static function get_feeds($feed_ids = null, $form_id = null, $addon_slug = null, $is_active = true)
    {
        $feeds = array_values(array_filter(self::stored('gf_test_feeds'), fn ($f) => $form_id === null || (int) $f['form_id'] === (int) $form_id));
        return $feeds ?: new WP_Error('not_found', 'Feed not found');
    }
}

class GFFormsModel
{
    public static function get_field($form, $field_id)
    {
        foreach ((array) ($form['fields'] ?? []) as $field) {
            if ((string) $field->id === (string) (int) $field_id) {
                return $field;
            }
        }
        return null;
    }
}

// The form on the page, as Gravity Forms shows it (enough to tell it apart from Fluent Forms' form).
add_shortcode('gravityform', fn ($atts) => '<div class="gform_wrapper" data-form="' . esc_attr($atts['id'] ?? '') . '">Gravity form</div>');
add_shortcode('gravityforms', fn ($atts) => '<div class="gform_wrapper" data-form="' . esc_attr($atts['id'] ?? '') . '">Gravity form</div>');

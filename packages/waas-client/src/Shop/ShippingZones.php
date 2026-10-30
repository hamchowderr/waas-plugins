<?php

namespace WaaSClient\Shop;

/**
 * Copies WooCommerce's shipping zones and their flat rate, free shipping and local pickup methods to FluentCart
 * (the FluentCart Migrator add-on has no shipping step).
 *
 * FluentCart puts a zone on one country, a list of countries, or the whole world, and limits states on each method;
 * it has no postcodes and no free-shipping conditions. Anything that doesn't fit is reported, never guessed: a free
 * shipping method with a minimum order or coupon condition comes across turned off, so it can't make every order
 * free. Each zone and method is copied once (the ids are kept in the switch's state), so a second run adds nothing.
 */
class ShippingZones
{
    /** Flat rate costs FluentCart can hold: a plain amount, or an amount per item ("4 * [qty]"). */
    private const PER_ITEM = '/^\s*(\d+(?:\.\d+)?)\s*\*\s*\[qty\]\s*$|^\s*\[qty\]\s*\*\s*(\d+(?:\.\d+)?)\s*$/i';

    /**
     * Where a WooCommerce zone applies, in FluentCart's terms.
     *
     * @param array $locations Each ['code' => 'US' | 'US:CA' | '9021*' | 'EU', 'type' => country|state|postcode|continent].
     * @param array $continents WooCommerce's continent code => its country codes.
     * @return array{region: string, meta: array|null, states: string[], lost: string[]}
     */
    public static function zone_region(array $locations, array $continents = []): array
    {
        $countries = [];
        $states = [];
        $postcodes = [];
        $lost = [];
        foreach ($locations as $location) {
            $code = strtoupper(trim((string) ($location['code'] ?? '')));
            $type = (string) ($location['type'] ?? '');
            if ($code === '') {
                continue;
            }
            if ($type === 'country') {
                $countries[$code] = true;
            } elseif ($type === 'state' && strpos($code, ':') !== false) {
                [$country, $state] = explode(':', $code, 2);
                $states[$country][] = $state;
            } elseif ($type === 'continent') {
                foreach ($continents[$code] ?? [] as $country) {
                    $countries[strtoupper((string) $country)] = true;
                }
                if (empty($continents[$code])) {
                    $lost[] = sprintf('Continent %s: no countries known for it, so it was left out.', $code);
                }
            } elseif ($type === 'postcode') {
                $postcodes[] = $code;
            }
        }

        $keep_states = [];
        if ($states) {
            // FluentCart limits states on each method, within one country.
            if (count($states) === 1 && !$countries) {
                $keep_states = array_values(array_unique(reset($states)));
            } else {
                $lost[] = sprintf('State limits (%s) were dropped: FluentCart can only limit states within a single-country zone. The zone covers these countries whole.', implode(', ', array_map(fn ($country, $list) => $country . ': ' . implode(' ', $list), array_keys($states), $states)));
            }
            foreach (array_keys($states) as $country) {
                $countries[$country] = true;
            }
        }
        if ($postcodes) {
            $lost[] = sprintf('Postcode limits (%s) were dropped: FluentCart zones have no postcodes. The zone covers its whole region.', implode(', ', $postcodes));
        }

        $countries = array_keys($countries);
        sort($countries);
        if (!$countries) {
            return ['region' => 'all', 'meta' => null, 'states' => [], 'lost' => $lost];
        }
        if (count($countries) === 1) {
            return ['region' => $countries[0], 'meta' => null, 'states' => $keep_states, 'lost' => $lost];
        }

        return ['region' => 'selection', 'meta' => ['countries' => $countries, 'selection_type' => 'included'], 'states' => [], 'lost' => $lost];
    }

    /**
     * A WooCommerce shipping method as a FluentCart one, or null when FluentCart has nothing like it.
     *
     * @param string $type WooCommerce's method id: flat_rate, free_shipping, local_pickup, ...
     * @param array $settings Its instance settings.
     * @return array{row: array|null, lost: string[]}
     */
    public static function method_row(string $type, array $settings, bool $enabled, array $states, int $order): array
    {
        $title = trim((string) ($settings['title'] ?? ''));
        $lost = [];
        $row = [
            'title' => $title,
            'type' => $type,
            'settings' => ['configure_rate' => 'per_order', 'class_aggregation' => 'sum_all'],
            'amount' => 0.0,
            'is_enabled' => $enabled ? 1 : 0,
            'states' => array_values($states),
            'order' => $order,
            'meta' => ['description' => ''],
        ];

        if ($type === 'flat_rate' || $type === 'local_pickup') {
            $row['title'] = $title !== '' ? $title : ($type === 'flat_rate' ? 'Flat rate' : 'Local pickup');
            $cost = trim(str_replace(',', '.', (string) ($settings['cost'] ?? '')));
            if ($cost === '' || is_numeric($cost)) {
                $row['amount'] = round((float) $cost, 2);
            } elseif (preg_match(self::PER_ITEM, $cost, $match)) {
                $row['amount'] = round((float) ($match[1] !== '' ? $match[1] : $match[2]), 2);
                $row['settings']['configure_rate'] = 'per_item';
            } else {
                return ['row' => null, 'lost' => [sprintf('"%s" was not copied: its cost "%s" is a formula FluentCart can\'t hold. Add it by hand.', $row['title'], $cost)]];
            }
            $classes = array_filter($settings, fn ($value, $key) => strpos((string) $key, 'class_cost_') === 0 && trim((string) $value) !== '', ARRAY_FILTER_USE_BOTH);
            if ($classes || trim((string) ($settings['no_class_cost'] ?? '')) !== '') {
                $lost[] = sprintf('"%s": its shipping class costs were not copied. Set them on FluentCart\'s shipping classes.', $row['title']);
            }

            return ['row' => $row, 'lost' => $lost];
        }

        if ($type === 'free_shipping') {
            $row['title'] = $title !== '' ? $title : 'Free shipping';
            $requires = (string) ($settings['requires'] ?? '');
            if ($requires !== '' && $requires !== 'none') {
                $row['is_enabled'] = 0;
                $lost[] = sprintf('"%s" needs %s in WooCommerce. FluentCart\'s free shipping has no conditions, so it was copied turned off; turn it on only if every order in the zone should ship free.', $row['title'], self::condition($requires, $settings));
            }

            return ['row' => $row, 'lost' => $lost];
        }

        return ['row' => null, 'lost' => [sprintf('"%s" (%s) was not copied: FluentCart has no such shipping method.', $title !== '' ? $title : $type, $type)]];
    }

    private static function condition(string $requires, array $settings): string
    {
        $minimum = trim((string) ($settings['min_amount'] ?? ''));
        $amount = $minimum !== '' ? 'a minimum order of ' . $minimum : 'a minimum order';
        switch ($requires) {
            case 'coupon':
                return 'a free shipping coupon';
            case 'min_amount':
                return $amount;
            case 'either':
                return $amount . ' or a free shipping coupon';
            case 'both':
                return $amount . ' and a free shipping coupon';
        }

        return 'a condition (' . $requires . ')';
    }

    /**
     * WooCommerce's zones, the "rest of the world" zone last, as plain arrays.
     *
     * @return array<int, array{id: int, name: string, order: int, locations: array, methods: array}>
     */
    public static function woo_zones(): array
    {
        if (!class_exists('WC_Shipping_Zones')) {
            return [];
        }
        $zones = [];
        foreach (\WC_Shipping_Zones::get_zones() as $zone) {
            $zones[] = [
                'id' => (int) $zone['id'],
                'name' => (string) $zone['zone_name'],
                'order' => (int) $zone['zone_order'],
                'locations' => array_map(fn ($location) => ['code' => (string) $location->code, 'type' => (string) $location->type], (array) $zone['zone_locations']),
                'methods' => self::methods((array) $zone['shipping_methods']),
            ];
        }
        $rest = new \WC_Shipping_Zone(0);
        $methods = self::methods($rest->get_shipping_methods());
        if ($methods) {
            $zones[] = ['id' => 0, 'name' => 'Rest of the world', 'order' => count($zones) + 1, 'locations' => [], 'methods' => $methods];
        }

        return $zones;
    }

    private static function methods(array $methods): array
    {
        $rows = [];
        foreach ($methods as $method) {
            $rows[] = [
                'instance_id' => (int) $method->instance_id,
                'type' => (string) $method->id,
                'enabled' => $method->enabled === 'yes',
                'order' => (int) ($method->method_order ?? 0),
                'settings' => is_array($method->instance_settings) ? $method->instance_settings : [],
            ];
        }

        return $rows;
    }

    /** WooCommerce's continent code => country codes (for zones set by continent). */
    public static function continents(): array
    {
        if (!function_exists('WC') || !WC()->countries) {
            return [];
        }

        return array_map(fn ($continent) => (array) ($continent['countries'] ?? []), (array) WC()->countries->get_continents());
    }

    /**
     * Copies the zones (or with $dry_run, says what it would copy).
     *
     * @param array $state The switch's state; its 'zones' and 'methods' maps are filled in.
     * @return array{zones: array, lost: string[]}
     */
    public static function copy(array &$state, bool $dry_run): array
    {
        global $wpdb;
        $report = ['zones' => [], 'lost' => []];
        $continents = self::continents();
        $now = current_time('mysql');

        foreach (self::woo_zones() as $zone) {
            $where = self::zone_region($zone['locations'], $continents);
            $fc_zone = (int) ($state['zones'][$zone['id']] ?? 0);
            $exists = $fc_zone && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fct_shipping_zones WHERE id = %d", $fc_zone));
            $row = ['zone' => $zone['name'], 'region' => $where['meta'] ? implode(' ', $where['meta']['countries']) : $where['region'], 'methods' => [], 'status' => 'already copied'];
            foreach ($where['lost'] as $note) {
                $report['lost'][] = $zone['name'] . ': ' . $note;
            }

            if (!$exists) {
                $row['status'] = $dry_run ? 'will be copied' : 'copied';
                if (!$dry_run) {
                    $wpdb->insert($wpdb->prefix . 'fct_shipping_zones', [
                        'name' => $zone['name'],
                        'region' => $where['region'],
                        'meta' => $where['meta'] ? wp_json_encode($where['meta']) : '{}',
                        'order' => $zone['order'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $fc_zone = (int) $wpdb->insert_id;
                    if (!$fc_zone) {
                        $row['status'] = 'failed: ' . $wpdb->last_error;
                        $report['zones'][] = $row;
                        continue;
                    }
                    $state['zones'][$zone['id']] = $fc_zone;
                }
            }

            foreach ($zone['methods'] as $method) {
                $copy = self::method_row($method['type'], $method['settings'], $method['enabled'], $where['states'], $method['order']);
                foreach ($copy['lost'] as $note) {
                    $report['lost'][] = $zone['name'] . ': ' . $note;
                }
                if (!$copy['row']) {
                    $row['methods'][] = $method['type'] . ' (not copied)';
                    continue;
                }
                $label = $copy['row']['title'] . ' ' . ($copy['row']['type'] === 'free_shipping' ? '' : number_format((float) $copy['row']['amount'], 2) . ' ') . ($copy['row']['is_enabled'] ? '' : '(off)');
                $row['methods'][] = trim($label);
                $fc_method = (int) ($state['methods'][$method['instance_id']] ?? 0);
                if ($fc_method && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fct_shipping_methods WHERE id = %d", $fc_method))) {
                    continue;
                }
                if ($dry_run || !$fc_zone) {
                    continue;
                }
                $data = $copy['row'];
                $wpdb->insert($wpdb->prefix . 'fct_shipping_methods', [
                    'zone_id' => $fc_zone,
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'settings' => wp_json_encode($data['settings']),
                    'amount' => $data['amount'],
                    'is_enabled' => $data['is_enabled'],
                    'states' => wp_json_encode($data['states']),
                    'order' => $data['order'],
                    'meta' => wp_json_encode($data['meta']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($wpdb->insert_id) {
                    $state['methods'][$method['instance_id']] = (int) $wpdb->insert_id;
                }
            }
            $report['zones'][] = $row;
        }

        return $report;
    }

    /** Removes the zones and methods the switch copied. */
    public static function undo(array &$state, bool $dry_run): array
    {
        global $wpdb;
        $removed = ['zones' => count($state['zones']), 'methods' => count($state['methods'])];
        if ($dry_run) {
            return $removed;
        }
        foreach ($state['methods'] as $id) {
            $wpdb->delete($wpdb->prefix . 'fct_shipping_methods', ['id' => (int) $id]);
        }
        foreach ($state['zones'] as $id) {
            $wpdb->delete($wpdb->prefix . 'fct_shipping_zones', ['id' => (int) $id]);
        }
        $state['zones'] = [];
        $state['methods'] = [];

        return $removed;
    }
}

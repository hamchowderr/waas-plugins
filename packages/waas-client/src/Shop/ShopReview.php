<?php

namespace WaaSClient\Shop;

/**
 * What the owner has to look at by hand after the switch; nothing here changes the shop.
 *
 * - Product tags: FluentCart has no tags (only categories and brands), so each tagged product is listed.
 * - Tax rates: the FluentCart Migrator add-on doesn't copy WooCommerce's rates; it loads FluentCart's built-in
 *   rates for each country and maps each WooCommerce rate to one by country and state. Every WooCommerce rate whose
 *   value, name or settings differ from the FluentCart rate it was mapped to is listed. Rates are never changed here.
 */
class ShopReview
{
    /** The add-on's map: WooCommerce tax_rate_id => fct_tax_rates.id. */
    public const TAX_MAP = '_woo_fct_tax_rate_maps';

    /**
     * @param array $woo Rows of woocommerce_tax_rates, each with its 'postcodes' and 'cities' lists added.
     * @param array $fluent fct_tax_rates.id => row.
     * @param array $map WooCommerce tax_rate_id => fct_tax_rates.id (empty before the switch).
     * @param array $by_place "country:state" => fct_tax_rates row, to say what a not-yet-mapped rate would use.
     * @return array<int, array> Only the rates that differ.
     */
    public static function tax_differences(array $woo, array $fluent, array $map, array $by_place = []): array
    {
        $rows = [];
        foreach ($woo as $rate) {
            $id = (int) $rate['tax_rate_id'];
            $country = (string) $rate['tax_rate_country'];
            $state = (string) $rate['tax_rate_state'];
            $place = trim($country . ($state !== '' ? ' ' . $state : '')) ?: 'every country';
            $fc = isset($map[$id]) ? ($fluent[(int) $map[$id]] ?? null) : ($by_place[$country . ':' . $state] ?? $by_place[$country . ':'] ?? null);
            $differs = [];
            if (!$fc) {
                $differs[] = $country === ''
                    ? 'FluentCart has no rate for every country; this rate was not mapped.'
                    : 'FluentCart has no built-in rate for this place yet; it is added when the switch runs.';
            } else {
                if (abs((float) $rate['tax_rate'] - (float) $fc['rate']) > 0.00005) {
                    $differs[] = sprintf('Rate: WooCommerce %s%%, FluentCart %s%%.', self::percent($rate['tax_rate']), self::percent($fc['rate']));
                }
                if (trim((string) $rate['tax_rate_name']) !== trim((string) $fc['name'])) {
                    $differs[] = sprintf('Name: WooCommerce "%s", FluentCart "%s".', $rate['tax_rate_name'], $fc['name']);
                }
                if ((int) ($rate['tax_rate_shipping'] ?? 0) && empty($fc['for_shipping'])) {
                    $differs[] = 'WooCommerce also taxes shipping at this rate; the FluentCart rate does not say so.';
                }
                if ((int) ($rate['tax_rate_compound'] ?? 0) && empty($fc['is_compound'])) {
                    $differs[] = 'Compound in WooCommerce, not in FluentCart.';
                }
            }
            if (!empty($rate['postcodes']) || !empty($rate['cities'])) {
                $differs[] = sprintf('Limited to %s in WooCommerce; FluentCart\'s rate covers the whole %s.', implode(', ', array_merge((array) $rate['postcodes'], (array) $rate['cities'])), $state !== '' ? 'state' : 'country');
            }
            if ((string) ($rate['tax_rate_class'] ?? '') !== '') {
                $differs[] = sprintf('WooCommerce tax class "%s"; the add-on maps every rate to FluentCart\'s Standard class.', $rate['tax_rate_class']);
            }
            if ($differs) {
                $rows[] = [
                    'place' => $place,
                    'woocommerce' => sprintf('%s %s%%', $rate['tax_rate_name'], self::percent($rate['tax_rate'])),
                    'fluentcart' => $fc ? sprintf('%s %s%%', $fc['name'], self::percent($fc['rate'])) : '-',
                    'differences' => $differs,
                ];
            }
        }

        return $rows;
    }

    private static function percent($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    /** The WooCommerce rates that differ from FluentCart's, as tax_differences() lists them. */
    public static function tax_rates(): array
    {
        global $wpdb;
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'woocommerce_tax_rates'))) {
            return [];
        }
        $woo = (array) $wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_order, tax_rate_id", ARRAY_A);
        foreach ($woo as &$rate) {
            $rate['postcodes'] = [];
            $rate['cities'] = [];
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT location_code, location_type FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE tax_rate_id = %d", (int) $rate['tax_rate_id']), ARRAY_A) as $location) {
                $rate[$location['location_type'] === 'city' ? 'cities' : 'postcodes'][] = $location['location_code'];
            }
        }
        unset($rate);

        $fluent = [];
        $by_place = [];
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'fct_tax_rates'))) {
            foreach ((array) $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fct_tax_rates ORDER BY id", ARRAY_A) as $row) {
                $fluent[(int) $row['id']] = $row;
                // The add-on's own lookup: country and state, then country alone (its first match).
                $by_place += [$row['country'] . ':' . $row['state'] => $row];
                $by_place += [$row['country'] . ':' => $row];
            }
        }
        $map = get_option(self::TAX_MAP, []);

        return self::tax_differences($woo, $fluent, is_array($map) ? $map : [], $by_place);
    }

    /** Each WooCommerce product with tags, and its tags. */
    public static function tags(): array
    {
        if (!taxonomy_exists('product_tag')) {
            return [];
        }
        $rows = [];
        $terms = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => true]);
        foreach (is_array($terms) ? $terms : [] as $term) {
            foreach ((array) get_objects_in_term((int) $term->term_id, 'product_tag') as $id) {
                $rows[(int) $id]['product'] = get_the_title((int) $id);
                $rows[(int) $id]['tags'][] = $term->name;
            }
        }
        ksort($rows);

        return array_map(fn ($id, $row) => ['id' => $id, 'product' => $row['product'], 'tags' => implode(', ', $row['tags'])], array_keys($rows), $rows);
    }
}

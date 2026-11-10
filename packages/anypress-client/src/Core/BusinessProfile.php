<?php

namespace AnyPressClient\Core;

use AnyPressClient\Features\PluginBootstrap;

/**
 * What the website assistant knows about the business behind this site, so it never has to ask what the site
 * already says. Two halves:
 *
 * - `site`: read fresh from WordPress each time: the site's name, tagline, language and time zone; the plan's site
 *   type; the business details from the purchase (`anypress_store_owner`: name, phone, city, state, country); and what
 *   it offers: its products and prices, booking types and forms, by name.
 * - `about`: what the owner tells it (what the business does, who it is for, where, opening hours, how it should
 *   sound, anything else), saved in the `anypress_business_profile` option. The checkout's optional "What does your
 *   business do?" answer fills `what` until the owner saves their own. The previous version is kept, so a save can
 *   be undone.
 *
 * Read only by the signed site routes (Api\SiteController). Cheap: a few options and three small, capped queries.
 */
class BusinessProfile
{
    public const OPTION = 'anypress_business_profile';
    public const PREVIOUS_OPTION = 'anypress_business_profile_previous';

    /** The owner's own facts, with the most characters each keeps. */
    public const FIELDS = [
        'what' => 500,
        'who' => 500,
        'area' => 300,
        'hours' => 300,
        'contact' => 300,
        'voice' => 200,
        'notes' => 1500,
    ];

    /** At most this many products, booking types and forms are listed. */
    private const LIST_LIMIT = 12;

    public static function read(): array
    {
        return [
            'site' => self::site(),
            // An object even when empty, so it reads as {} in JSON.
            'about' => (object) self::about(),
            'updated' => (string) get_option(self::OPTION . '_updated', ''),
        ];
    }

    /**
     * Saves the owner's facts. Only the fields given change; an empty string clears one. Returns the new profile.
     *
     * @param array<string, mixed> $about
     */
    public static function save(array $about): array
    {
        $current = self::stored();
        $next = $current;
        foreach (self::FIELDS as $key => $max) {
            if (!array_key_exists($key, $about)) {
                continue;
            }
            $value = trim(sanitize_textarea_field((string) $about[$key]));
            $value = function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
            if ($value === '') {
                unset($next[$key]);
            } else {
                $next[$key] = $value;
            }
        }
        if ($next !== $current) {
            update_option(self::PREVIOUS_OPTION, $current, false);
            update_option(self::OPTION, $next, false);
            update_option(self::OPTION . '_updated', gmdate('c'), false);
        }

        return self::read();
    }

    /** Puts back the version before the last save. */
    public static function undo(): array
    {
        $previous = get_option(self::PREVIOUS_OPTION, null);
        if (is_array($previous)) {
            update_option(self::PREVIOUS_OPTION, self::stored(), false);
            update_option(self::OPTION, $previous, false);
            update_option(self::OPTION . '_updated', gmdate('c'), false);
        }

        return self::read();
    }

    /** @return array<string, string> */
    private static function stored(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? array_intersect_key(array_map('strval', $stored), self::FIELDS) : [];
    }

    /** @return array<string, string> */
    private static function about(): array
    {
        $about = self::stored();
        if (!isset($about['what'])) {
            $answer = trim((string) (self::owner()['about'] ?? ''));
            if ($answer !== '') {
                $about['what'] = $answer;
            }
        }

        return $about;
    }

    /** The purchase's business details, as the platform set them. */
    private static function owner(): array
    {
        $owner = json_decode((string) get_option('anypress_store_owner', ''), true);

        return is_array($owner) ? $owner : [];
    }

    private static function site(): array
    {
        $owner = self::owner();
        $text = static fn (string $key): string => trim(wp_specialchars_decode((string) ($owner[$key] ?? ''), ENT_QUOTES));
        $country = strtoupper(substr($text('country'), 0, 2));
        $details = get_option('anypress_owner_details');
        $site_types = array_values(array_filter(array_map('trim', array_map('strval', (array) get_option(PluginBootstrap::TENANT_ROLES, [])))));

        return array_filter([
            'name' => (string) get_bloginfo('name'),
            'tagline' => (string) get_bloginfo('description'),
            'url' => home_url('/'),
            'language' => determine_locale(),
            'timezone' => wp_timezone_string(),
            'siteType' => implode(', ', $site_types),
            'owner' => array_filter([
                'name' => $text('name'),
                'phone' => $text('phone'),
                'city' => $text('city'),
                'state' => $text('state'),
                'country' => $country !== '' && class_exists('Locale') ? (string) \Locale::getDisplayRegion('-' . $country, 'en') : $country,
            ], static fn ($value) => (string) $value !== ''),
            'currency' => self::currency($details),
            'products' => self::products(),
            'bookings' => self::bookings(),
            'forms' => self::forms(),
        ], static fn ($value) => $value !== '' && $value !== []);
    }

    /** Published products by name, with their price when the shop keeps one: FluentCart, or WooCommerce. */
    private static function products(): array
    {
        global $wpdb;
        $type = post_type_exists('fluent-products') ? 'fluent-products' : (post_type_exists('product') ? 'product' : '');
        if ($type === '') {
            return [];
        }
        $posts = get_posts(['post_type' => $type, 'post_status' => 'publish', 'numberposts' => self::LIST_LIMIT, 'orderby' => 'menu_order title', 'order' => 'ASC']);
        if (!$posts) {
            return [];
        }
        $prices = [];
        if ($type === 'fluent-products' && self::table_exists($wpdb->prefix . 'fct_product_details')) {
            $ids = array_map('intval', wp_list_pluck($posts, 'ID'));
            // FluentCart keeps prices in cents.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT post_id, min_price, max_price FROM {$wpdb->prefix}fct_product_details WHERE post_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ')', ...$ids), ARRAY_A) as $row) {
                $min = (float) $row['min_price'] / 100;
                $max = (float) $row['max_price'] / 100;
                $prices[(int) $row['post_id']] = $max > $min ? self::money($min) . '–' . self::money($max) : self::money($min);
            }
        }
        $list = [];
        foreach ($posts as $post) {
            $price = $prices[$post->ID] ?? ($type === 'product' ? (string) get_post_meta($post->ID, '_price', true) : '');
            $list[] = array_filter(['name' => wp_strip_all_tags(get_the_title($post)), 'price' => $price], static fn ($value) => (string) $value !== '');
        }

        return $list;
    }

    /** Active booking types (FluentBooking), with how long each takes. */
    private static function bookings(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fcal_calendar_events';
        if (!self::table_exists($table)) {
            return [];
        }
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT title, duration FROM %i WHERE status = 'active' ORDER BY id ASC LIMIT %d", $table, self::LIST_LIMIT), ARRAY_A);

        return array_map(static fn ($row) => ['name' => (string) $row['title'], 'minutes' => (int) $row['duration']], $rows);
    }

    /** Published forms (Fluent Forms), by title. */
    private static function forms(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fluentform_forms';
        if (!self::table_exists($table)) {
            return [];
        }

        return array_map('strval', (array) $wpdb->get_col($wpdb->prepare("SELECT title FROM %i WHERE status = 'published' ORDER BY id ASC LIMIT %d", $table, self::LIST_LIMIT)));
    }

    /** The shop's currency, else the one the purchase's country uses. */
    private static function currency($details): string
    {
        $shop = get_option('fluent_cart_store_settings');
        if (is_array($shop) && !empty($shop['currency'])) {
            return strtoupper((string) $shop['currency']);
        }

        return is_array($details) ? (string) ($details['set']['currency'] ?? '') : '';
    }

    private static function table_exists(string $table): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    private static function money(float $amount): string
    {
        return floor($amount) === $amount ? (string) (int) $amount : number_format($amount, 2, '.', '');
    }
}

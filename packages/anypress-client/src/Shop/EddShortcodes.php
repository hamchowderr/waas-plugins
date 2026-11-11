<?php

namespace AnyPressClient\Shop;

use AnyPressClient\Forms\PageSwitcher;

/**
 * Swaps Easy Digital Downloads' shortcodes and blocks in a piece of content for FluentCart's. Plain string work, so
 * it can be tested without WordPress. A buy button needs the FluentCart product its download became: $products maps
 * EDD download ids to FluentCart product ids, and a button whose download isn't copied yet stays as it is.
 *
 * EDD's account shortcodes (purchase history, download history, profile editor) are all FluentCart's one customer
 * profile, so a page with several of them gets the profile once.
 */
final class EddShortcodes
{
    /** EDD shortcode => FluentCart shortcode (without brackets). null: needs the product (purchase_link). */
    private const SHORTCODES = [
        'download_checkout' => 'fluent_cart_checkout',
        'download_cart' => 'fluent_cart_cart',
        'purchase_history' => 'fluent_cart_customer_profile',
        'download_history' => 'fluent_cart_customer_profile',
        'edd_profile_editor' => 'fluent_cart_customer_profile',
        'edd_login' => 'fluent_cart_login_form',
        'edd_register' => 'fluent_cart_registration_form',
        'edd_receipt' => 'fluent_cart_receipt',
        'downloads' => 'fluent_cart_products',
        'purchase_link' => null,
    ];

    /** EDD blocks (from its own block library) => FluentCart shortcode. null: the buy button. */
    private const BLOCKS = [
        'edd/checkout' => 'fluent_cart_checkout',
        'edd/cart' => 'fluent_cart_cart',
        'edd/order-history' => 'fluent_cart_customer_profile',
        'edd/user-downloads' => 'fluent_cart_customer_profile',
        'edd/login' => 'fluent_cart_login_form',
        'edd/register' => 'fluent_cart_registration_form',
        'edd/receipt' => 'fluent_cart_receipt',
        'edd/confirmation' => 'fluent_cart_receipt',
        'edd/downloads' => 'fluent_cart_products',
        'edd/buy-button' => null,
    ];

    /** Text that shows EDD may be in a piece of content (for a quick database search). */
    public const NEEDLES = ['[download_', '[purchase_', '[edd_', '[downloads', 'wp:edd/'];

    /**
     * @param array<int, int> $products EDD download id => FluentCart product id.
     * @return array{content: string, replacements: array<int, array{from: string, to: string}>, missing: string[]}
     */
    public static function rewrite(string $content, array $products): array
    {
        $replacements = [];
        $missing = [];
        $profile = false;
        $swap = function (string $from, ?string $to) use (&$replacements, &$profile): string {
            if (strpos((string) $to, '[fluent_cart_customer_profile]') !== false) {
                if ($profile) {
                    $replacements[] = ['from' => $from, 'to' => ''];
                    return '';
                }
                $profile = true;
            }
            $replacements[] = ['from' => $from, 'to' => (string) $to];
            return (string) $to;
        };

        $content = (string) preg_replace_callback(
            '/<!--\s+wp:(' . implode('|', array_map(fn ($b) => preg_quote($b, '/'), array_keys(self::BLOCKS))) . ')(\s+\{.*?\})?\s+(?:\/-->|-->.*?<!--\s+\/wp:\1\s+-->)/s',
            function ($m) use ($products, $swap, &$missing) {
                $attrs = json_decode(trim($m[2] ?? ''), true);
                $attrs = is_array($attrs) ? $attrs : [];
                $to = self::target(self::BLOCKS[$m[1]], (int) ($attrs['download_id'] ?? 0), $products);
                if ($to === null) {
                    $missing[] = $m[0];
                    return $m[0];
                }
                return $swap($m[0], "<!-- wp:shortcode -->\n" . $to . "\n<!-- /wp:shortcode -->");
            },
            $content
        );

        $content = (string) preg_replace_callback(
            '/\[(\[?)(' . implode('|', array_map(fn ($t) => preg_quote($t, '/'), array_keys(self::SHORTCODES))) . ')(?=[\s\]\/])([^\]]*)\](\]?)/',
            function ($m) use ($products, $swap, &$missing) {
                if ($m[1] === '[' && $m[4] === ']') {
                    return $m[0];
                }
                $attrs = PageSwitcher::attributes(rtrim($m[3], '/ '));
                $to = self::target(self::SHORTCODES[$m[2]], (int) ($attrs['id'] ?? $attrs['download_id'] ?? 0), $products);
                if ($to === null) {
                    $missing[] = $m[0];
                    return $m[0];
                }
                return $m[1] . $swap($m[0], $to) . $m[4];
            },
            $content
        );

        return ['content' => $content, 'replacements' => $replacements, 'missing' => $missing];
    }

    /** The FluentCart shortcode for an EDD one, or null when it is a buy button whose download isn't copied. */
    private static function target(?string $shortcode, int $download, array $products): ?string
    {
        if ($shortcode !== null) {
            return '[' . $shortcode . ']';
        }
        $product = (int) ($products[$download] ?? 0);

        return $product > 0 ? '[fluent_cart_product_card product_id="' . $product . '"]' : null;
    }
}

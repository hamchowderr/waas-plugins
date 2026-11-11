<?php

namespace AnyPressClient\Shop;

use AnyPressClient\Api\SiteAbilities;

/**
 * The shop switch as WordPress abilities, next to the site-editing ones (same category, so the AnyPress website MCP
 * server offers them too): check-shop says what a switch would do and changes nothing; switch-shop does it, or with
 * undo takes its fixes back out. Both need an administrator (manage_options), and run as the signed-in user.
 */
class ShopAbilities
{
    private ShopSwitch $switch;

    public function __construct(ShopSwitch $switch)
    {
        $this->switch = $switch;
        add_action('wp_abilities_api_init', [$this, 'register']);
    }

    public function register(): void
    {
        $output = ['type' => 'object'];
        $permission = fn () => current_user_can('manage_options');

        wp_register_ability(SiteAbilities::CATEGORY . '/check-shop', [
            'label' => __('Check the shop switch', 'anypress-client'),
            'description' => __('Checks whether this website\'s WooCommerce shop (or, with from "edd", Easy Digital Downloads 3 shop) can move to FluentCart (WooCommerce, FluentCart and the FluentCart Migrator add-on active), and reports the order storage (HPOS), what\'s in the shop, whether subscriptions are in use, what the switch would add (gallery images, shipping zones, merged customers), the product tags and tax rates to check by hand, and WooCommerce compared with FluentCart. Changes nothing.', 'anypress-client'),
            'category' => SiteAbilities::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'from' => ['type' => 'string', 'enum' => ['woocommerce', 'edd'], 'default' => 'woocommerce', 'description' => 'The shop to check: WooCommerce or Easy Digital Downloads 3.'],
                ],
                'additionalProperties' => false,
                'default' => [],
            ],
            'output_schema' => $output,
            'execute_callback' => fn ($input = null) => $this->switch->run(true, self::from($input)),
            'permission_callback' => $permission,
            'meta' => [
                'show_in_rest' => true,
                'mcp' => ['public' => true, 'type' => 'tool'],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);

        wp_register_ability(SiteAbilities::CATEGORY . '/switch-shop', [
            'label' => __('Switch the shop to FluentCart', 'anypress-client'),
            'description' => __('Copies the WooCommerce shop (products, orders, customers, coupons, refunds) to FluentCart with the FluentCart Migrator add-on (with from "edd", an Easy Digital Downloads 3 shop with its subscriptions and licences, and swaps EDD\'s shortcodes and blocks on pages for FluentCart\'s), then adds what it leaves out: product gallery images, shipping zones and methods, and one customer per WordPress user. Lists the product tags and tax rates to check by hand (rates are never changed) and compares the two shops. Running it again adds nothing twice; a large shop that stops part way carries on where it stopped. WooCommerce is never changed or turned off. With undo, takes the gallery images, shipping zones and customer merges back out (what the add-on copied stays). Run check-shop first.', 'anypress-client'),
            'category' => SiteAbilities::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'undo' => ['type' => 'boolean', 'default' => false, 'description' => 'Take the switch\'s fixes back out instead.'],
                    'from' => ['type' => 'string', 'enum' => ['woocommerce', 'edd'], 'default' => 'woocommerce', 'description' => 'The shop to move: WooCommerce or Easy Digital Downloads 3.'],
                ],
                'additionalProperties' => false,
                'default' => [],
            ],
            'output_schema' => $output,
            'execute_callback' => fn ($input = null) => is_array($input) && !empty($input['undo']) ? $this->switch->undo(false, self::from($input)) : $this->switch->run(false, self::from($input)),
            'permission_callback' => $permission,
            'meta' => [
                'show_in_rest' => true,
                'mcp' => ['public' => true, 'type' => 'tool'],
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    private static function from($input): string
    {
        return is_array($input) && ($input['from'] ?? '') === 'edd' ? 'edd' : 'woocommerce';
    }
}

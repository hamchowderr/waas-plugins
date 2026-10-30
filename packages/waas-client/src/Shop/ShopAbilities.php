<?php

namespace WaaSClient\Shop;

use WaaSClient\Api\SiteAbilities;

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
            'label' => __('Check the shop switch', 'waas-client'),
            'description' => __('Checks whether this website\'s WooCommerce shop can move to FluentCart (WooCommerce, FluentCart and the FluentCart Migrator add-on active), and reports the order storage (HPOS), what\'s in the shop, whether subscriptions are in use, what the switch would add (gallery images, shipping zones, merged customers), the product tags and tax rates to check by hand, and WooCommerce compared with FluentCart. Changes nothing.', 'waas-client'),
            'category' => SiteAbilities::CATEGORY,
            'output_schema' => $output,
            'execute_callback' => fn () => $this->switch->run(true),
            'permission_callback' => $permission,
            'meta' => [
                'show_in_rest' => true,
                'mcp' => ['public' => true, 'type' => 'tool'],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);

        wp_register_ability(SiteAbilities::CATEGORY . '/switch-shop', [
            'label' => __('Switch the shop to FluentCart', 'waas-client'),
            'description' => __('Copies the WooCommerce shop (products, orders, customers, coupons, refunds) to FluentCart with the FluentCart Migrator add-on, then adds what it leaves out: product gallery images, shipping zones and methods, and one customer per WordPress user. Lists the product tags and tax rates to check by hand (rates are never changed) and compares the two shops. Running it again adds nothing twice; a large shop that stops part way carries on where it stopped. WooCommerce is never changed or turned off. With undo, takes the gallery images, shipping zones and customer merges back out (what the add-on copied stays). Run check-shop first.', 'waas-client'),
            'category' => SiteAbilities::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'undo' => ['type' => 'boolean', 'default' => false, 'description' => 'Take the switch\'s fixes back out instead.'],
                ],
                'additionalProperties' => false,
                'default' => [],
            ],
            'output_schema' => $output,
            'execute_callback' => fn ($input = null) => is_array($input) && !empty($input['undo']) ? $this->switch->undo() : $this->switch->run(),
            'permission_callback' => $permission,
            'meta' => [
                'show_in_rest' => true,
                'mcp' => ['public' => true, 'type' => 'tool'],
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }
}

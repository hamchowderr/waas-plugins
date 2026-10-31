<?php

namespace WaaSClient\Crm;

use WaaSClient\Api\SiteAbilities;

/**
 * The CRM switch as WordPress abilities, next to the site-editing ones (same category, so the AnyPress website MCP
 * server offers them too): check-crm says what a switch would do and changes nothing; switch-crm does it, or with
 * undo takes it back out. Both need an administrator (manage_options), and run as the signed-in user.
 */
class CrmAbilities
{
    private CrmSwitch $switch;

    public function __construct(CrmSwitch $switch)
    {
        $this->switch = $switch;
        add_action('wp_abilities_api_init', [$this, 'register']);
    }

    public function register(): void
    {
        $output = ['type' => 'object'];
        $permission = fn () => current_user_can('manage_options');
        $without_consent = [
            'type' => 'string',
            'enum' => CrmSwitch::WITHOUT_CONSENT,
            'default' => 'transactional',
            'description' => 'Status for buyers no source holds a consent record for: transactional (order emails only, never campaigns) or unsubscribed.',
        ];

        wp_register_ability(SiteAbilities::CATEGORY . '/check-crm', [
            'label' => __('Check the CRM switch', 'waas-client'),
            'description' => __('Checks whether this website\'s WooCommerce buyers (guests included), MailPoet subscribers and Newsletter plugin subscribers can be brought into FluentCRM, and reports how many contacts each source has, the status each would get and why (marketing consent), the tags, lists and fields that would be created, and what can\'t come across. Changes nothing.', 'waas-client'),
            'category' => SiteAbilities::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => ['without_consent' => $without_consent],
                'additionalProperties' => false,
                'default' => [],
            ],
            'output_schema' => $output,
            'execute_callback' => fn ($input = null) => $this->switch->run(true, self::without_consent($input)),
            'permission_callback' => $permission,
            'meta' => [
                'show_in_rest' => true,
                'mcp' => ['public' => true, 'type' => 'tool'],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);

        wp_register_ability(SiteAbilities::CATEGORY . '/switch-crm', [
            'label' => __('Bring buyers and subscribers into FluentCRM', 'waas-client'),
            'description' => __('Brings WooCommerce buyers (guests included; name, phone, billing address, order count and total spent, and a "Product: Past" tag per product bought), MailPoet subscribers and lists, and Newsletter plugin subscribers and lists into FluentCRM. Subscribes only people a source holds a consent record for; unsubscribed, bounced and complained stay that way. Sends no email. Running it again changes nothing already there; the source plugins are never changed. With undo, removes only the contacts, tags, lists and fields it created and puts back what it changed on contacts that were already there. Run check-crm first.', 'waas-client'),
            'category' => SiteAbilities::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'undo' => ['type' => 'boolean', 'default' => false, 'description' => 'Take the switch back out instead.'],
                    'without_consent' => $without_consent,
                ],
                'additionalProperties' => false,
                'default' => [],
            ],
            'output_schema' => $output,
            'execute_callback' => fn ($input = null) => is_array($input) && !empty($input['undo']) ? $this->switch->undo() : $this->switch->run(false, self::without_consent($input)),
            'permission_callback' => $permission,
            'meta' => [
                'show_in_rest' => true,
                'mcp' => ['public' => true, 'type' => 'tool'],
                // Not destructive: the switch only adds and fills in, and undo deletes only the contacts, tags,
                // lists and fields the switch itself created (as the shop switch's undo does). The REST run route
                // takes POST for it.
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    private static function without_consent($input): string
    {
        $value = is_array($input) ? (string) ($input['without_consent'] ?? '') : '';

        return in_array($value, CrmSwitch::WITHOUT_CONSENT, true) ? $value : 'transactional';
    }
}

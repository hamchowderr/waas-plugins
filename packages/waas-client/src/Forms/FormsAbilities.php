<?php

namespace WaaSClient\Forms;

use WaaSClient\Api\SiteAbilities;

/**
 * The forms switch as WordPress abilities, next to the site-editing ones (same category, so the AnyPress website MCP
 * server offers them too): check-forms says what a switch would do and changes nothing; switch-forms does it, or
 * with undo puts the old shortcodes back. Both need an administrator (manage_options), and run as the signed-in user.
 */
class FormsAbilities
{
    private FormsSwitch $switch;

    public function __construct(FormsSwitch $switch)
    {
        $this->switch = $switch;
        add_action('wp_abilities_api_init', [$this, 'register']);
    }

    public function register(): void
    {
        $output = ['type' => 'object'];
        $permission = fn () => current_user_can('manage_options');

        wp_register_ability(SiteAbilities::CATEGORY . '/check-forms', [
            'label' => __('Check the forms switch', 'waas-client'),
            'description' => __('Lists this website\'s Contact Form 7, WPForms and Ninja Forms forms and what switching them to Fluent Forms would do: the fields and settings that would be dropped, the entries that would come across, and the pages, templates and widgets whose form would be swapped. Changes nothing.', 'waas-client'),
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

        wp_register_ability(SiteAbilities::CATEGORY . '/switch-forms', [
            'label' => __('Switch the forms to Fluent Forms', 'waas-client'),
            'description' => __('Converts the Contact Form 7, WPForms and Ninja Forms forms and their entries to Fluent Forms, fixes what Fluent Forms\' importer gets wrong, and swaps the forms on pages, templates and widgets. Each changed page keeps a revision. Running it again changes nothing already done. With undo, puts the old shortcodes and blocks back (the Fluent Forms forms stay). The old plugins are never changed. Run check-forms first.', 'waas-client'),
            'category' => SiteAbilities::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'undo' => ['type' => 'boolean', 'default' => false, 'description' => 'Put the old shortcodes and blocks back instead.'],
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

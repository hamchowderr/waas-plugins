<?php

namespace AnyPressClient\Moving;

use AnyPressClient\Api\SiteAbilities;

/**
 * The support, affiliate, community, course and Circle switches as WordPress abilities, next to the site-editing ones (same
 * category, so the AnyPress website MCP server offers them too): check-<key> says what a switch would do and changes
 * nothing; switch-<key> does it, or with undo takes it back out. Both need an administrator (manage_options), and run
 * as the signed-in user.
 */
class ImportAbilities
{
    /** @var NativeImport[] */
    private array $imports;

    public function __construct(NativeImport ...$imports)
    {
        $this->imports = $imports;
        add_action('wp_abilities_api_init', [$this, 'register']);
    }

    public function register(): void
    {
        foreach ($this->imports as $import) {
            $this->register_one($import);
        }
    }

    private function register_one(NativeImport $import): void
    {
        $permission = fn () => current_user_can('manage_options');
        $from = ['type' => 'string', 'default' => '', 'description' => 'The source plugin, when more than one is active (check-' . $import->key() . ' lists them).'];
        $what = [
            'support' => __('help desk tickets from Awesome Support, SupportCandy or JS Help Desk into Fluent Support (replies, attachments, customers and agents)', 'anypress-client'),
            'affiliate' => __('affiliates from AffiliateWP, Affiliates Manager, Solid Affiliate, SliceWP or Ultimate Affiliate Pro into an empty FluentAffiliate (groups, referrals, customers, payouts and visits)', 'anypress-client'),
            'community' => __('a BuddyPress or BuddyBoss community into an empty FluentCommunity (groups as spaces with their members, posts, comments and reactions)', 'anypress-client'),
            'course' => __('LearnDash, Tutor LMS or LifterLMS courses into FluentCommunity courses (their outline as sections and lessons, students, the lessons each student finished and the courses they completed)', 'anypress-client'),
            'circle' => __('a Circle community into an empty FluentCommunity, through Circle\'s Admin API with the community\'s Admin V2 token (discussion spaces with their members and moderators, space groups, topics, posts, comments and replies, and course spaces as courses with their sections, lessons and students; members become WordPress users)', 'anypress-client'),
        ][$import->key()] ?? '';
        $labels = [
            'support' => [__('Check the support switch', 'anypress-client'), __('Bring help desk tickets into Fluent Support', 'anypress-client')],
            'affiliate' => [__('Check the affiliate switch', 'anypress-client'), __('Bring affiliates into FluentAffiliate', 'anypress-client')],
            'community' => [__('Check the community switch', 'anypress-client'), __('Bring a BuddyPress community into FluentCommunity', 'anypress-client')],
            'course' => [__('Check the course switch', 'anypress-client'), __('Bring LearnDash, Tutor LMS or LifterLMS courses into FluentCommunity', 'anypress-client')],
            'circle' => [__('Check the Circle switch', 'anypress-client'), __('Bring a Circle community into FluentCommunity', 'anypress-client')],
        ][$import->key()];

        wp_register_ability(SiteAbilities::CATEGORY . '/check-' . $import->key(), [
            'label' => $labels[0],
            /* translators: %s: what the switch brings across. */
            'description' => sprintf(__('Checks whether this website can bring %s, with the Fluent plugin\'s own importer, and reports what the source holds, what the Fluent plugin holds now, anything blocking it and what can\'t come across. Changes nothing.', 'anypress-client'), $what),
            'category' => SiteAbilities::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => ['from' => $from] + $import->inputs(),
                'additionalProperties' => false,
                'default' => [],
            ],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => fn ($input = null) => self::configured($import, $input)->run(true, self::from($input)),
            'permission_callback' => $permission,
            'meta' => [
                'show_in_rest' => true,
                'mcp' => ['public' => true, 'type' => 'tool'],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);

        wp_register_ability(SiteAbilities::CATEGORY . '/switch-' . $import->key(), [
            'label' => $labels[1],
            /* translators: %s: what the switch brings across. */
            'description' => sprintf(__('Brings %s with the Fluent plugin\'s own importer, holds back email while it runs, and reports what the source holds next to what came across. The source plugin is never changed. It runs once; with undo, removes only what it added. Run the check first.', 'anypress-client'), $what),
            'category' => SiteAbilities::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'from' => $from,
                    'undo' => ['type' => 'boolean', 'default' => false, 'description' => 'Take the switch back out instead.'],
                ] + $import->inputs(),
                'additionalProperties' => false,
                'default' => [],
            ],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => fn ($input = null) => is_array($input) && !empty($input['undo']) ? $import->undo() : self::configured($import, $input)->run(false, self::from($input)),
            'permission_callback' => $permission,
            'meta' => [
                'show_in_rest' => true,
                'mcp' => ['public' => true, 'type' => 'tool'],
                // Not destructive: the switch only adds, and undo deletes only what the switch itself added (as the
                // shop and CRM switches do). The REST run route takes POST for it.
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    /** Hands the switch its own input (such as a token) for this call. */
    private static function configured(NativeImport $import, $input): NativeImport
    {
        $import->configure(is_array($input) ? array_intersect_key($input, $import->inputs()) : []);

        return $import;
    }

    private static function from($input): string
    {
        return is_array($input) ? sanitize_key((string) ($input['from'] ?? '')) : '';
    }
}

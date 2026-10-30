<?php

namespace WaaSClient\Api;

use WP_Error;
use WP_REST_Request;

/**
 * The site-editing actions of SiteController as WordPress abilities (the Abilities API in WordPress 6.9+), so
 * WordPress's own tools can use them: the abilities REST API (wp-abilities/v1), the MCP Adapter plugin and AI
 * agents running inside the site. Each ability runs SiteController's code, so content is cleaned and revisions
 * are kept exactly as for the AnyPress connector. Unlike the signed routes, abilities act as the signed-in user
 * and need that user's own capabilities: edit_pages for pages, edit_theme_options for styles, header, footer
 * and menus, upload_files for images.
 */
class SiteAbilities
{
    public const CATEGORY = 'anypress';

    private SiteController $site;

    public function __construct(SiteController $site)
    {
        $this->site = $site;
        add_action('wp_abilities_api_categories_init', [$this, 'register_category']);
        add_action('wp_abilities_api_init', [$this, 'register_abilities']);
    }

    public function register_category(): void
    {
        wp_register_ability_category(self::CATEGORY, [
            'label' => __('Website design and content', 'waas-client'),
            'description' => __('Read and change this website\'s pages, starter patterns, colours and fonts, header, footer, menus and images.', 'waas-client'),
        ]);
    }

    public function register_abilities(): void
    {
        foreach ($this->abilities() as $name => $ability) {
            [$kind, $method, $capability] = $ability['run'];
            $schema = $ability['input'] ?? null;
            wp_register_ability(self::CATEGORY . '/' . $name, array_filter([
                'label' => $ability['label'],
                'description' => $ability['description'],
                'category' => self::CATEGORY,
                'input_schema' => $schema,
                'output_schema' => ['type' => $ability['output'] ?? 'object'],
                'execute_callback' => fn ($input = null) => $this->run($method, $input),
                'permission_callback' => fn ($input = null) => $this->allowed($capability, $input),
                'meta' => [
                    'show_in_rest' => true,
                    'mcp' => ['public' => true, 'type' => 'tool'],
                    'annotations' => self::ANNOTATIONS[$kind],
                ],
            ], fn ($value) => $value !== null));
        }
    }

    /**
     * read: changes nothing. add: adds something new. edit: changes something in place; WordPress keeps a revision,
     * so the change can be undone (restore-page-revision, or Revisions in the editor). remove: takes something away.
     */
    private const ANNOTATIONS = [
        'read' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        'add' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
        'edit' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
        'remove' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ];

    private function abilities(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1, 'description' => 'The page id, from get-site.'];
        $section = ['type' => 'integer', 'minimum' => 0, 'description' => 'The section number from the page outline (0 is the first section).'];
        $blocks = ['type' => 'string', 'description' => 'Block markup. Use colour, font and size slugs from get-styles.'];
        $pattern = ['type' => 'string', 'description' => 'A pattern name from find-patterns, used instead of content.'];
        $status = ['type' => 'string', 'enum' => ['draft', 'publish']];
        $object = fn (array $properties, array $required = []) => array_filter([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required ?: null,
            'additionalProperties' => false,
        ]);

        return [
            'get-site' => [
                'run' => ['read', 'site_overview', 'edit_pages'],
                'label' => 'Get the website overview',
                'description' => 'The website\'s name, tagline, address, theme and home page, and every page with its id, status and link. Start here.',
            ],
            'get-page' => [
                'run' => ['read', 'get_page', 'edit_pages'],
                'label' => 'Read a page',
                'description' => 'A page\'s outline (its numbered sections) and its block markup.',
                'input' => $object(['id' => $id], ['id']),
            ],
            'create-page' => [
                'run' => ['add', 'create_page', 'edit_pages'],
                'label' => 'Create a page',
                'description' => 'Adds a page. New pages are drafts unless status is publish. For a ready-made page, use a starter page\'s content from get-pattern.',
                'input' => $object(['title' => ['type' => 'string', 'minLength' => 1], 'content' => $blocks, 'status' => $status + ['default' => 'draft']], ['title']),
            ],
            'update-page' => [
                'run' => ['edit', 'update_page', 'edit_pages'],
                'label' => 'Update a page',
                'description' => 'Changes a page\'s title, address (slug), status or whole content. To change one part of a page, use the section abilities instead.',
                'input' => $object(['id' => $id, 'title' => ['type' => 'string', 'minLength' => 1], 'slug' => ['type' => 'string'], 'status' => $status, 'content' => $blocks], ['id']),
            ],
            'add-page-section' => [
                'run' => ['add', 'insert_section', 'edit_pages'],
                'label' => 'Add a section to a page',
                'description' => 'Adds a section (content or a pattern) before section number position, or at the end of the page when position is left out.',
                'input' => $object(['id' => $id, 'content' => $blocks, 'pattern' => $pattern, 'position' => $section], ['id']),
            ],
            'replace-page-section' => [
                'run' => ['edit', 'replace_section', 'edit_pages'],
                'label' => 'Replace a section of a page',
                'description' => 'Puts new content, or a pattern, in place of one section of a page.',
                'input' => $object(['id' => $id, 'section' => $section, 'content' => $blocks, 'pattern' => $pattern], ['id', 'section']),
            ],
            'remove-page-section' => [
                'run' => ['remove', 'remove_section', 'edit_pages'],
                'label' => 'Remove a section from a page',
                'description' => 'Takes one section off a page; later sections move up one number. The page\'s revisions keep the old version.',
                'input' => $object(['id' => $id, 'section' => $section], ['id', 'section']),
            ],
            'list-page-revisions' => [
                'run' => ['read', 'list_revisions', 'edit_pages'],
                'label' => 'List a page\'s revisions',
                'description' => 'Earlier versions of a page, newest first, with each one\'s outline.',
                'input' => $object(['id' => $id], ['id']),
                'output' => 'array',
            ],
            'restore-page-revision' => [
                'run' => ['edit', 'restore_revision', 'edit_pages'],
                'label' => 'Restore a page revision',
                'description' => 'Puts a page back the way it was in one of its revisions (undo). The version it replaces is kept as a revision too.',
                'input' => $object(['id' => $id, 'revision' => ['type' => 'integer', 'minimum' => 1, 'description' => 'A revision id from list-page-revisions.']], ['id', 'revision']),
            ],
            'find-patterns' => [
                'run' => ['read', 'list_patterns', 'edit_pages'],
                'label' => 'Find patterns',
                'description' => 'Ready-made sections and pages from the theme and this website. Category anypress/pages lists the starter pages.',
                'input' => $object(['category' => ['type' => 'string'], 'search' => ['type' => 'string']]) + ['default' => []],
                'output' => 'array',
            ],
            'get-pattern' => [
                'run' => ['read', 'get_pattern', 'edit_pages'],
                'label' => 'Read a pattern',
                'description' => 'A pattern\'s block markup, to use as a page\'s content or as a section.',
                'input' => $object(['name' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]+/[a-z0-9_-]+$']], ['name']),
            ],
            'get-styles' => [
                'run' => ['read', 'get_styles', 'edit_theme_options'],
                'label' => 'Read the colours and fonts',
                'description' => 'The colour palette, fonts, font sizes and spacing sizes to use in blocks (by slug), the current styles, and this website\'s own style changes.',
            ],
            'update-styles' => [
                'run' => ['edit', 'update_styles', 'edit_theme_options'],
                'label' => 'Change the colours and fonts',
                'description' => 'Merges settings and/or styles, in theme.json form, into this website\'s styles (what Styles in the Site Editor saves). For example styles.color.background or settings.color.palette.custom.',
                'input' => $object(['settings' => ['type' => 'object'], 'styles' => ['type' => 'object']]),
            ],
            'get-site-part' => [
                'run' => ['read', 'get_part', 'edit_theme_options'],
                'label' => 'Read the header or footer',
                'description' => 'The header or footer\'s outline and block markup.',
                'input' => $object(['area' => ['type' => 'string', 'enum' => ['header', 'footer']]], ['area']),
            ],
            'update-site-part' => [
                'run' => ['edit', 'update_part', 'edit_theme_options'],
                'label' => 'Change the header or footer',
                'description' => 'Replaces the whole header or footer with new block markup, saved as this website\'s own copy of the theme\'s part.',
                'input' => $object(['area' => ['type' => 'string', 'enum' => ['header', 'footer']], 'content' => $blocks], ['area', 'content']),
            ],
            'list-menus' => [
                'run' => ['read', 'list_menus', 'edit_theme_options'],
                'label' => 'List the menus',
                'description' => 'The website\'s navigation menus with their links, as blocks.',
                'output' => 'array',
            ],
            'update-menu' => [
                'run' => ['edit', 'update_menu', 'edit_theme_options'],
                'label' => 'Change a menu',
                'description' => 'Replaces a menu\'s links (navigation-link and navigation-submenu blocks) and optionally its title.',
                'input' => $object(['id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'A menu id from list-menus.'], 'content' => $blocks, 'title' => ['type' => 'string']], ['id', 'content']),
            ],
            'add-image' => [
                'run' => ['add', 'add_media', 'upload_files'],
                'label' => 'Add an image',
                'description' => 'Copies an image from a public https link into the media library and returns its id and address for image blocks.',
                'input' => $object(['url' => ['type' => 'string', 'format' => 'uri'], 'alt' => ['type' => 'string', 'description' => 'Alt text describing the image.']], ['url']),
            ],
        ];
    }

    /** The signed-in user's own capability; for one page, also that page (edit_post). */
    private function allowed(string $capability, $input)
    {
        if (!current_user_can($capability)) {
            return false;
        }
        $page = is_array($input) ? ($input['id'] ?? null) : null;
        // A page that doesn't exist is left to the action, which answers that no page has that id.
        if ($capability === 'edit_pages' && $page !== null && get_post((int) $page)) {
            return current_user_can('edit_post', (int) $page);
        }

        return true;
    }

    /** Runs a SiteController action with the ability's input as the request's parameters. */
    private function run(string $method, $input)
    {
        if ($method === 'site_overview') {
            return $this->site->site() + ['pages' => $this->site->list_pages()];
        }
        $request = new WP_REST_Request();
        foreach (is_array($input) ? $input : [] as $key => $value) {
            $request->set_param($key, $value);
        }
        $result = $this->site->{$method}($request);

        return $result instanceof WP_Error || is_array($result) ? $result : new WP_Error('waas_site_failed', 'That did not work.', ['status' => 500]);
    }
}

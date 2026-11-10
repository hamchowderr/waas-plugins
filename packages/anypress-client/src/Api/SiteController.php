<?php

namespace AnyPressClient\Api;

use AnyPressClient\Core\BusinessProfile;
use AnyPressClient\Core\SignedRequest;
use AnyPressClient\Core\SiteSections;
use AnyPressClient\Features\PluginBootstrap;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * The store's design and content, for the AnyPress connector: pages and their sections, the theme's patterns,
 * colours and fonts, the header and footer, menus, revisions and images. Only the AnyPress API can call it: every
 * request must carry a signature made with the store's site key (Core\SignedRequest), and runs as the store's
 * first administrator. Content goes through WordPress's block parser and post HTML filter; nothing here runs code,
 * installs anything or changes settings outside design and content. WordPress saves a revision of every change.
 * The design routes (patterns, styles, header and footer, menus, images) are in the SiteDesignRoutes trait, and the
 * shop's products (FluentCart, written by its own importer) in SiteProductRoutes.
 */
class SiteController
{
    use SiteDesignRoutes;
    use SiteProductRoutes;

    public const ROUTE = '/site';
    private const ID = '(?P<id>\d+)';
    private const SECTION = '(?P<section>\d+)';
    private const PART_AREAS = ['header', 'footer'];

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public function register_rest_routes(): void
    {
        $routes = [
            ['', WP_REST_Server::READABLE, 'site'],
            ['/pages', WP_REST_Server::READABLE, 'list_pages'],
            ['/pages', WP_REST_Server::CREATABLE, 'create_page'],
            ['/pages/' . self::ID, WP_REST_Server::READABLE, 'get_page'],
            ['/pages/' . self::ID, WP_REST_Server::EDITABLE, 'update_page'],
            ['/pages/' . self::ID . '/sections', WP_REST_Server::CREATABLE, 'insert_section'],
            ['/pages/' . self::ID . '/sections/' . self::SECTION, WP_REST_Server::EDITABLE, 'replace_section'],
            ['/pages/' . self::ID . '/sections/' . self::SECTION, WP_REST_Server::DELETABLE, 'remove_section'],
            ['/pages/' . self::ID . '/revisions', WP_REST_Server::READABLE, 'list_revisions'],
            ['/pages/' . self::ID . '/revisions/(?P<revision>\d+)/restore', WP_REST_Server::CREATABLE, 'restore_revision'],
            ['/patterns', WP_REST_Server::READABLE, 'list_patterns'],
            ['/patterns/(?P<name>[a-z0-9_-]+/[a-z0-9_-]+)', WP_REST_Server::READABLE, 'get_pattern'],
            ['/styles', WP_REST_Server::READABLE, 'get_styles'],
            ['/styles', WP_REST_Server::EDITABLE, 'update_styles'],
            ['/parts/(?P<area>header|footer)', WP_REST_Server::READABLE, 'get_part'],
            ['/parts/(?P<area>header|footer)', WP_REST_Server::EDITABLE, 'update_part'],
            ['/menus', WP_REST_Server::READABLE, 'list_menus'],
            ['/menus/' . self::ID, WP_REST_Server::EDITABLE, 'update_menu'],
            ['/media', WP_REST_Server::CREATABLE, 'add_media'],
            ['/products', WP_REST_Server::READABLE, 'list_products'],
            ['/products', WP_REST_Server::CREATABLE, 'add_products'],
            ['/business', WP_REST_Server::READABLE, 'get_business'],
            ['/business', WP_REST_Server::EDITABLE, 'update_business'],
            ['/business/undo', WP_REST_Server::CREATABLE, 'undo_business'],
        ];
        foreach ($routes as [$path, $methods, $callback]) {
            register_rest_route(PluginBootstrap::API_V1_NAMESPACE, self::ROUTE . $path, [
                'methods' => $methods,
                'callback' => [$this, $callback],
                'permission_callback' => [$this, 'authorize'],
            ]);
        }
    }

    /** Signed by the AnyPress API with this store's site key; then acts as the first administrator. */
    public function authorize(WP_REST_Request $request)
    {
        $signed = SignedRequest::verify(
            SignedRequest::key(),
            $request->get_header(SignedRequest::HEADER),
            $request->get_method(),
            SignedRequest::route($request->get_route(), (array) $request->get_query_params()),
            (string) $request->get_body()
        );
        if (!$signed) {
            return new WP_Error('anypress_site_unauthorized', 'This request is not signed by AnyPress.', ['status' => 401]);
        }

        $admins = get_users(['role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID']);
        if (!$admins) {
            return new WP_Error('anypress_site_no_admin', 'This site has no administrator to act as.', ['status' => 409]);
        }
        wp_set_current_user((int) $admins[0]);

        return true;
    }

    public function site(): array
    {
        $front = get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0;

        return [
            'name' => get_bloginfo('name'),
            'tagline' => get_bloginfo('description'),
            'url' => home_url('/'),
            'theme' => wp_get_theme()->get('Name'),
            'homePage' => $front ?: null,
            'homeShows' => $front ? 'page' : 'latest posts',
        ];
    }

    /** What the site says about its business, and what its owner has told the assistant (Core\BusinessProfile). */
    public function get_business(): array
    {
        return BusinessProfile::read();
    }

    /** { about: { what?, who?, area?, hours?, contact?, voice?, notes? } } Only the fields given change; "" clears one. */
    public function update_business(WP_REST_Request $request)
    {
        $about = $request->get_param('about');
        if (!is_array($about) || !array_intersect_key($about, BusinessProfile::FIELDS)) {
            return $this->bad('Send about with at least one of: ' . implode(', ', array_keys(BusinessProfile::FIELDS)) . '.');
        }

        return BusinessProfile::save($about);
    }

    /** Puts back what the owner's facts were before the last save. */
    public function undo_business(): array
    {
        return BusinessProfile::undo();
    }

    public function list_pages(): array
    {
        $pages = get_posts(['post_type' => 'page', 'post_status' => ['publish', 'draft', 'private', 'pending'], 'numberposts' => 200, 'orderby' => 'menu_order title', 'order' => 'ASC']);

        return array_map([$this, 'page_summary'], $pages);
    }

    public function get_page(WP_REST_Request $request)
    {
        $page = $this->page($request);
        if (is_wp_error($page)) {
            return $page;
        }

        return $this->page_details($page);
    }

    /** { title, content?, status?: draft|publish } New pages are drafts unless asked otherwise. */
    public function create_page(WP_REST_Request $request)
    {
        $title = sanitize_text_field((string) $request->get_param('title'));
        if ($title === '') {
            return $this->bad('Give the page a title.');
        }
        $content = $this->clean((string) ($request->get_param('content') ?? ''));
        if (is_wp_error($content)) {
            return $content;
        }
        $id = wp_insert_post([
            'post_type' => 'page',
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => $this->status($request->get_param('status'), 'draft'),
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ], true);

        return is_wp_error($id) ? $id : $this->page_summary(get_post($id));
    }

    /** { title?, content?, status?: draft|publish, slug? } */
    public function update_page(WP_REST_Request $request)
    {
        $page = $this->page($request);
        if (is_wp_error($page)) {
            return $page;
        }
        $changes = ['ID' => $page->ID];
        if ($request->get_param('title') !== null) {
            $changes['post_title'] = sanitize_text_field((string) $request->get_param('title'));
        }
        if ($request->get_param('slug') !== null) {
            $changes['post_name'] = sanitize_title((string) $request->get_param('slug'));
        }
        if ($request->get_param('status') !== null) {
            $changes['post_status'] = $this->status($request->get_param('status'), $page->post_status);
        }
        if ($request->get_param('content') !== null) {
            $content = $this->clean((string) $request->get_param('content'));
            if (is_wp_error($content)) {
                return $content;
            }
            $changes['post_content'] = $content;
        }

        return $this->save_page($changes);
    }

    /** { content } or { pattern }, plus position (a section number to insert before; leave out to add at the end). */
    public function insert_section(WP_REST_Request $request)
    {
        $page = $this->page($request);
        $new = is_wp_error($page) ? $page : $this->section_blocks($request);
        if (is_wp_error($new)) {
            return $new;
        }
        $position = $request->get_param('position');
        $blocks = SiteSections::insert(parse_blocks($page->post_content), $position === null ? null : (int) $position, $new);
        if ($blocks === null) {
            return $this->bad('position must be a section number (0 is the first section).');
        }

        return $this->save_page(['ID' => $page->ID, 'post_content' => serialize_blocks($blocks)]);
    }

    /** { content } or { pattern } in place of section N. */
    public function replace_section(WP_REST_Request $request)
    {
        $page = $this->page($request);
        $new = is_wp_error($page) ? $page : $this->section_blocks($request);
        if (is_wp_error($new)) {
            return $new;
        }
        $blocks = SiteSections::replace(parse_blocks($page->post_content), (int) $request->get_param('section'), $new);

        return $blocks === null ? $this->no_section() : $this->save_page(['ID' => $page->ID, 'post_content' => serialize_blocks($blocks)]);
    }

    public function remove_section(WP_REST_Request $request)
    {
        $page = $this->page($request);
        if (is_wp_error($page)) {
            return $page;
        }
        $blocks = SiteSections::remove(parse_blocks($page->post_content), (int) $request->get_param('section'));

        return $blocks === null ? $this->no_section() : $this->save_page(['ID' => $page->ID, 'post_content' => serialize_blocks($blocks)]);
    }

    public function list_revisions(WP_REST_Request $request)
    {
        $page = $this->page($request);
        if (is_wp_error($page)) {
            return $page;
        }

        return array_values(array_map(fn ($revision) => [
            'id' => $revision->ID,
            'date' => $this->time($revision),
            'title' => $revision->post_title,
            'outline' => SiteSections::outline(parse_blocks($revision->post_content)),
        ], wp_get_post_revisions($page->ID, ['posts_per_page' => 20])));
    }

    public function restore_revision(WP_REST_Request $request)
    {
        $page = $this->page($request);
        if (is_wp_error($page)) {
            return $page;
        }
        $revision_id = (int) $request->get_param('revision');
        $revision = wp_get_post_revision($revision_id);
        if (!$revision || (int) $revision->post_parent !== $page->ID) {
            return new WP_Error('anypress_site_not_found', 'That page has no such revision.', ['status' => 404]);
        }
        wp_restore_post_revision($revision->ID);

        return $this->page_summary(get_post($page->ID));
    }

    private function page(WP_REST_Request $request)
    {
        $page = get_post((int) $request->get_param('id'));
        if (!$page || $page->post_type !== 'page' || $page->post_status === 'trash') {
            return new WP_Error('anypress_site_not_found', 'No page has that id.', ['status' => 404]);
        }

        return $page;
    }

    private function page_summary($page): array
    {
        return [
            'id' => $page->ID,
            'title' => $page->post_title,
            'slug' => $page->post_name,
            'status' => $page->post_status,
            'link' => $page->post_status === 'publish' ? get_permalink($page) : get_preview_post_link($page),
            'modified' => $this->time($page),
        ];
    }

    private function page_details($page): array
    {
        return $this->page_summary($page) + [
            'outline' => SiteSections::outline(parse_blocks($page->post_content)),
            'content' => $page->post_content,
        ];
    }

    private function time($post): ?string
    {
        // A new draft has no GMT times yet (WordPress fills them in on publish); its local time is still right.
        $time = get_post_datetime($post, 'modified', 'gmt') ?: get_post_datetime($post, 'modified');

        return $time ? $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z') : null;
    }

    /**
     * wp_update_post, after keeping the post as it is now when it has no revision yet. WordPress saves a revision
     * of each new state, not of the one it replaces, so without this the first change to a page the template or
     * create_page made (or to the styles, header or a menu) could never be undone.
     */
    private function update_post(array $changes)
    {
        if (!wp_get_post_revisions($changes['ID'], ['posts_per_page' => 1, 'fields' => 'ids'])) {
            wp_save_post_revision($changes['ID']);
        }

        return wp_update_post($changes, true);
    }

    private function save_page(array $changes)
    {
        $saved = $this->update_post($changes);

        return is_wp_error($saved) ? $saved : $this->page_details(get_post($saved));
    }

    /** The section to add: { pattern } (a pattern's name) or { content } (blocks). */
    private function section_blocks(WP_REST_Request $request)
    {
        $name = $request->get_param('pattern');
        if ($name !== null) {
            $pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered((string) $name);
            if (!$pattern) {
                return new WP_Error('anypress_site_not_found', 'No pattern has that name.', ['status' => 404]);
            }
            $content = (string) $pattern['content'];
        } else {
            $content = $this->clean((string) $request->get_param('content'));
            if (is_wp_error($content)) {
                return $content;
            }
        }
        $blocks = parse_blocks($content);
        if (!SiteSections::positions($blocks)) {
            return $this->bad('The section is empty.');
        }

        return $blocks;
    }

    /** Block markup through WordPress's post HTML filter, which also checks block attributes (what an author without unfiltered_html gets). */
    private function clean(string $content)
    {
        if (strlen($content) > 512000) {
            return $this->bad('That content is too long (500 KB at most).');
        }

        return wp_kses_post($content);
    }

    private function status($status, string $default): string
    {
        return in_array($status, ['draft', 'publish'], true) ? $status : $default;
    }

    private function no_section(): WP_Error
    {
        return new WP_Error('anypress_site_not_found', 'That page has no such section. Read the page for its outline.', ['status' => 404]);
    }

    private function bad(string $message): WP_Error
    {
        return new WP_Error('anypress_site_bad_request', $message, ['status' => 400]);
    }
}

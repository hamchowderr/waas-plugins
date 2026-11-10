<?php

namespace AnyPressClient\Api;

use AnyPressClient\Core\SiteSections;
use WP_Error;
use WP_REST_Request;

/**
 * SiteController's design routes: the theme's patterns, the colours and fonts (global styles), the header and footer,
 * menus and images. Content is cleaned and saved with a revision by SiteController's helpers.
 */
trait SiteDesignRoutes
{
    /** ?category=&search= Patterns the Site Editor offers, without their markup (get_pattern has it). */
    public function list_patterns(WP_REST_Request $request): array
    {
        $category = (string) ($request->get_param('category') ?? '');
        $search = strtolower((string) ($request->get_param('search') ?? ''));
        $list = [];
        foreach (\WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern) {
            if (($pattern['inserter'] ?? true) === false) {
                continue;
            }
            $categories = $pattern['categories'] ?? [];
            if ($category !== '' && !in_array($category, $categories, true)) {
                continue;
            }
            $haystack = strtolower($pattern['title'] . ' ' . ($pattern['description'] ?? '') . ' ' . implode(' ', $pattern['keywords'] ?? []));
            if ($search !== '' && strpos($haystack, $search) === false) {
                continue;
            }
            $list[] = array_filter([
                'name' => $pattern['name'],
                'title' => $pattern['title'],
                'description' => $pattern['description'] ?? null,
                'categories' => $categories,
            ]);
        }

        return $list;
    }

    public function get_pattern(WP_REST_Request $request)
    {
        $pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered((string) $request->get_param('name'));
        if (!$pattern) {
            return new WP_Error('anypress_site_not_found', 'No pattern has that name.', ['status' => 404]);
        }

        return ['name' => $pattern['name'], 'title' => $pattern['title'], 'categories' => $pattern['categories'] ?? [], 'content' => $pattern['content']];
    }

    /** The colours, fonts and sizes to use (use their slugs in blocks), and the site's current style changes. */
    public function get_styles(): array
    {
        $id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
        $user = json_decode((string) get_post_field('post_content', $id), true) ?: [];

        // The theme's presets and the site's own, without WordPress's generic defaults.
        $presets = fn (array $path) => array_diff_key((array) wp_get_global_settings($path), ['default' => true]);

        return [
            'palette' => $presets(['color', 'palette']),
            'fontFamilies' => $presets(['typography', 'fontFamilies']),
            'fontSizes' => $presets(['typography', 'fontSizes']),
            'spacingSizes' => $presets(['spacing', 'spacingSizes']),
            'styles' => wp_get_global_styles(),
            'siteChanges' => ['settings' => $user['settings'] ?? new \stdClass(), 'styles' => $user['styles'] ?? new \stdClass()],
        ];
    }

    /**
     * { settings?, styles? } in theme.json form, merged into the site's style changes (what Styles in the Site
     * Editor saves). For example settings.color.palette.custom, styles.color.background, styles.elements.button.
     */
    public function update_styles(WP_REST_Request $request)
    {
        $settings = $request->get_param('settings');
        $styles = $request->get_param('styles');
        if (!is_array($settings ?? []) || !is_array($styles ?? []) || ($settings === null && $styles === null)) {
            return $this->bad('Send settings and/or styles as objects, in theme.json form.');
        }
        $id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
        $current = json_decode((string) get_post_field('post_content', $id), true) ?: [];
        $next = array_replace_recursive($current, array_filter(['settings' => $settings, 'styles' => $styles], fn ($v) => $v !== null));
        $next['version'] = \WP_Theme_JSON::LATEST_SCHEMA;
        // The same clean-up WordPress applies when Styles are saved in the Site Editor.
        $next = \WP_Theme_JSON::remove_insecure_properties($next, 'custom');
        $next['version'] = \WP_Theme_JSON::LATEST_SCHEMA;
        $next['isGlobalStylesUserThemeJSON'] = true;
        $saved = $this->update_post(['ID' => $id, 'post_content' => wp_slash(wp_json_encode($next))]);
        if (is_wp_error($saved)) {
            return $saved;
        }
        \WP_Theme_JSON_Resolver::clean_cached_data();

        return $this->get_styles();
    }

    public function get_part(WP_REST_Request $request)
    {
        $part = get_block_template(get_stylesheet() . '//' . $request->get_param('area'), 'wp_template_part');
        if (!$part) {
            return new WP_Error('anypress_site_not_found', 'This theme has no such part.', ['status' => 404]);
        }

        return [
            'area' => $request->get_param('area'),
            'customised' => $part->source === 'custom',
            'outline' => SiteSections::outline(parse_blocks($part->content)),
            'content' => $part->content,
        ];
    }

    /** { content } The whole header or footer, as blocks. Saved as the site's own copy of the theme's part. */
    public function update_part(WP_REST_Request $request)
    {
        $area = (string) $request->get_param('area');
        if (!in_array($area, self::PART_AREAS, true)) {
            return $this->bad('Only the header and footer can be changed.');
        }
        $content = $this->clean((string) $request->get_param('content'));
        if (is_wp_error($content)) {
            return $content;
        }
        $part = get_block_template(get_stylesheet() . '//' . $area, 'wp_template_part');
        if ($part && $part->wp_id) {
            $saved = $this->update_post(['ID' => $part->wp_id, 'post_content' => $content]);
        } else {
            $saved = wp_insert_post(['post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_name' => $area, 'post_title' => ucfirst($area), 'post_content' => $content], true);
            if (!is_wp_error($saved)) {
                wp_set_object_terms($saved, get_stylesheet(), 'wp_theme');
                wp_set_object_terms($saved, $area, 'wp_template_part_area');
            }
        }

        return is_wp_error($saved) ? $saved : $this->get_part($request);
    }

    public function list_menus(): array
    {
        return array_map(fn ($menu) => [
            'id' => $menu->ID,
            'title' => $menu->post_title,
            'content' => $menu->post_content,
        ], get_posts(['post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 50, 'orderby' => 'date', 'order' => 'DESC']));
    }

    /** { content } navigation-link (and navigation-submenu) blocks; { title } optional. */
    public function update_menu(WP_REST_Request $request)
    {
        $menu = get_post((int) $request->get_param('id'));
        if (!$menu || $menu->post_type !== 'wp_navigation') {
            return new WP_Error('anypress_site_not_found', 'No menu has that id.', ['status' => 404]);
        }
        $content = $this->clean((string) $request->get_param('content'));
        if (is_wp_error($content)) {
            return $content;
        }
        $changes = ['ID' => $menu->ID, 'post_content' => $content];
        if ($request->get_param('title') !== null) {
            $changes['post_title'] = sanitize_text_field((string) $request->get_param('title'));
        }
        $saved = $this->update_post($changes);

        return is_wp_error($saved) ? $saved : ['id' => $menu->ID, 'title' => get_the_title($menu->ID), 'content' => get_post_field('post_content', $menu->ID)];
    }

    /** { url, alt? } Copies an image from a public https link into the media library. */
    public function add_media(WP_REST_Request $request)
    {
        $url = esc_url_raw((string) $request->get_param('url'), ['https']);
        if ($url === '' || !wp_http_validate_url($url)) {
            return $this->bad('Give a public https link to an image.');
        }
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $alt = sanitize_text_field((string) ($request->get_param('alt') ?? ''));
        $id = media_sideload_image($url, 0, $alt ?: null, 'id');
        if (is_wp_error($id)) {
            return $id;
        }
        if ($alt !== '') {
            update_post_meta($id, '_wp_attachment_image_alt', $alt);
        }

        return ['id' => $id, 'url' => wp_get_attachment_url($id), 'alt' => $alt];
    }
}

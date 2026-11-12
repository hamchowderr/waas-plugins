<?php

namespace AnyPressClient\Features;

/**
 * The block editor on a store, without the parts that get in an owner's way:
 *
 * - WordPress's one-time welcome guides ("Welcome to the editor", the Site Editor's "Edit your site" and the Styles,
 *   page and template guides) start switched off. They stay in the editor's Options menu (Welcome guide), and what
 *   an owner switches back on is kept, because only the defaults change.
 * - The "Choose a pattern" window on a new page lists the starter pages of this website's own kit and the shared
 *   ones, not every kit's (another kit's starter pages are about another kind of business). The other kits' pages
 *   are still in the inserter under Pages and their kit; they only leave the new-page window.
 *
 * Admin and REST requests only, so the front end of the website does no extra work.
 */
class StoreEditor
{
    /** Kit starter page titles end in "Assay kit" or "Circuit kit, electricians"; this finds the kit's name. */
    private const KIT_IN_TITLE = '/:\s*([A-Z][a-z]+) kit\b/';

    private bool $patterns_done = false;

    public function __construct()
    {
        add_action('enqueue_block_editor_assets', [$this, 'welcome_guides_off']);
        add_action('admin_init', [$this, 'kit_starter_pages'], PHP_INT_MAX);
        add_action('rest_api_init', [$this, 'kit_starter_pages'], PHP_INT_MAX);
    }

    /**
     * The guides' defaults are set after WordPress's own (the editor sets them when it starts, on DOM ready), so
     * this runs after every DOM-ready callback.
     */
    public function welcome_guides_off(): void
    {
        $defaults = [
            'core/edit-post' => ['welcomeGuide' => false, 'welcomeGuideTemplate' => false],
            'core/edit-site' => ['welcomeGuide' => false, 'welcomeGuideStyles' => false, 'welcomeGuidePage' => false, 'welcomeGuideTemplate' => false],
            'core/edit-widgets' => ['welcomeGuide' => false],
            'core/customize-widgets' => ['welcomeGuide' => false],
        ];
        $script = '(function(wp,d){if(!wp||!wp.data||!wp.domReady){return;}var set=function(){var p=wp.data.dispatch("core/preferences");if(!p||!p.setDefaults){return;}Object.keys(d).forEach(function(s){p.setDefaults(s,d[s]);});};set();wp.domReady(function(){setTimeout(set,0);});})(window.wp,' . wp_json_encode($defaults) . ');';
        wp_register_script('anypress-store-editor', false, ['wp-data', 'wp-dom-ready', 'wp-preferences'], ANYPRESS_CLIENT_VERSION, ['in_footer' => true]);
        wp_enqueue_script('anypress-store-editor');
        wp_add_inline_script('anypress-store-editor', $script);
    }

    /** Takes other kits' starter pages out of the new-page window (see the class comment). */
    public function kit_starter_pages(): void
    {
        if ($this->patterns_done || !class_exists('WP_Block_Patterns_Registry')) {
            return;
        }
        $this->patterns_done = true;
        $registry = \WP_Block_Patterns_Registry::get_instance();
        $kit = self::active_kit();
        foreach ($registry->get_all_registered() as $pattern) {
            $types = (array) ($pattern['blockTypes'] ?? []);
            if (!in_array('core/post-content', $types, true)) {
                continue;
            }
            if (!self::is_other_kit((string) ($pattern['title'] ?? ''), $kit)) {
                continue;
            }
            $name = (string) $pattern['name'];
            $pattern['blockTypes'] = array_values(array_diff($types, ['core/post-content']));
            unset($pattern['name']);
            $registry->unregister($name);
            $registry->register($name, $pattern);
        }
    }

    /** Whether a starter page belongs to a kit other than this website's ($kit '' when no kit's look is on). */
    public static function is_other_kit(string $title, string $kit): bool
    {
        if (!preg_match(self::KIT_IN_TITLE, $title, $match)) {
            return false;
        }

        return strcasecmp($match[1], $kit) !== 0;
    }

    /**
     * The kit whose look this website uses, by name ("Circuit"), or ''. The site tools save the kit's title
     * ("Circuit (electricians)") in the site's styles; the Site Editor's Styles may not, so a kit is also found by
     * its colours: the site's palette is the kit's own.
     */
    public static function active_kit(): string
    {
        if (!class_exists('WP_Theme_JSON_Resolver')) {
            return '';
        }
        $id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
        $styles = $id ? json_decode((string) get_post_field('post_content', $id), true) : null;
        if (!is_array($styles)) {
            return '';
        }
        $title = (string) ($styles['title'] ?? '');
        $palette = self::palette($styles['settings']['color']['palette'] ?? []);
        foreach (\WP_Theme_JSON_Resolver::get_style_variations() as $variation) {
            if (strpos((string) ($variation['slug'] ?? ''), 'kit-') !== 0) {
                continue;
            }
            $theirs = self::palette($variation['settings']['color']['palette'] ?? []);
            if (($title !== '' && ($variation['title'] ?? '') === $title) || ($palette !== [] && $palette === $theirs)) {
                return (string) strtok((string) $variation['title'], ' (');
            }
        }

        return '';
    }

    /** A palette as slug => colour, from a theme file's list or the site's saved {"theme": [...]}. */
    private static function palette($palette): array
    {
        $list = is_array($palette) && isset($palette['theme']) ? $palette['theme'] : $palette;
        $out = [];
        foreach ((array) $list as $color) {
            if (is_array($color) && isset($color['slug'], $color['color'])) {
                $out[(string) $color['slug']] = strtolower((string) $color['color']);
            }
        }
        ksort($out);

        return $out;
    }
}

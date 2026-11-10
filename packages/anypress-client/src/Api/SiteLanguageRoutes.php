<?php

namespace AnyPressClient\Api;

use AnyPressClient\Features\Languages;
use WP_Error;
use WP_REST_Request;

/**
 * The store's languages (Features\Languages, through Polylang): which are on, turning them on or off, and making a page's
 * copy in another language. A copy starts as a draft with the original's title and content; the caller translates its
 * text with the ordinary page routes and publishes it. Used by SiteController.
 */
trait SiteLanguageRoutes
{
    public function get_languages(): array
    {
        return Languages::describe();
    }

    /** { on: bool, languages?: string[] } WordPress locales besides the website's own, like ["es_ES", "fr_FR"]. */
    public function update_languages(WP_REST_Request $request)
    {
        $on = $request->get_param('on');
        if (!is_bool($on)) {
            return $this->bad('Send on: true to turn Languages on, or false to turn it off.');
        }
        $languages = $request->get_param('languages');
        if ($languages !== null && (!is_array($languages) || array_filter($languages, fn ($locale) => !is_string($locale) || !preg_match('/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$/', $locale)))) {
            return $this->bad('languages must be WordPress locales, like ["es_ES", "fr_FR"].');
        }
        $saved = Languages::save($on, $languages);
        if (is_wp_error($saved)) {
            return $saved;
        }

        return Languages::describe() + ['note' => $on
            ? 'Setting up takes a few seconds (translation files are downloaded). Read the languages again until ready is true before translating pages.'
            : 'Languages is off: the language switcher left the menus, and translated pages stay as ordinary pages.'];
    }

    /** { language } a language code or locale. Returns the copy (made as a draft if it didn't exist). */
    public function translate_page(WP_REST_Request $request)
    {
        $page = $this->page($request);
        if (is_wp_error($page)) {
            return $page;
        }
        if (!Languages::is_ready()) {
            return new WP_Error('anypress_languages_off', 'Languages is not on, or still setting up. Turn it on and read the languages until ready is true.', ['status' => 409]);
        }
        $language = PLL()->model->languages->get((string) $request->get_param('language'));
        if (!$language) {
            return $this->bad('language must be one of this website\'s languages: ' . implode(', ', pll_languages_list(['fields' => 'slug'])) . '.');
        }
        $source = pll_get_post_language($page->ID) ?: pll_default_language();
        if ($source === $language->slug) {
            return $this->bad('This page is already in that language.');
        }
        $existing = pll_get_post($page->ID, $language->slug);
        if ($existing) {
            return $this->page_summary(get_post($existing)) + ['created' => false];
        }

        $copy = wp_insert_post([
            'post_type' => 'page',
            'post_title' => $page->post_title,
            'post_content' => $page->post_content,
            'post_excerpt' => $page->post_excerpt,
            'post_status' => 'draft',
            'post_parent' => (int) (pll_get_post($page->post_parent, $language->slug) ?: 0),
            'menu_order' => $page->menu_order,
            'page_template' => get_page_template_slug($page),
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ], true);
        if (is_wp_error($copy)) {
            return $copy;
        }
        pll_set_post_language($copy, $language->slug);
        $translations = pll_get_post_translations($page->ID);
        $translations[$source] = $page->ID;
        $translations[$language->slug] = $copy;
        pll_save_post_translations($translations);

        return $this->page_summary(get_post($copy)) + ['created' => true];
    }

    /** A page's language and its copies in other languages (code => page id), while Languages is on. */
    private function page_languages($page): array
    {
        if (!Languages::is_ready()) {
            return [];
        }
        $translations = pll_get_post_translations($page->ID);
        unset($translations[(string) pll_get_post_language($page->ID)]);

        return ['language' => pll_get_post_language($page->ID) ?: null, 'translations' => (object) $translations];
    }
}

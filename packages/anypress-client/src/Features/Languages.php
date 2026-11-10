<?php

namespace AnyPressClient\Features;

use WP_Error;

/**
 * The store's Languages switch: the website in more than one language, through Polylang (wordpress.org/plugins/polylang).
 * Stores come with Polylang installed and switched off. When the owner turns Languages on (the AnyPress dashboard, the
 * connector or Dashboard > Your plan), the plan keeps Polylang on (RolesManager adds it to the plan's plugins) and, once,
 * on the next request after Polylang has loaded, this class sets it up: the site's own language as the default, the
 * other languages chosen, addresses like /es/ (none for the default language), WordPress's translation files for the
 * site, its plugins and theme, a language switcher in the menus, and every existing page in the default language.
 *
 * Each language has its own copy of a page, linked to the others (translate_page makes a draft copy to translate). Menu
 * links to a page open that page's copy in the visitor's language. Text typed into the Fluent plugins (product names,
 * form labels, email text) is not translated by Polylang.
 *
 * Turning Languages off takes the switcher out of the menus and lets the plan switch Polylang off; translated pages
 * stay, as ordinary pages, and come back linked when Languages is turned on again.
 */
class Languages
{
    public const PLUGIN = 'polylang/polylang.php';
    public const OPTION = 'anypress_languages';
    public const MAX = 10;
    private const LOCK = 'anypress_languages_setup';
    private const FORM = 'anypress_languages';
    private const SWITCHER = 'polylang/navigation-language-switcher';
    private const SWITCHER_MARKUP = '<!-- wp:polylang/navigation-language-switcher {"layout":"dropdown"} /-->';

    public function __construct()
    {
        add_action('wp_loaded', [$this, 'finish_setup']);
        add_action('admin_post_' . self::FORM, [$this, 'save_form']);
        if (self::is_on()) {
            add_filter('render_block_core/navigation-link', [$this, 'translate_link'], 10, 2);
            add_filter('render_block_core/navigation-submenu', [$this, 'translate_link'], 10, 2);
        }
    }

    /** Whether this website has Polylang at all (stores made before it came with the template don't). */
    public static function is_installed(): bool
    {
        return file_exists(WP_PLUGIN_DIR . '/' . self::PLUGIN);
    }

    /** @return array{on: bool, languages: string[], ready: bool} languages are WordPress locales, the default first. */
    public static function state(): array
    {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];

        return [
            'on' => !empty($saved['on']),
            'languages' => array_values(array_filter((array) ($saved['languages'] ?? []), 'is_string')),
            'ready' => !empty($saved['ready']),
        ];
    }

    public static function is_on(): bool
    {
        return self::state()['on'] && self::is_installed();
    }

    /** Polylang has loaded and has its languages: translated pages and links work. */
    public static function is_ready(): bool
    {
        return self::is_on() && self::state()['ready'] && function_exists('PLL') && function_exists('pll_languages_list');
    }

    /**
     * The plan's plugins plus Polylang while Languages is on.
     *
     * @param string[] $plugins
     * @return string[]
     */
    public static function with_polylang(array $plugins): array
    {
        if (self::is_on() && !in_array(self::PLUGIN, $plugins, true)) {
            $plugins[] = self::PLUGIN;
        }

        return $plugins;
    }

    /** The site's own language, which stays the default. */
    public static function default_locale(): string
    {
        $locale = (string) get_option('WPLANG', '');

        return $locale !== '' ? $locale : 'en_US';
    }

    /** Every language Polylang knows: locale => name. Empty when Polylang isn't installed. */
    public static function known(): array
    {
        $file = WP_PLUGIN_DIR . '/polylang/src/settings/languages.php';
        if (!file_exists($file)) {
            return [];
        }
        $known = [];
        foreach ((array) include $file as $locale => $language) {
            if (is_array($language) && isset($language['name'])) {
                $known[(string) $locale] = (string) $language['name'];
            }
        }

        return $known;
    }

    /**
     * Turns Languages on (with these extra languages) or off. The default language is always the site's own and comes
     * first. Setting up finishes on the next request, which this starts in the background.
     *
     * @param string[]|null $languages WordPress locales, like es_ES; null keeps the ones chosen before.
     * @return array|WP_Error the new state
     */
    public static function save(bool $on, ?array $languages = null)
    {
        if ($on && !self::is_installed()) {
            return new WP_Error('anypress_languages_unavailable', 'This website was built before Languages came with stores, so it has no Polylang yet. Move it to the newest template version first.', ['status' => 409]);
        }
        $state = self::state();
        $chosen = $languages === null ? array_slice($state['languages'], 1) : array_values(array_unique(array_map('strval', $languages)));
        $known = self::known();
        $default = self::default_locale();
        $chosen = array_values(array_filter($chosen, fn ($locale) => $locale !== $default));
        $unknown = $known ? array_values(array_diff($chosen, array_keys($known))) : [];
        if ($unknown) {
            return new WP_Error('anypress_languages_unknown', sprintf('Not a language this website can use: %s. Use WordPress locales such as es_ES, fr_FR or de_DE.', implode(', ', $unknown)), ['status' => 400]);
        }
        if (count($chosen) + 1 > self::MAX) {
            return new WP_Error('anypress_languages_too_many', sprintf('Choose up to %d languages, the website\'s own included.', self::MAX), ['status' => 400]);
        }
        if ($on && !$chosen) {
            return new WP_Error('anypress_languages_none', 'Choose at least one language besides the website\'s own.', ['status' => 400]);
        }

        $list = array_merge([$default], $chosen);
        $changed = $on !== $state['on'] || $list !== $state['languages'];
        update_option(self::OPTION, ['on' => $on, 'languages' => $list, 'ready' => $changed ? false : $state['ready']], false);

        if ($on && $changed) {
            if (!function_exists('activate_plugin')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            activate_plugin(self::PLUGIN, '', false, true);
            // Polylang sends the next admin page to its set-up wizard; AnyPress does that set-up itself.
            delete_transient('pll_activation_redirect');
            self::start_setup();
        }
        if (!$on) {
            self::remove_switcher();
        }

        return self::state();
    }

    /** Asks the website for any page, without waiting, so the set-up runs now rather than on the next visit. */
    private static function start_setup(): void
    {
        wp_remote_get(home_url('/'), ['blocking' => false, 'timeout' => 0.01, 'sslverify' => apply_filters('https_local_ssl_verify', false)]);
    }

    /** Once, after Languages was turned on or its languages changed, and only once Polylang has loaded. */
    public function finish_setup(): void
    {
        $state = self::state();
        if (!$state['on'] || $state['ready'] || !self::is_installed() || !function_exists('PLL') || !isset(PLL()->model)) {
            return;
        }
        // One request at a time; a set-up that died frees the lock after five minutes.
        $started = (int) get_option(self::LOCK, 0);
        if ($started > time() - 300 || !($started ? update_option(self::LOCK, time(), false) : add_option(self::LOCK, time(), '', false))) {
            return;
        }
        try {
            self::setup($state['languages']);
            update_option(self::OPTION, ['ready' => true] + $state, false);
        } finally {
            delete_option(self::LOCK);
        }
    }

    /** @param string[] $locales the default first */
    private static function setup(array $locales): void
    {
        $model = PLL()->model;
        $options = PLL()->options;
        // example.com/es/... for other languages, plain addresses for the default one, no /language/ in them, and
        // no jumping to another language on the visitor's browser setting.
        $options['force_lang'] = 1;
        $options['hide_default'] = true;
        $options['rewrite'] = true;
        $options['browser'] = false;
        if (method_exists($options, 'save')) {
            $options->save();
        }

        foreach ($locales as $order => $locale) {
            if (!$model->languages->get($locale)) {
                $model->languages->add(['locale' => $locale, 'term_group' => $order]);
            }
        }
        $default = $model->languages->get($locales[0]);
        if ($default && ($options['default_lang'] ?? '') !== $default->slug) {
            $model->languages->update_default($default->slug);
        }
        // Languages taken out of the list go only when nothing is written in them.
        foreach ($model->languages->get_list() as $language) {
            if (!in_array($language->locale, $locales, true) && (int) $language->get_tax_prop('language', 'count') === 0) {
                $model->languages->delete($language->term_id);
            }
        }
        $model->set_language_in_mass();

        self::download_translations($locales);
        self::add_switcher();
        flush_rewrite_rules(false);
    }

    /** WordPress's own translation files for each language: WordPress itself, then its plugins and theme. */
    private static function download_translations(array $locales): void
    {
        require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        foreach ($locales as $locale) {
            if ($locale !== 'en_US') {
                wp_download_language_pack($locale);
            }
        }
        // The update check asks wordpress.org for translations in every installed language.
        wp_clean_update_cache();
        wp_update_plugins();
        wp_update_themes();
        $updates = wp_get_translation_updates();
        if ($updates) {
            (new \Language_Pack_Upgrader(new \Automatic_Upgrader_Skin()))->bulk_upgrade($updates);
        }
    }

    /** A language switcher at the end of each menu (WordPress makes the header's menu first if there is none yet). */
    private static function add_switcher(): void
    {
        if (class_exists('WP_Navigation_Fallback') && !get_posts(['post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids'])) {
            \WP_Navigation_Fallback::get_fallback();
        }
        foreach (get_posts(['post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 20]) as $menu) {
            if (strpos($menu->post_content, self::SWITCHER) === false) {
                wp_update_post(['ID' => $menu->ID, 'post_content' => rtrim($menu->post_content) . "\n" . self::SWITCHER_MARKUP]);
            }
        }
    }

    private static function remove_switcher(): void
    {
        foreach (get_posts(['post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 20]) as $menu) {
            if (strpos($menu->post_content, self::SWITCHER) === false) {
                continue;
            }
            $blocks = array_values(array_filter(parse_blocks($menu->post_content), fn ($block) => ($block['blockName'] ?? '') !== self::SWITCHER));
            wp_update_post(['ID' => $menu->ID, 'post_content' => serialize_blocks($blocks)]);
        }
    }

    /**
     * A menu link to a page opens that page's copy in the visitor's language, with its title when the link shows the
     * page's own title.
     */
    public function translate_link(string $html, array $block): string
    {
        $id = (int) ($block['attrs']['id'] ?? 0);
        if (!$id || ($block['attrs']['kind'] ?? '') !== 'post-type' || !self::is_ready()) {
            return $html;
        }
        $translation = (int) pll_get_post($id);
        if (!$translation || $translation === $id || get_post_status($translation) !== 'publish') {
            return $html;
        }
        $from = get_permalink($id);
        $to = get_permalink($translation);
        if ($from && $to) {
            $html = str_replace(['href="' . esc_url($from) . '"', 'href="' . esc_attr($from) . '"'], 'href="' . esc_url($to) . '"', $html);
        }
        $label = (string) ($block['attrs']['label'] ?? '');
        $original = get_the_title($id);
        if ($label !== '' && $label === $original) {
            $html = str_replace('>' . esc_html($original) . '<', '>' . esc_html(get_the_title($translation)) . '<', $html);
        }

        return $html;
    }

    /**
     * The website's languages for the API: each one's locale, code, name, whether it's the default, and how many pages
     * are written in it.
     */
    public static function describe(): array
    {
        $state = self::state();
        $known = self::known();
        $languages = [];
        if (self::is_ready()) {
            foreach (PLL()->model->languages->get_list() as $language) {
                $languages[] = [
                    'locale' => $language->locale,
                    'code' => $language->slug,
                    'name' => $language->name,
                    'default' => (bool) $language->is_default,
                    'pages' => (int) pll_count_posts($language->slug, ['post_type' => 'page']),
                ];
            }
        } else {
            foreach ($state['languages'] as $index => $locale) {
                $languages[] = ['locale' => $locale, 'name' => $known[$locale] ?? $locale, 'default' => $index === 0];
            }
        }

        return [
            'on' => $state['on'] && self::is_installed(),
            'available' => self::is_installed(),
            'ready' => self::is_ready(),
            'languages' => $languages,
            'choices' => array_map(fn ($locale, $name) => ['locale' => $locale, 'name' => $name], array_keys($known), $known),
        ];
    }

    /** The "Languages" section of the "Your plan" page: on or off, and which languages. */
    public static function render_section(): void
    {
        if (!self::is_installed()) {
            return;
        }
        $state = self::describe();
        $chosen = array_slice(self::state()['languages'], 1);
        $known = self::known();
        $default = self::default_locale();
        unset($known[$default]);
        asort($known);
?>
        <h2 id="languages"><?php esc_html_e('Languages', 'anypress-client'); ?></h2>
        <p><?php esc_html_e('Show your website in more than one language. Each page gets a copy per language that you (or your AI app) translate, visitors pick a language in the menu, and addresses look like /es/. Buttons and screens from your plugins follow the visitor\'s language. Text you typed into your shop, forms and emails is not translated.', 'anypress-client'); ?></p>
        <?php if (isset($_GET['languages'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks a message. ?>
            <div class="notice notice-<?php echo $_GET['languages'] === 'saved' ? 'success' : 'error'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?> inline"><p><?php
                echo $_GET['languages'] === 'saved' // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    ? esc_html__('Saved. Setting up languages takes a few seconds.', 'anypress-client')
                    : esc_html(sanitize_text_field(wp_unslash((string) get_transient(self::FORM . '_error_' . get_current_user_id()))));
            ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM); ?>">
            <?php wp_nonce_field(self::FORM); ?>
            <p><label><input type="checkbox" name="on" value="1" <?php checked($state['on']); ?>> <?php esc_html_e('Languages on', 'anypress-client'); ?></label></p>
            <p>
                <label for="anypress-languages"><?php
                    /* translators: %s: the website's own language */
                    echo esc_html(sprintf(__('Other languages (your website\'s own, %s, stays the main one):', 'anypress-client'), $default));
                ?></label><br>
                <select id="anypress-languages" name="languages[]" multiple size="8" style="min-width:20em">
                    <?php foreach ($known as $locale => $name) : ?>
                        <option value="<?php echo esc_attr($locale); ?>" <?php selected(in_array($locale, $chosen, true)); ?>><?php echo esc_html($name . ' (' . $locale . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <?php if ($state['on'] && !$state['ready']) : ?>
                <p><em><?php esc_html_e('Setting up: reload this page in a moment.', 'anypress-client'); ?></em></p>
            <?php elseif ($state['ready']) : ?>
                <p><?php echo esc_html(implode(', ', array_map(fn ($language) => sprintf('%s: %d %s', $language['name'], $language['pages'], _n('page', 'pages', $language['pages'], 'anypress-client')), $state['languages']))); ?></p>
            <?php endif; ?>
            <?php submit_button(__('Save languages', 'anypress-client'), 'secondary'); ?>
        </form>
<?php
    }

    public function save_form(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to change this website\'s languages.', 'anypress-client'), 403);
        }
        check_admin_referer(self::FORM);
        $languages = array_map('sanitize_text_field', array_map('wp_unslash', (array) ($_POST['languages'] ?? [])));
        $saved = self::save(!empty($_POST['on']), $languages);
        if (is_wp_error($saved)) {
            set_transient(self::FORM . '_error_' . get_current_user_id(), $saved->get_error_message(), 60);
        }
        wp_safe_redirect(admin_url('index.php?page=' . CustomerPlanPage::PAGE . '&languages=' . (is_wp_error($saved) ? 'error' : 'saved') . '#languages'));
        exit;
    }
}

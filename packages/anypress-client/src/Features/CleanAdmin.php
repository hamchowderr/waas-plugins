<?php

namespace AnyPressClient\Features;

/**
 * A clean wp-admin on a store: the plugins that come with the website (every plugin roles.json lists) don't ask the
 * owner to upgrade to a paid version, leave a review or try another product, and don't show their own notices. Their
 * setup is done when the store is made (the template's presets and the owner's details from the purchase), and what
 * is set up or connected is on Dashboard > Your plan. The owner's own plugins are left as they are.
 *
 * Also hidden: WordPress's update notices for what the store's version manages (WordPress itself and the included
 * plugins), and screens owners have no use for (the theme and plugin file editors, the background-job list).
 *
 * Admin requests only, and only on a store. A site can turn it off with the anypress_client/clean_admin filter.
 */
class CleanAdmin
{
    /** Submenu titles that sell something rather than do something. */
    public const UPSELL_MENU = '/\b(upgrade|go pro|get pro|pro features|premium|pricing|licen[cs]es?|add-?ons|extensions|try [a-z]+!?|[a-z]+ sale|deals?)\b/i';

    /** Plugins screen links that sell something or ask for a review. */
    public const UPSELL_LINK = '/\b(upgrade|go pro|get pro|unlock pro|pro version|premium|pricing|buy|review|rate us|rate it|add-?ons|check update)\b/i';

    /** Screens a store owner never needs. */
    public const HIDDEN_SCREENS = [
        'index.php' => ['update-core.php'],
        'tools.php' => ['action-scheduler', 'theme-editor.php', 'plugin-editor.php'],
        'themes.php' => ['theme-editor.php'],
        'plugins.php' => ['plugin-editor.php'],
    ];

    /** @var string[]|null plugin folders (or single-file plugins) the template manages */
    private static ?array $managed = null;

    /** @var string[]|null plugin folders this store's site type turns on (null: no site type found, hide nothing) */
    private static ?array $planned = null;

    public function __construct()
    {
        if (!is_admin() || !PluginBootstrap::is_store()) {
            return;
        }
        // Before the admin menu is built (admin_menu runs before admin_init).
        add_action('init', [$this, 'hooks'], 1);
    }

    public function hooks(): void
    {
        if (!apply_filters('anypress_client/clean_admin', true) || self::managed_folders() === []) {
            return;
        }
        foreach (['admin_notices', 'all_admin_notices', 'user_admin_notices'] as $hook) {
            add_action($hook, [$this, 'drop_managed_notices'], PHP_INT_MIN);
        }
        add_action('admin_menu', [$this, 'tidy_menu'], PHP_INT_MAX);
        add_filter('plugin_action_links', [$this, 'plugin_links'], PHP_INT_MAX, 2);
        add_filter('plugin_row_meta', [$this, 'plugin_links'], PHP_INT_MAX, 2);
        // A plugin's own plugin_action_links_<file> filter runs after the general one, so filter that too.
        add_action('load-plugins.php', [$this, 'plugin_links_per_plugin']);
        // Included plugins this site type doesn't use stay off and out of the Plugins screen.
        add_filter('all_plugins', [$this, 'hide_unplanned_plugins'], PHP_INT_MAX);
        add_filter('plugin_auto_update_setting_html', [$this, 'auto_update_html'], PHP_INT_MAX, 2);
        add_filter('site_transient_update_plugins', [$this, 'hide_managed_updates']);
        add_filter('wp_get_update_data', [$this, 'update_counts'], PHP_INT_MAX);
        add_filter('admin_footer_text', [$this, 'footer_text'], PHP_INT_MAX);
        add_filter('update_footer', '__return_empty_string', PHP_INT_MAX);
        add_action('admin_head', [$this, 'hide_in_page_upsells']);
    }

    /** The folders of the plugins any site type turns on (and the MCP Adapter). */
    public static function managed_folders(): array
    {
        if (self::$managed !== null) {
            return self::$managed;
        }
        $roles = file_exists(AdminRolesSettings::ROLES_FILE_PATH) ? json_decode((string) file_get_contents(AdminRolesSettings::ROLES_FILE_PATH), true) : null;
        $files = is_array($roles) ? RolesManager::managed_plugins($roles) : [];

        self::$managed = array_values(array_unique(array_map(static fn ($file) => explode('/', $file)[0], $files)));

        return self::$managed;
    }

    /** The folders of the plugins this store's site type turns on (and the MCP Adapter), or null when no site type matches. */
    public static function planned_folders(): ?array
    {
        if (self::$planned !== null) {
            return self::$planned === ['*'] ? null : self::$planned;
        }
        $roles = file_exists(AdminRolesSettings::ROLES_FILE_PATH) ? json_decode((string) file_get_contents(AdminRolesSettings::ROLES_FILE_PATH), true) : null;
        $user_roles = array_values(array_filter(array_map('strval', (array) get_option(PluginBootstrap::TENANT_ROLES, []))));
        $plan = is_array($roles) ? RolesManager::plan_for($user_roles, $roles) : ['matched' => []];
        if ($plan['matched'] === []) {
            self::$planned = ['*'];

            return null;
        }
        $files = array_merge($plan['plugins'], [McpServer::PLUGIN]);

        self::$planned = array_values(array_unique(array_map(static fn ($file) => explode('/', $file)[0], $files)));

        return self::$planned;
    }

    /** On the Plugins screen: no rows for included plugins this site type doesn't use (they are off and can't be used here). */
    public function hide_unplanned_plugins($plugins)
    {
        $planned = self::planned_folders();
        if (!is_array($plugins) || $planned === null) {
            return $plugins;
        }
        foreach (array_keys($plugins) as $file) {
            if (self::is_managed((string) $file) && !in_array(explode('/', (string) $file)[0], $planned, true)) {
                unset($plugins[$file]);
            }
        }

        return $plugins;
    }

    /** The store's version keeps the included plugins up to date, so their rows don't offer auto-updates. */
    public function auto_update_html($html, $plugin_file)
    {
        return self::is_managed((string) $plugin_file) ? '' : $html;
    }

    /** Whether a plugin file (as WordPress names it, e.g. fluent-crm/fluent-crm.php) comes with the website. */
    public static function is_managed(string $plugin_file): bool
    {
        return in_array(explode('/', $plugin_file)[0], self::managed_folders(), true);
    }

    /** The plugin folder a callback's code is in, or null for WordPress itself and anything outside wp-content/plugins. */
    public static function plugin_of($callback): ?string
    {
        if ($callback === null) {
            return null;
        }
        try {
            if (is_string($callback) && str_contains($callback, '::')) {
                $callback = explode('::', $callback, 2);
            }
            if (is_array($callback) && count($callback) === 2) {
                $file = (new \ReflectionMethod($callback[0], $callback[1]))->getFileName();
            } elseif ($callback instanceof \Closure || (is_string($callback) && function_exists($callback))) {
                $file = (new \ReflectionFunction($callback))->getFileName();
            } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                $file = (new \ReflectionMethod($callback, '__invoke'))->getFileName();
            } else {
                return null;
            }
        } catch (\ReflectionException $e) {
            return null;
        }
        $dir = wp_normalize_path(WP_PLUGIN_DIR) . '/';
        $file = wp_normalize_path((string) $file);
        if (!str_starts_with($file, $dir)) {
            return null;
        }

        return explode('/', substr($file, strlen($dir)))[0];
    }

    /** Removes the notices the included plugins add, and WordPress's own update notice, before they print. */
    public function drop_managed_notices(): void
    {
        global $wp_filter;
        $hook = current_filter();
        remove_action('admin_notices', 'update_nag', 3);
        remove_action('network_admin_notices', 'update_nag', 3);
        remove_action('admin_notices', 'maintenance_nag', 10);
        if (!isset($wp_filter[$hook])) {
            return;
        }
        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $plugin = self::plugin_of($callback['function']);
                if ($plugin !== null && in_array($plugin, self::managed_folders(), true)) {
                    remove_action($hook, $callback['function'], $priority);
                }
            }
        }
    }

    /** Takes out menu items that sell something, and the screens owners have no use for. */
    public function tidy_menu(): void
    {
        global $submenu;
        foreach (self::HIDDEN_SCREENS as $parent => $pages) {
            foreach ($pages as $page) {
                remove_submenu_page($parent, $page);
            }
        }
        foreach ((array) $submenu as $parent => $items) {
            // Only the included plugins' own menus: an owner's plugin keeps its Extensions or Add-ons page.
            $parent_plugin = self::plugin_of(self::page_callback(get_plugin_page_hookname((string) $parent, '')));
            foreach ((array) $items as $i => $item) {
                $title = trim(wp_strip_all_tags((string) ($item[0] ?? '')));
                if ($title === '' || !preg_match(self::UPSELL_MENU, $title)) {
                    continue;
                }
                $plugin = self::plugin_of(self::page_callback(get_plugin_page_hookname((string) ($item[2] ?? ''), (string) $parent))) ?? $parent_plugin;
                if ($plugin !== null && in_array($plugin, self::managed_folders(), true)) {
                    unset($submenu[$parent][$i]);
                }
            }
        }
    }

    /** On the Plugins screen, an included plugin's row keeps its Settings links but loses upgrade and review links. */
    public function plugin_links($links, $plugin_file)
    {
        if (!is_array($links) || !self::is_managed((string) $plugin_file)) {
            return $links;
        }

        return array_filter($links, static fn ($link) => !preg_match(self::UPSELL_LINK, wp_strip_all_tags((string) $link)));
    }

    public function plugin_links_per_plugin(): void
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach (array_keys(get_plugins()) as $file) {
            if (self::is_managed($file)) {
                add_filter('plugin_action_links_' . $file, [$this, 'plugin_links'], PHP_INT_MAX, 2);
            }
        }
    }

    /** The store's version brings new copies of the included plugins, so WordPress doesn't offer to update them. */
    public function hide_managed_updates($updates)
    {
        if (!is_object($updates) || empty($updates->response) || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return $updates;
        }
        foreach (array_keys((array) $updates->response) as $file) {
            if (self::is_managed((string) $file)) {
                $updates->no_update[$file] = $updates->response[$file];
                unset($updates->response[$file]);
            }
        }

        return $updates;
    }

    /** WordPress itself comes with the store's version too: no "Updates" count for it in the menu. */
    public function update_counts($data)
    {
        if (!is_array($data) || !isset($data['counts']['wordpress'])) {
            return $data;
        }
        $data['counts']['wordpress'] = 0;
        $data['counts']['total'] = (int) ($data['counts']['plugins'] ?? 0) + (int) ($data['counts']['themes'] ?? 0) + (int) ($data['counts']['translations'] ?? 0);
        $data['title'] = '';

        return $data;
    }

    /** No "rate us" footers on the included plugins' screens. */
    public function footer_text($text)
    {
        return $this->on_managed_screen() ? '' : $text;
    }

    /**
     * Upgrade buttons inside the included plugins' own screens (their apps draw them, so there is no hook): hidden by
     * their text, also when the app redraws. Only on those screens.
     */
    public function hide_in_page_upsells(): void
    {
        if (!$this->on_managed_screen()) {
            return;
        }
        ?>
        <style>
            .rank-math-unlock-pro-notice, .rank-math-unlock-pro-box, .ff_card_pro, .ff_upgrade_btn_large, .fls_dash_promo, .rank-math-pro-cta, .rank-math-anniversary-wrapper, .fct-congrats-modal,
            #wpbody a[href*="/pricing"], #wpbody a[href*="rankmath.com/pro"], #wpbody a[href*="utm_medium=upgrade"] { display: none !important; }
            body.el-popup-parent--hidden { overflow: auto !important; }
        </style>
        <script>
        (function () {
            var sell = /^\s*(upgrade( to pro| now)?|go pro|get pro|buy pro|buy now|unlock( with)? pro|unlock|leave a review|write a review|rate us|review [\w ]+ on WordPress\.org\.?|try [a-z]+!?)\s*(\(opens in a new (window|tab)\))?\s*$/i;
            function tidy(root) {
                (root.querySelectorAll ? root : document).querySelectorAll('#wpbody a, #wpbody button').forEach(function (el) {
                    if (!el.dataset.anypressSeen && sell.test(el.textContent || '')) {
                        el.style.display = 'none';
                    }
                    el.dataset.anypressSeen = '1';
                });
            }
            document.addEventListener('DOMContentLoaded', function () {
                tidy(document);
                var body = document.getElementById('wpbody');
                if (body && window.MutationObserver) {
                    var queued = false;
                    new MutationObserver(function () {
                        if (queued) return;
                        queued = true;
                        requestAnimationFrame(function () { queued = false; tidy(document); });
                    }).observe(body, { childList: true, subtree: true });
                }
            });
        })();
        </script>
        <?php
    }

    /** Whether the current screen belongs to one of the included plugins (a page=<slug> it registered). */
    private function on_managed_screen(): bool
    {
        global $pagenow;
        if ($pagenow === 'index.php' && empty($_GET['page'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen this is.
            return true; // the Dashboard home, where the included plugins put their widgets
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen this is.
        if ($page === '') {
            return false;
        }
        global $pagenow, $plugin_page;
        $plugin = self::plugin_of(self::page_callback(get_plugin_page_hookname($page, $pagenow === 'admin.php' ? '' : (string) $pagenow)));
        if ($plugin === null && isset($plugin_page)) {
            $plugin = self::plugin_of(self::page_callback(get_plugin_page_hookname((string) $plugin_page, get_admin_page_parent())));
        }

        return $plugin !== null && in_array($plugin, self::managed_folders(), true);
    }

    /** The function that draws an admin page, from its hook name. */
    private static function page_callback(?string $hookname)
    {
        global $wp_filter;
        if ($hookname === null || $hookname === '' || !isset($wp_filter[$hookname])) {
            return null;
        }
        foreach ($wp_filter[$hookname]->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                return $callback['function'];
            }
        }

        return null;
    }
}

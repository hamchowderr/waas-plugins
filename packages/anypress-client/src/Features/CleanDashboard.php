<?php

namespace AnyPressClient\Features;

use AnyPressClient\Core\Brand;

/**
 * The wp-admin Dashboard home on a store: WordPress's own boxes (Welcome, Site Health Status, Quick Draft, At a Glance,
 * Activity, WordPress Events and News) and the boxes the included plugins add are taken off, and the store's own home
 * takes their place: links to edit the site, what comes with this site type (the same list as roles.json and the
 * AnyPress dashboard) with a link to each plugin's screen, the plan and where to get help. Boxes from plugins the
 * owner added stay.
 *
 * On by default on every store. The AnyPress API turns it off for a seller's or reseller's stores when they choose
 * (the anypress_clean_dashboard option set to "off"), and a site can turn it off with the
 * anypress_client/clean_dashboard filter.
 */
class CleanDashboard
{
    public const OPTION = 'anypress_clean_dashboard';

    /** Our boxes, kept when the rest are taken off. */
    public const WIDGETS = ['anypress_home', 'anypress_site', 'anypress_plan'];

    /**
     * The AnyPress ribbon mark, shown on AnyPress's own stores (a reseller's stores show only the reseller's name).
     * $id keeps its gradient ids apart when it appears twice on the page.
     */
    private static function mark(string $id): string
    {
        $svg = '<svg class="anypress-mark" viewBox="0 0 100 100" width="40" height="40" aria-hidden="true" focusable="false"><defs>'
        . '<linearGradient id="apdL" x1="8" y1="80" x2="66" y2="40" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#FFB338"/><stop offset=".55" stop-color="#FF7A2A"/><stop offset="1" stop-color="#F2413A"/></linearGradient>'
        . '<linearGradient id="apdR" x1="56" y1="14" x2="86" y2="86" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#FF5A33"/><stop offset=".5" stop-color="#EE2F5E"/><stop offset="1" stop-color="#D21FA4"/></linearGradient>'
        . '<linearGradient id="apdT" x1="8" y1="78" x2="44" y2="72" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#FFC75A"/><stop offset="1" stop-color="#FF9A30"/></linearGradient></defs>'
        . '<rect width="100" height="100" rx="22" fill="#14141A"/><g transform="translate(3 3) scale(.9)">'
        . '<path d="M48.5 17.5 Q51 14 55 15 L56 27 L50.5 48 L62.3 48 L66.2 59 L48 64 L44 77.5 L20 77.5 Q16 77.5 18.5 75 Q28 68 32 58 Z" fill="url(#apdL)"/>'
        . '<path d="M20 77.5 Q16 77.5 18.5 75 Q24 71 30 70 Q36 72 44 77.5 Z" fill="url(#apdT)"/>'
        . '<path d="M51 15 L62 15 Q65.5 15 66.8 18.5 L90 80 Q91.5 84 87.5 84 L77.5 84 Q75 84 74 81.5 L55 27 Z" fill="url(#apdR)"/>'
        . '<path d="M51 15 L55 27 L56.8 32 L52.6 24 Z" fill="#7A0F3A" opacity=".35"/></g></svg>';

        return str_replace('apd', 'apd' . $id, $svg);
    }

    public function __construct()
    {
        if (!is_admin() || !PluginBootstrap::is_store()) {
            return;
        }
        add_action('wp_dashboard_setup', [$this, 'setup'], PHP_INT_MAX);
    }

    public static function enabled(): bool
    {
        return get_option(self::OPTION, 'on') !== 'off' && (bool) apply_filters('anypress_client/clean_dashboard', true);
    }

    public function setup(): void
    {
        if (!self::enabled()) {
            return;
        }
        remove_action('welcome_panel', 'wp_welcome_panel');
        $this->tidy();
        // Boxes a plugin adds later, on do_meta_boxes, go too.
        add_action('do_meta_boxes', [$this, 'tidy_late'], PHP_INT_MAX, 1);

        $brand = Brand::name();
        wp_add_dashboard_widget('anypress_home', __('Your website', 'anypress-client'), [$this, 'render_home'], null, null, 'normal', 'high');
        // The plugin list is compact and sits on the right, so the two columns end about level.
        wp_add_dashboard_widget('anypress_site', __('What comes with your website', 'anypress-client'), [$this, 'render_site'], null, null, 'side', 'high');
        /* translators: %s: the website seller's brand, such as AnyPress */
        wp_add_dashboard_widget('anypress_plan', sprintf(__('Your plan with %s', 'anypress-client'), $brand), [$this, 'render_plan'], null, null, 'normal');

        // AnyPress's own stores get the AnyPress look; a reseller's stores keep WordPress's colours and only the reseller's name.
        if (Brand::reseller() === '') {
            add_filter('admin_body_class', static fn ($classes) => $classes . ' anypress-brand');
        }

        add_action('admin_enqueue_scripts', static function ($hook) {
            if ($hook === 'index.php') {
                wp_enqueue_style('anypress-admin-styles', ANYPRESS_PLUGIN_DIR_URI . 'assets/style.css', [], ANYPRESS_CLIENT_VERSION);
            }
        });
    }

    public function tidy_late($screen): void
    {
        if ($screen === 'dashboard') {
            $this->tidy();
        }
    }

    /** Takes off every Dashboard box but ours and those of plugins the owner added. */
    private function tidy(): void
    {
        global $wp_meta_boxes;
        if (empty($wp_meta_boxes['dashboard']) || !is_array($wp_meta_boxes['dashboard'])) {
            return;
        }
        foreach ($wp_meta_boxes['dashboard'] as $context => $priorities) {
            foreach ((array) $priorities as $priority => $boxes) {
                foreach ((array) $boxes as $id => $box) {
                    if (in_array((string) $id, self::WIDGETS, true) || !is_array($box)) {
                        continue;
                    }
                    $plugin = CleanAdmin::plugin_of($box['callback'] ?? null);
                    if ($plugin === null || in_array($plugin, CleanAdmin::managed_folders(), true)) {
                        unset($wp_meta_boxes['dashboard'][$context][$priority][$id]);
                    }
                }
            }
        }
    }

    public function render_home(): void
    {
        $user = wp_get_current_user();
        $links = [
            [__('Visit your site', 'anypress-client'), home_url('/'), 'external', true],
        ];
        if (current_user_can('edit_theme_options') && wp_is_block_theme()) {
            $links[] = [__('Edit the design', 'anypress-client'), admin_url('site-editor.php'), 'admin-appearance', false];
        }
        if (current_user_can('edit_pages')) {
            $links[] = [__('Pages', 'anypress-client'), admin_url('edit.php?post_type=page'), 'admin-page', false];
            $links[] = [__('Add a page', 'anypress-client'), admin_url('post-new.php?post_type=page'), 'plus-alt2', false];
        }
        if (current_user_can('edit_posts')) {
            $links[] = [__('Write a post', 'anypress-client'), admin_url('post-new.php'), 'edit', false];
        }
        if (current_user_can('upload_files')) {
            $links[] = [__('Media', 'anypress-client'), admin_url('upload.php'), 'format-image', false];
        }
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
?>
        <div class="anypress-home">
            <?php if (Brand::reseller() === '') : ?>
                <?php echo self::mark('h'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG. ?>
            <?php endif; ?>
            <p class="anypress-home-hello">
                <?php
                /* translators: %s: the user's display name */
                echo esc_html(sprintf(__('Hi %s, welcome back.', 'anypress-client'), $user->display_name));
                ?>
            </p>
            <p class="anypress-home-site">
                <strong><?php echo esc_html(get_bloginfo('name')); ?></strong>
                <a href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener"><?php echo esc_html($host); ?></a>
            </p>
            <ul class="anypress-home-links">
                <?php foreach ($links as [$label, $url, $icon, $external]) : ?>
                    <li><a class="button<?php echo $external ? ' button-primary' : ''; ?>" href="<?php echo esc_url($url); ?>"<?php echo $external ? ' target="_blank" rel="noopener"' : ''; ?>>
                        <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                        <?php echo esc_html($label); ?>
                    </a></li>
                <?php endforeach; ?>
            </ul>
        </div>
<?php
    }

    public function render_site(): void
    {
        $plan = self::plan();
        if ($plan['plugins'] === []) {
            echo '<p>' . esc_html__('This website has no site type yet, so nothing extra comes with it.', 'anypress-client') . '</p>';

            return;
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $wp_plugins = get_plugins();
        // Only what is installed: a design demo with the site type set has a few of its plugins, not all.
        $installed = array_values(array_filter($plan['plugins'], static fn ($file) => isset($wp_plugins[$file])));
        // AnyPress Blocks goes right after the theme it belongs with.
        usort($installed, static fn ($a, $b) => (int) !str_starts_with($a, 'anypress-blocks/') <=> (int) !str_starts_with($b, 'anypress-blocks/'));
        $screens = self::plugin_screens();
?>
        <ul class="anypress-site-plugins">
            <?php
            // The theme first: the site's design comes with it too.
            $theme = wp_get_theme();
            /* translators: %s: the theme's name, such as AnyPress */
            $theme_name = sprintf(__('%s Theme', 'anypress-client'), (string) $theme->get('Name'));
            $theme_about = __('The site\'s design: pages, colours and fonts you change in the Site Editor.', 'anypress-client');
            $theme_screen = current_user_can('edit_theme_options') ? ($theme->is_block_theme() ? admin_url('site-editor.php') : admin_url('themes.php')) : null;
            ?>
            <li title="<?php echo esc_attr($theme_about); ?>">
                <span class="anypress-site-plugin-initial" aria-hidden="true"><?php echo esc_html(strtoupper(mb_substr($theme_name, 0, 1))); ?><?php
                    if ($theme->get_stylesheet() === 'anypress' && Brand::reseller() === '') {
                        echo self::mark('t'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG.
                    } elseif ($theme->get_screenshot() !== false) {
                        printf('<img src="%s" alt="" width="30" height="30">', esc_url((string) $theme->get_screenshot()));
                    }
                ?></span>
                <span class="anypress-site-plugin-name">
                    <?php if ($theme_screen !== null) : ?>
                        <a href="<?php echo esc_url($theme_screen); ?>"><?php echo esc_html($theme_name); ?></a>
                    <?php else : ?>
                        <?php echo esc_html($theme_name); ?>
                    <?php endif; ?>
                </span>
                <span class="anypress-site-plugin-about"><?php echo esc_html($theme_about); ?></span>
            </li>
            <?php foreach ($installed as $file) :
                $folder = explode('/', $file)[0];
                [$name, $about] = self::name_and_about((string) ($wp_plugins[$file]['Name'] ?? $folder), (string) ($plan['about'][$file] ?? ''));
                $screen = $screens[$folder] ?? null;
                if ($screen === null && $folder === 'anypress-blocks' && current_user_can('edit_theme_options') && wp_is_block_theme()) {
                    // Its blocks have no screen of their own; they're used in the Site Editor.
                    $screen = admin_url('site-editor.php');
                }
            ?>
                <li<?php echo $about !== '' ? ' title="' . esc_attr($about) . '"' : ''; ?>>
                    <span class="anypress-site-plugin-initial" aria-hidden="true"><?php echo esc_html(strtoupper(mb_substr($name, 0, 1))); ?><?php
                        if ($folder === 'anypress-blocks' && Brand::reseller() === '') {
                            echo self::mark('b'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG.
                        } else {
                            // The plugin's wordpress.org icon; the script below tries its other file names, and the letter stays if none loads.
                            printf('<img src="%s" alt="" width="30" height="30" data-anypress-icon="%s">', esc_url('https://ps.w.org/' . rawurlencode($folder) . '/assets/icon.svg'), esc_attr($folder));
                        }
                    ?></span>
                    <span class="anypress-site-plugin-name">
                        <?php if ($screen !== null && is_plugin_active($file)) : ?>
                            <a href="<?php echo esc_url($screen); ?>"><?php echo esc_html($name); ?></a>
                        <?php else : ?>
                            <?php echo esc_html($name); ?>
                        <?php endif; ?>
                    </span>
                    <?php if ($about !== '') : ?>
                        <span class="anypress-site-plugin-about"><?php echo esc_html($about); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <script>
            document.querySelectorAll('#anypress_site img[data-anypress-icon]').forEach(function (img) {
                var files = ['icon-128x128.png', 'icon-128x128.gif', 'icon-128x128.jpg'];
                function next() {
                    var file = files.shift();
                    if (!file) { img.remove(); return; }
                    img.src = 'https://ps.w.org/' + encodeURIComponent(img.dataset.anypressIcon) + '/assets/' + file;
                }
                img.addEventListener('error', next);
                if (img.complete && img.naturalWidth === 0) { next(); }
            });
        </script>
<?php
    }

    public function render_plan(): void
    {
        $plan = self::plan();
        $storefront = (string) get_option('anypress_host_website_url', '');
?>
        <div class="anypress-plan">
            <?php if ($plan['title'] !== '') : ?>
                <p class="anypress-plan-type">
                    <?php esc_html_e('Site type', 'anypress-client'); ?>
                    <strong><?php echo esc_html($plan['title']); ?></strong>
                </p>
            <?php endif; ?>
            <p><?php esc_html_e('WordPress, your theme and the plugins that come with your website are kept up to date for you, with backups.', 'anypress-client'); ?></p>
            <p><a class="button anypress-plan-link" href="<?php echo esc_url(admin_url('index.php?page=' . CustomerPlanPage::PAGE)); ?>"><?php esc_html_e('See your plan', 'anypress-client'); ?></a></p>
            <?php if ($storefront !== '') : ?>
                <p><?php
                    printf(
                        /* translators: 1: the website seller's brand, 2: link to the seller's storefront */
                        esc_html__('Need help or a bigger plan? Contact %1$s at %2$s.', 'anypress-client'),
                        esc_html(Brand::name()),
                        '<a href="' . esc_url($storefront) . '" target="_blank" rel="noopener">' . esc_html((string) preg_replace('#^https?://#', '', untrailingslashit($storefront))) . '</a>'
                    );
                ?></p>
            <?php endif; ?>
        </div>
<?php
    }

    /**
     * The plugins this store's site type turns on (without AnyPress Client itself), their "what it is for" lines from
     * roles.json, and the site type's name.
     *
     * @return array{plugins: string[], about: array<string, string>, title: string}
     */
    private static function plan(): array
    {
        $roles = file_exists(AdminRolesSettings::ROLES_FILE_PATH) ? json_decode((string) file_get_contents(AdminRolesSettings::ROLES_FILE_PATH), true) : null;
        $roles = is_array($roles) ? $roles : [];
        $user_roles = array_values(array_filter(array_map('strval', (array) get_option(PluginBootstrap::TENANT_ROLES, []))));
        $plan = RolesManager::plan_for($user_roles, $roles);
        $plugins = [];
        $about = [];
        $titles = [];
        foreach ($plan['matched'] as $slug) {
            $role = (array) ($roles[$slug] ?? []);
            $titles[] = (string) ($role['title'] ?? $slug);
            foreach ((array) ($role['plugins'] ?? []) as $file) {
                if (is_string($file) && $file !== PluginBootstrap::PLUGIN_NAME && !in_array($file, $plugins, true)) {
                    $plugins[] = $file;
                }
            }
            foreach ((array) ($role['about'] ?? []) as $file => $text) {
                if (is_string($text)) {
                    $about[(string) $file] = $text;
                }
            }
        }

        return ['plugins' => $plugins, 'about' => $about, 'title' => implode(', ', $titles)];
    }

    /**
     * The plugin's short name and what it is for. roles.json lines start with a label ("FluentHub: one dashboard...",
     * "SEO: page titles..."): the label is dropped from the line, and is the name when it names the plugin (has the
     * first word of its name: "FluentCRM" for "FluentCRM - Marketing Automation For WordPress"); otherwise the
     * header's name up to its dash ("FluentAuth - Auth Security Plugin" is FluentAuth, "SEO" is Rank Math SEO).
     *
     * @return array{0: string, 1: string}
     */
    private static function name_and_about(string $name, string $about): array
    {
        $short = trim((string) preg_split('/\s+[-–—]\s+/u', $name, 2)[0]);
        $parts = explode(': ', $about, 2);
        if (count($parts) !== 2 || strlen($parts[0]) > 40) {
            return [$short, $about];
        }
        $label = trim($parts[0]);
        $own = stripos($label, (string) strtok($short, ' ')) !== false;

        return [$own ? $label : $short, ucfirst($parts[1])];
    }

    /**
     * The admin screen of each plugin folder: its first top-level menu item, or the first submenu item it draws
     * (FluentHub can merge the Fluent plugins' menus into its own). Only screens the user may open.
     *
     * @return array<string, string> folder => URL
     */
    private static function plugin_screens(): array
    {
        global $menu, $submenu;
        $screens = [];
        foreach ((array) $menu as $item) {
            $slug = (string) ($item[2] ?? '');
            if ($slug === '' || !current_user_can((string) ($item[1] ?? 'manage_options'))) {
                continue;
            }
            $plugin = CleanAdmin::plugin_of(CleanAdmin::page_callback(get_plugin_page_hookname($slug, '')));
            if ($plugin !== null && !isset($screens[$plugin])) {
                $screens[$plugin] = self::screen_url($slug, '');
            }
        }
        foreach ((array) $submenu as $parent => $items) {
            foreach ((array) $items as $item) {
                $slug = (string) ($item[2] ?? '');
                if ($slug === '' || !current_user_can((string) ($item[1] ?? 'manage_options'))) {
                    continue;
                }
                $plugin = CleanAdmin::plugin_of(CleanAdmin::page_callback(get_plugin_page_hookname($slug, (string) $parent)));
                if ($plugin !== null && !isset($screens[$plugin])) {
                    $screens[$plugin] = self::screen_url($slug, (string) $parent);
                }
            }
        }

        return $screens;
    }

    private static function screen_url(string $slug, string $parent): string
    {
        if (str_contains($slug, '.php')) {
            return admin_url($slug);
        }
        $base = ($parent !== '' && str_contains($parent, '.php') && $parent !== 'admin.php') ? $parent : 'admin.php';

        return add_query_arg('page', $slug, admin_url($base));
    }
}

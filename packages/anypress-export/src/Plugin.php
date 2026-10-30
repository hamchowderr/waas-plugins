<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages go out only through wp_send_json_error(), which JSON-encodes them.

/**
 * The plugin in WordPress: the Tools → Move to AnyPress page, the requests its page makes to move the
 * site step by step, and the WP-CLI command. The export's state is the `anypress_export_state` option.
 */
final class Plugin
{
    const OPTION = 'anypress_export_state';
    const LOCK = 'anypress_export_lock';
    const NONCE = 'anypress_export';
    const PAGE = 'anypress-export';
    const CRON = 'anypress_export_cleanup';
    /** Work folders untouched this long are removed: an import code only lasts 7 days. */
    const MAX_AGE = 7 * 86400;

    public static function boot(string $file): void
    {
        register_activation_hook($file, [self::class, 'schedule']);
        register_deactivation_hook($file, [self::class, 'deactivate']);
        add_action(self::CRON, [self::class, 'cleanup']);
        add_action('admin_init', [self::class, 'schedule']);
        add_action('admin_menu', [self::class, 'menu']);
        foreach (['preflight', 'begin', 'step', 'status', 'cancel'] as $action) {
            add_action("wp_ajax_anypress_export_$action", [self::class, "ajax_$action"]);
        }
        add_filter('plugin_action_links_' . plugin_basename($file), function ($links) {
            array_unshift($links, '<a href="' . esc_url(admin_url('tools.php?page=' . self::PAGE)) . '">' . esc_html__('Move to AnyPress', 'anypress-export') . '</a>');
            return $links;
        });
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('anypress-export', Cli::class);
        }
    }

    public static function menu(): void
    {
        $hook = add_management_page(__('Move to AnyPress', 'anypress-export'), __('Move to AnyPress', 'anypress-export'), 'manage_options', self::PAGE, [self::class, 'page']);
        if ($hook) {
            add_action('load-' . $hook, function () {
                add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
            });
        }
    }

    /** The page's script, only on this page, with its strings translatable and its starting state. */
    public static function enqueue(): void
    {
        $file = dirname(__DIR__) . '/anypress-export.php';
        wp_enqueue_script('anypress-export', plugins_url('assets/export.js', $file), ['wp-i18n'], ANYPRESS_EXPORT_VERSION, true);
        wp_set_script_translations('anypress-export', 'anypress-export');
        $state = get_option(self::OPTION);
        $config = [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'progress' => is_array($state) ? Exporter::progress($state) + ['store' => $state['store'] ?? []] : null,
        ];
        wp_add_inline_script('anypress-export', 'window.anypressExport = ' . wp_json_encode($config) . ';', 'before');
    }

    /** Runs the daily clean-up of abandoned work folders. */
    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 3600, 'daily', self::CRON);
    }

    /** Removes work folders nobody has touched for 7 days, and forgets an export whose folder is gone. */
    public static function cleanup(): void
    {
        Exporter::cleanup(wp_upload_dir(null, false)['basedir'], self::MAX_AGE);
        $state = get_option(self::OPTION);
        if (is_array($state) && ($state['step'] ?? '') !== 'moving' && !empty($state['work']) && !is_dir($state['work'])) {
            delete_option(self::OPTION);
        }
    }

    /** On deactivation: stop the clean-up and remove every work folder now, so no copy of the database stays behind. */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON);
        Exporter::cleanup(wp_upload_dir(null, false)['basedir'], 0);
        delete_option(self::OPTION);
        delete_transient(self::LOCK);
    }

    /** What waas.json tells AnyPress about this site. */
    public static function siteInfo(): array
    {
        global $wpdb;
        $layout = Preflight::layout(self::layout());
        return [
            'home' => home_url(),
            'siteurl' => site_url(),
            'name' => get_bloginfo('name'),
            'wordPressVersion' => get_bloginfo('version'),
            'phpVersion' => PHP_VERSION,
            'tablePrefix' => $wpdb->prefix,
            'multisite' => is_multisite(),
            'dbCharset' => (string) $wpdb->charset,
            'exportedBy' => 'anypress-export ' . ANYPRESS_EXPORT_VERSION,
            'exportedAt' => gmdate('c'),
        ] + array_intersect_key($layout, ['contentUrl' => true, 'siteUrl' => true]) + [
            'preflight' => self::preflight(false),
        ];
    }

    /** Where the site keeps its files and answers. */
    private static function layout(): array
    {
        $uploads = wp_upload_dir(null, false);
        return [
            'home' => home_url(),
            'siteurl' => site_url(),
            'contentDir' => WP_CONTENT_DIR,
            'contentUrl' => content_url(),
            'uploadsDir' => (string) $uploads['basedir'],
            'uploadsConstant' => defined('UPLOADS') ? (string) UPLOADS : null,
            'uploadPath' => (string) get_option('upload_path'),
        ];
    }

    /**
     * The preflight checks. Without `$scan`, only what WordPress knows at once (the export adds the
     * database and file checks in its first steps); with it, also the database and a look through
     * wp-content for up to `$seconds`, for the page to show before anything starts.
     */
    public static function preflight(bool $scan, float $seconds = 8): array
    {
        global $wpdb;
        $layout = Preflight::layout(self::layout());
        $active = (array) get_option('active_plugins', []);
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $installed = get_plugins();
        $plugins = [];
        foreach ($active as $file) {
            $plugins[] = ['file' => (string) $file, 'name' => (string) ($installed[$file]['Name'] ?? $file), 'version' => (string) ($installed[$file]['Version'] ?? '')];
        }
        $extensions = get_loaded_extensions();
        sort($extensions);
        $report = [
            'checkedAt' => gmdate('c'),
            'blockers' => array_merge(Preflight::requirements(is_multisite(), function_exists('deflate_init'), function_exists('curl_init')), $layout['blockers']),
            'warnings' => $layout['warnings'],
            'tablePrefix' => $wpdb->prefix,
            'php' => ['version' => PHP_VERSION, 'extensions' => $extensions],
            'wordPressVersion' => get_bloginfo('version'),
            'plugins' => $plugins,
            'configConstants' => Preflight::configConstants(self::configSource()),
            'actionScheduler' => self::actionScheduler(),
        ] + Preflight::recognise($active);
        if (!$scan) return $report;

        $report = Preflight::database(new WpdbDatabase($wpdb)) + $report;
        $uploads = (string) wp_upload_dir(null, false)['basedir'];
        $work = glob(rtrim($uploads, '/') . '/anypress-export-*', GLOB_ONLYDIR) ?: [];
        $report['files'] = Preflight::files(FileList::build(WP_CONTENT_DIR, null, $work, microtime(true) + $seconds));
        $free = function_exists('disk_free_space') ? @disk_free_space(is_dir($uploads) ? $uploads : WP_CONTENT_DIR) : false;
        $report['disk'] = ['free' => $free === false ? null : (int) $free, 'need' => Preflight::diskNeed((int) $report['files']['uploadsBytes'], (int) $report['databaseBytes'])];
        $report['notes'] = Preflight::notes($report);
        return $report;
    }

    /** wp-config.php's source, found where WordPress looks for it; empty when it can't be read. */
    private static function configSource(): string
    {
        $file = ABSPATH . 'wp-config.php';
        if (!@is_file($file)) {
            $file = dirname(ABSPATH) . '/wp-config.php';
            if (!@is_file($file) || @is_file(dirname(ABSPATH) . '/wp-settings.php')) return '';
        }
        return (string) @file_get_contents($file);
    }

    /** Action Scheduler's (WooCommerce and others) waiting and overdue tasks; null without it. */
    private static function actionScheduler(): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'actionscheduler_actions';
        $suppressed = $wpdb->suppress_errors(true);
        try {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return null;
            return [
                'pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE status = 'pending'"),
                'pastDue' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE status = 'pending' AND scheduled_date_gmt < %s", gmdate('Y-m-d H:i:s'))),
            ];
        } finally {
            $wpdb->suppress_errors($suppressed);
        }
    }

    public static function exporter(): Exporter
    {
        global $wpdb;
        return new Exporter(new WpdbDatabase($wpdb), WP_CONTENT_DIR);
    }

    public static function code(string $code): ImportCode
    {
        return ImportCode::parse($code, defined('ANYPRESS_EXPORT_ALLOW_HTTP') && ANYPRESS_EXPORT_ALLOW_HTTP);
    }

    /**
     * Starts an export with a pasted code. Shared by the page and the WP-CLI command.
     *
     * @throws \Throwable with a message for the person
     */
    public static function begin(string $pasted): array
    {
        $site = self::siteInfo();
        if ($site['preflight']['blockers']) throw new \RuntimeException($site['preflight']['blockers'][0]);
        $old = get_option(self::OPTION);
        if (is_array($old) && !empty($old['work'])) Exporter::remove($old['work']);
        $code = self::code($pasted);
        $uploads = wp_upload_dir(null, false);
        $state = self::exporter()->begin($code, new Api($code), $uploads['basedir'], $site);
        update_option(self::OPTION, $state, false);
        return $state;
    }

    /** Runs the export for up to `$seconds` and saves where it got to. */
    public static function step(array $state, float $seconds, ?callable $heartbeat = null): array
    {
        $state = self::exporter()->run($state, new Api(self::code($state['code'])), $seconds, $heartbeat);
        update_option(self::OPTION, $state, false);
        return $state;
    }

    // Requests from the page. Each answers { step, percent, message } or { error }.

    private static function guard(): void
    {
        if (!current_user_can('manage_options')) wp_send_json_error(['error' => __('Only an administrator can move this site.', 'anypress-export')], 403);
        check_ajax_referer(self::NONCE);
    }

    private static function answer(array $state): void
    {
        wp_send_json_success(Exporter::progress($state) + ['store' => $state['store'] ?? []]);
    }

    private static function fail(\Throwable $e): void
    {
        wp_send_json_error(['error' => $e->getMessage()], 400);
    }

    public static function ajax_begin(): void
    {
        self::guard();
        try {
            // The nonce is checked in guard().
            self::answer(self::begin(sanitize_textarea_field(wp_unslash($_POST['code'] ?? '')))); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    public static function ajax_step(): void
    {
        self::guard();
        $state = get_option(self::OPTION);
        if (!is_array($state)) wp_send_json_error(['error' => __('No move in progress. Paste your import code to start.', 'anypress-export')], 400);
        // One step at a time: a second tab or a retried request waits for this one.
        if (get_transient(self::LOCK)) self::answer($state);
        set_transient(self::LOCK, 1, 120);
        if (function_exists('set_time_limit')) {
            set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- a long export step, run in small time slices.
        }
        ignore_user_abort(true);
        $max = (int) ini_get('max_execution_time');
        $seconds = $max > 0 ? max(5, min(20, $max / 2)) : 20;
        try {
            // A long upload keeps the lock, so a reload waits for it instead of starting a second upload.
            $state = self::step($state, $seconds, function () {
                set_transient(self::LOCK, 1, 120);
            });
            delete_transient(self::LOCK);
            self::answer($state);
        } catch (\Throwable $e) {
            delete_transient(self::LOCK);
            self::fail($e);
        }
    }

    public static function ajax_preflight(): void
    {
        self::guard();
        try {
            $report = self::preflight(true);
            wp_send_json_success(['notes' => $report['notes'], 'blocked' => (bool) $report['blockers']]);
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    public static function ajax_status(): void
    {
        self::guard();
        $state = get_option(self::OPTION);
        if (!is_array($state)) wp_send_json_error(['error' => __('No move in progress.', 'anypress-export')], 400);
        try {
            wp_send_json_success((new Api(self::code($state['code'])))->status());
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    public static function ajax_cancel(): void
    {
        self::guard();
        $state = get_option(self::OPTION);
        if (is_array($state) && !empty($state['work'])) Exporter::remove($state['work']);
        delete_option(self::OPTION);
        delete_transient(self::LOCK);
        wp_send_json_success([]);
    }

    public static function page(): void
    {
        ?>
        <div class="wrap" id="anypress-export">
            <h1><?php esc_html_e('Move to AnyPress', 'anypress-export'); ?></h1>
            <p style="max-width:640px"><?php esc_html_e('This copies your whole site (pages, posts, products, users, settings, plugins, themes and media) to the AnyPress store waiting for it. Nothing here is changed or deleted, and your site keeps working while it moves. Once AnyPress has it, you check it on its new address, then point your domain at it.', 'anypress-export'); ?></p>

            <div data-view="start" hidden>
                <label for="anypress-export-code"><strong><?php esc_html_e('Import code', 'anypress-export'); ?></strong></label>
                <p class="description"><?php esc_html_e('On AnyPress, choose Move a site in. It shows a code starting with AP1. Paste it here.', 'anypress-export'); ?></p>
                <textarea id="anypress-export-code" rows="3" class="large-text code" style="max-width:640px" placeholder="AP1."></textarea>
                <p><button type="button" class="button button-primary" data-action="begin"><?php esc_html_e('Send this site to AnyPress', 'anypress-export'); ?></button></p>
            </div>

            <div data-view="progress" hidden style="max-width:640px">
                <p data-field="store"></p>
                <div style="background:#dcdcde;border-radius:4px;height:12px;overflow:hidden"><div data-field="bar" style="background:#2271b1;height:12px;width:0"></div></div>
                <p data-field="message"></p>
                <p class="description" data-field="hint"><?php esc_html_e('Keep this page open until the upload finishes. If you close it, come back and it carries on where it stopped.', 'anypress-export'); ?></p>
                <p><button type="button" class="button" data-action="cancel"><?php esc_html_e('Cancel', 'anypress-export'); ?></button></p>
            </div>

            <div data-field="error" class="notice notice-error inline" hidden style="max-width:640px"><p></p></div>

            <div data-field="notes" style="max-width:640px" hidden></div>
        </div>
        <?php
    }
}

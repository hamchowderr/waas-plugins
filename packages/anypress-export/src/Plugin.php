<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

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

    public static function boot(string $file): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        foreach (['begin', 'step', 'status', 'cancel'] as $action) {
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
        add_management_page(__('Move to AnyPress', 'anypress-export'), __('Move to AnyPress', 'anypress-export'), 'manage_options', self::PAGE, [self::class, 'page']);
    }

    /** What waas.json tells AnyPress about this site. */
    public static function siteInfo(): array
    {
        global $wpdb;
        return [
            'home' => home_url(),
            'siteurl' => site_url(),
            'name' => get_bloginfo('name'),
            'wordPressVersion' => get_bloginfo('version'),
            'phpVersion' => PHP_VERSION,
            'tablePrefix' => $wpdb->prefix,
            'multisite' => is_multisite(),
            'exportedBy' => 'anypress-export ' . ANYPRESS_EXPORT_VERSION,
            'exportedAt' => gmdate('c'),
        ];
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
        if (is_multisite()) throw new \RuntimeException('Multisite networks cannot be moved to AnyPress yet.');
        if (!function_exists('deflate_init')) throw new \RuntimeException('This server cannot compress files (the zlib extension for PHP is missing). Ask your host to turn it on.');
        $old = get_option(self::OPTION);
        if (is_array($old) && !empty($old['work'])) Exporter::remove($old['work']);
        $code = self::code($pasted);
        $uploads = wp_upload_dir(null, false);
        $state = self::exporter()->begin($code, new Api($code), $uploads['basedir'], self::siteInfo());
        update_option(self::OPTION, $state, false);
        return $state;
    }

    /** Runs the export for up to `$seconds` and saves where it got to. */
    public static function step(array $state, float $seconds): array
    {
        $state = self::exporter()->run($state, new Api(self::code($state['code'])), $seconds);
        update_option(self::OPTION, $state, false);
        return $state;
    }

    // Requests from the page. Each answers { step, percent, message } or { error }.

    private static function guard(): void
    {
        if (!current_user_can('manage_options')) wp_send_json_error(['error' => 'Only an administrator can move this site.'], 403);
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
            self::answer(self::begin((string) wp_unslash($_POST['code'] ?? '')));
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    public static function ajax_step(): void
    {
        self::guard();
        $state = get_option(self::OPTION);
        if (!is_array($state)) wp_send_json_error(['error' => 'No move in progress. Paste your import code to start.'], 400);
        // One step at a time: a second tab or a retried request waits for this one.
        if (get_transient(self::LOCK)) self::answer($state);
        set_transient(self::LOCK, 1, 120);
        @set_time_limit(0);
        ignore_user_abort(true);
        $max = (int) ini_get('max_execution_time');
        $seconds = $max > 0 ? max(5, min(20, $max / 2)) : 20;
        try {
            $state = self::step($state, $seconds);
            delete_transient(self::LOCK);
            self::answer($state);
        } catch (\Throwable $e) {
            delete_transient(self::LOCK);
            self::fail($e);
        }
    }

    public static function ajax_status(): void
    {
        self::guard();
        $state = get_option(self::OPTION);
        if (!is_array($state)) wp_send_json_error(['error' => 'No move in progress.'], 400);
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
        $state = get_option(self::OPTION);
        $progress = is_array($state) ? Exporter::progress($state) + ['store' => $state['store'] ?? []] : null;
        $config = [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'progress' => $progress,
        ];
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
        </div>
        <script>
        (function () {
            var config = <?php echo wp_json_encode($config); ?>;
            var root = document.getElementById('anypress-export');
            var $ = function (sel) { return root.querySelector(sel); };
            var view = function (name) {
                $('[data-view="start"]').hidden = name !== 'start';
                $('[data-view="progress"]').hidden = name !== 'progress';
            };
            var error = function (text) {
                var box = $('[data-field="error"]');
                box.hidden = !text;
                box.querySelector('p').textContent = text || '';
            };
            var request = function (action, data) {
                var body = new URLSearchParams(Object.assign({ action: 'anypress_export_' + action, _ajax_nonce: config.nonce }, data || {}));
                return fetch(config.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (res) {
                    return res.json().catch(function () { return { success: false, data: { error: 'The server stopped answering (HTTP ' + res.status + '). Reload this page to carry on.' } }; });
                }).then(function (json) {
                    if (!json.success) throw new Error((json.data && json.data.error) || 'Something went wrong.');
                    return json.data;
                });
            };
            var show = function (p) {
                view('progress');
                var store = p.store || {};
                $('[data-field="store"]').textContent = store.domain ? 'Moving to ' + store.domain + (store.size ? ' (' + store.size + ')' : '') : '';
                $('[data-field="bar"]').style.width = p.percent + '%';
                $('[data-field="message"]').textContent = p.message;
            };
            var poll = function () {
                request('status').then(function (s) {
                    if (s.status === 'done') {
                        show({ percent: 100, message: 'Done. Your site is on AnyPress: ' + s.url, store: s.store });
                        $('[data-field="hint"]').textContent = 'Check it there, then connect your domain on AnyPress. You can deactivate and delete this plugin now.';
                        $('[data-action="cancel"]').hidden = true;
                        return;
                    }
                    if (s.status === 'failed') {
                        error('AnyPress could not set up your site. Contact AnyPress support; your site here is unchanged.');
                        return;
                    }
                    setTimeout(poll, 10000);
                }).catch(function (e) { error(e.message); setTimeout(poll, 30000); });
            };
            var step = function () {
                request('step').then(function (p) {
                    show(p);
                    if (p.step === 'moving') {
                        $('[data-field="hint"]').textContent = 'The upload is done. You can close this page; AnyPress carries on without it.';
                        poll();
                    } else {
                        setTimeout(step, 300);
                    }
                }).catch(function (e) { error(e.message + ' Reload this page to try again from where it stopped.'); });
            };
            $('[data-action="begin"]').addEventListener('click', function () {
                error('');
                request('begin', { code: $('#anypress-export-code').value }).then(function (p) { show(p); step(); }).catch(function (e) { error(e.message); });
            });
            $('[data-action="cancel"]').addEventListener('click', function () {
                if (!confirm('Stop moving this site? What was packed so far is deleted.')) return;
                request('cancel').then(function () { error(''); view('start'); });
            });
            if (config.progress) {
                show(config.progress);
                if (config.progress.step === 'moving') poll(); else step();
            } else {
                view('start');
            }
        })();
        </script>
        <?php
    }
}

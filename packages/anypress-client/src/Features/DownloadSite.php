<?php

namespace AnyPressClient\Features;

use AnyPressClient\Core\SignedRequest;

/**
 * Tools > Download my site: a copy of the whole website (WordPress, every file, the database and a README on restoring
 * it) as one zip that restores on any WordPress host. The AnyPress API makes the copy on the server and gives a link
 * that works for an hour; this page asks it, signing with the store's site key, so only this website gets its own copy.
 * Administrators only (manage_options). Shown on stores that have a site key.
 */
class DownloadSite
{
    public const PAGE = 'anypress-download-site';
    public const ACTION = 'anypress_download_site';
    public const API_ROUTE = '/sites/download';

    /** @var callable(string, array): (array|\WP_Error) */
    private $post;

    public function __construct(?callable $post = null)
    {
        $this->post = $post ?? 'wp_remote_post';
        if (!PluginBootstrap::is_store() || SignedRequest::key() === '') {
            return;
        }
        add_action('admin_menu', [$this, 'add_page']);
        add_action('admin_post_' . self::ACTION, [$this, 'start']);
        add_action('admin_enqueue_scripts', [$this, 'scripts']);
    }

    public function add_page(): void
    {
        add_management_page(__('Download my site', 'anypress-client'), __('Download my site', 'anypress-client'), 'manage_options', self::PAGE, [$this, 'render']);
    }

    /**
     * The API's answer: {download: null|{statusCode, fileName, sizeBytes, dateDone, expiresAt, url, ...}}, or null when
     * it couldn't be reached.
     */
    public function ask(bool $start): ?array
    {
        $body = (string) wp_json_encode(array_merge(
            ['site' => strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))],
            $start ? ['start' => true] : []
        ));
        $response = ($this->post)(EditorAssistant::api_url() . self::API_ROUTE, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-AnyPress-Signature' => SignedRequest::sign(SignedRequest::key(), 'POST', self::API_ROUTE, $body),
            ],
            'body' => $body,
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $answer = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($answer) && array_key_exists('download', $answer) ? $answer : null;
    }

    /** "Make a copy": starts one, then back to the page, which waits for it. */
    public function start(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'anypress-client'), 403);
        }
        check_admin_referer(self::ACTION);
        $answer = $this->ask(true);
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'started' => $answer === null ? 'failed' : '1'], admin_url('tools.php')));
        exit;
    }

    /** While a copy is being made, the page looks again every 10 seconds. */
    public function scripts(string $hook): void
    {
        if ($hook !== 'tools_page_' . self::PAGE) {
            return;
        }
        wp_register_script('anypress-download-site', false, [], ANYPRESS_CLIENT_VERSION, true);
        wp_enqueue_script('anypress-download-site');
        wp_add_inline_script(
            'anypress-download-site',
            "document.querySelector('[data-anypress-making]') && setTimeout(function () { window.location.reload(); }, 10000);"
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $answer = $this->ask(false);
        $download = is_array($answer['download'] ?? null) ? $answer['download'] : null;
        $status = $download ? (int) ($download['statusCode'] ?? -1) : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only says how the last press went; changes nothing.
        $started = isset($_GET['started']) ? sanitize_key(wp_unslash($_GET['started'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Download my site', 'anypress-client'); ?></h1>
            <p style="max-width:44em"><?php esc_html_e('Take a full copy of this website with you: WordPress, your themes, plugins and uploads, and the whole database, in one zip. It includes a README that walks through putting it up at any WordPress host. The website keeps running while the copy is made.', 'anypress-client'); ?></p>

            <?php if ($started === 'failed' || $answer === null) : ?>
                <div class="notice notice-error inline"><p><?php esc_html_e('The copy service could not be reached just now. Try again in a minute.', 'anypress-client'); ?></p></div>
            <?php endif; ?>

            <div class="card" style="max-width:44em">
                <?php if ($status === 0) : ?>
                    <p data-anypress-making><span class="spinner is-active" style="float:none;margin:0 6px 0 0"></span><strong><?php esc_html_e('Making the copy…', 'anypress-client'); ?></strong></p>
                    <p class="description"><?php esc_html_e('Usually a few minutes; sites with large media libraries take longer. This page updates on its own, and you can leave it and come back.', 'anypress-client'); ?></p>
                <?php elseif ($status === 1 && !empty($download['url'])) : ?>
                    <p><strong><?php echo esc_html((string) $download['fileName']); ?></strong></p>
                    <p class="description">
                        <?php echo esc_html(sprintf(
                            /* translators: 1: size, such as 46 MB, 2: when the copy was made, 3: the date it is deleted */
                            __('%1$s, made %2$s ago. Kept until %3$s.', 'anypress-client'),
                            size_format((int) ($download['sizeBytes'] ?? 0)),
                            human_time_diff((int) strtotime((string) ($download['dateDone'] ?? $download['dateCreated'] ?? 'now'))),
                            wp_date(get_option('date_format'), (int) strtotime((string) $download['expiresAt']))
                        )); ?>
                    </p>
                    <p><a class="button button-primary" href="<?php echo esc_url((string) $download['url']); ?>"><?php esc_html_e('Download zip', 'anypress-client'); ?></a></p>
                <?php elseif ($status === 2) : ?>
                    <p><?php esc_html_e('The last copy could not be made. Your website itself is fine. Try again.', 'anypress-client'); ?></p>
                <?php elseif ($answer !== null) : ?>
                    <p><?php esc_html_e('There is no copy from the last week yet.', 'anypress-client'); ?></p>
                <?php endif; ?>

                <?php if ($status !== 0) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                        <?php wp_nonce_field(self::ACTION); ?>
                        <?php submit_button($status === 1 ? __('Make a new copy', 'anypress-client') : __('Make a copy', 'anypress-client'), $status === 1 ? 'secondary' : 'primary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            </div>
            <p class="description"><?php esc_html_e('Copies are kept for a week. Each download link works for an hour; open this page again for a fresh one.', 'anypress-client'); ?></p>
        </div>
        <?php
    }
}

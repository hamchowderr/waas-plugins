<?php

namespace WaaSClient\Features;

use WaaSClient\Core\SignedRequest;

/**
 * The website assistant beside the page: an "Assistant" panel in the block editor and the Site Editor, and an
 * Assistant button in the admin bar that opens it. The panel frames the assistant's chat (assistant.anypress.io/editor)
 * and passes it what the editor has open; the assistant's changes come back and land in the editor, where the owner
 * sees them, can undo them, and keeps them by pressing Save (assets/editor-assistant.js).
 *
 * The chat signs in by itself: this store asks the AnyPress API for a one-time handoff, signed with its site key, for
 * administrators who can change the site's design. Stores only: without a site key there is no panel.
 */
class EditorAssistant
{
    const CAPABILITY = 'edit_theme_options';
    const ROUTE_NAMESPACE = 'waas-client/v1';
    const ROUTE = '/assistant/handoff';
    const API_ROUTE = '/oauth/site/store-handoff';
    /** Opens the panel when the editor loads (the admin bar's button). */
    const OPEN_PARAM = 'anypress-assistant';

    public function __construct()
    {
        add_action('enqueue_block_editor_assets', [$this, 'enqueue']);
        add_action('rest_api_init', [$this, 'register_route']);
        add_action('admin_bar_menu', [$this, 'admin_bar'], 80);
    }

    public static function api_url(): string
    {
        return rtrim(self::setting('WAAS_API_URL', 'https://api.anypress.io'), '/');
    }

    public static function assistant_url(): string
    {
        return rtrim(self::setting('WAAS_ASSISTANT_URL', 'https://assistant.anypress.io'), '/');
    }

    /** A constant, then an environment variable, then the default. */
    private static function setting(string $name, string $default): string
    {
        if (defined($name) && is_string(constant($name)) && constant($name) !== '') {
            return constant($name);
        }
        $env = getenv($name);

        return is_string($env) && $env !== '' ? $env : $default;
    }

    public static function is_available(): bool
    {
        return SignedRequest::key() !== '' && current_user_can(self::CAPABILITY);
    }

    public function enqueue(): void
    {
        if (!self::is_available()) {
            return;
        }
        $base = defined('WAAS_PLUGIN_DIR_URI') ? WAAS_PLUGIN_DIR_URI : plugin_dir_url(dirname(__DIR__, 2) . '/index.php');
        wp_enqueue_script(
            'waas-editor-assistant',
            $base . 'assets/editor-assistant.js',
            ['wp-plugins', 'wp-editor', 'wp-element', 'wp-data', 'wp-blocks', 'wp-api-fetch', 'wp-notices', 'wp-i18n', 'wp-dom-ready'],
            WAAS_CLIENT_VERSION,
            true
        );
        wp_add_inline_script(
            'waas-editor-assistant',
            'window.waasEditorAssistant = ' . wp_json_encode([
                'assistantUrl' => self::assistant_url(),
                'handoffPath' => '/' . self::ROUTE_NAMESPACE . self::ROUTE,
                'openParam' => self::OPEN_PARAM,
            ]) . ';',
            'before'
        );
        wp_enqueue_style('waas-editor-assistant', $base . 'assets/editor-assistant.css', [], WAAS_CLIENT_VERSION);
    }

    public function register_route(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'handoff'],
            'permission_callback' => [self::class, 'is_available'],
        ]);
    }

    /**
     * Asks the API for a one-time sign-in to the assistant for this website.
     *
     * @return array{url: string}|\WP_Error
     */
    public function handoff()
    {
        $body = wp_json_encode([
            'site' => strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)),
            'assistantUrl' => self::assistant_url(),
        ]);
        $response = wp_remote_post(self::api_url() . self::API_ROUTE, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Waas-Signature' => SignedRequest::sign(SignedRequest::key(), 'POST', self::API_ROUTE, $body),
            ],
            'body' => $body,
        ]);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $answer = $code === 200 ? json_decode((string) wp_remote_retrieve_body($response), true) : null;
        $url = is_array($answer) && is_string($answer['url'] ?? null) ? $answer['url'] : '';
        // Only a sign-in to our own assistant goes into the editor's frame.
        if ($url === '' || strpos($url, self::assistant_url() . '/') !== 0) {
            return new \WP_Error('waas_assistant_unavailable', __('The assistant can’t be reached right now. Try again in a minute.', 'waas-client'), ['status' => 502]);
        }

        return ['url' => $url];
    }

    /** "Assistant" in the admin bar: opens the editor for the page being viewed, or the Site Editor, with the panel open. */
    public function admin_bar(\WP_Admin_Bar $bar): void
    {
        if (!self::is_available()) {
            return;
        }
        $target = admin_url('site-editor.php');
        if (!is_admin() && is_singular()) {
            $post = get_queried_object();
            if ($post instanceof \WP_Post && current_user_can('edit_post', $post->ID)) {
                $target = (string) get_edit_post_link($post->ID, 'raw');
            }
        }
        $bar->add_node([
            'id' => 'anypress-assistant',
            'title' => '<span class="ab-icon dashicons dashicons-format-chat" aria-hidden="true" style="margin-top:2px"></span><span class="ab-label">' . esc_html__('Assistant', 'waas-client') . '</span>',
            'href' => add_query_arg(self::OPEN_PARAM, '1', $target),
            'meta' => ['title' => __('Change this page in plain words', 'waas-client')],
        ]);
    }
}

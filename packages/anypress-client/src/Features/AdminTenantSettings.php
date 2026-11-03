<?php

namespace AnyPressClient\Features;

use AnyPressClient\Core\Brand;
use AnyPressClient\Core\HttpService;

/**
 * AnyPress Client's settings page, on the template website only (stores never show it): the storefront this
 * template's plan roles are sent to.
 */
class AdminTenantSettings
{
    const PAGE = 'anypress-admin-tenant';
    const TEST_ACTION = 'anypress_test_storefront';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_anypress_admin_page'], 11);
        add_action('admin_enqueue_scripts', [$this, 'add_admin_styles']);
        add_action('admin_post_' . self::TEST_ACTION, [$this, 'test_storefront_connection']);
        add_action('admin_init', [$this, 'add_anypress_admin_settings']);
        add_filter("pre_update_option_anypress_host_website_url", [__CLASS__, 'normalize_host_url']);
        add_filter('plugin_action_links_' . ANYPRESS_CLIENT_BASENAME, [$this, 'add_settings_link']);
    }

    /** Stored as https://<host>, whatever scheme was typed; empty stays empty. */
    public static function normalize_host_url($value): string
    {
        $host = trim(preg_replace('/^(http|https):\/\//i', '', trim((string) $value)), '/');

        return $host === '' ? '' : 'https://' . $host;
    }

    public function add_settings_link($links)
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">' . esc_html__('Settings', 'anypress-client') . '</a>');

        return $links;
    }

    public function add_anypress_admin_page()
    {
        add_menu_page(
            Brand::plugin_name(),
            Brand::plugin_name(),
            'manage_options',
            self::PAGE,
            [$this, 'render_anypress_admin_tenant_page'],
            'dashicons-networking',
            10
        );

        add_submenu_page(
            self::PAGE,
            /* translators: %s: the plugin's name */
            sprintf(__('%s settings', 'anypress-client'), Brand::plugin_name()),
            __('Settings', 'anypress-client'),
            'manage_options',
            self::PAGE,
            [$this, 'render_anypress_admin_tenant_page']
        );
    }

    public function add_admin_styles($hook)
    {
        if (strpos((string) $hook, self::PAGE) === false) {
            return;
        }

        wp_enqueue_style('anypress-admin-styles', ANYPRESS_PLUGIN_DIR_URI . 'assets/style.css', [], ANYPRESS_CLIENT_VERSION);
    }

    /** admin-post handler for the Test connection button: a harmless read of the storefront's API index. */
    public function test_storefront_connection()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'anypress-client'), 403);
        }
        check_admin_referer(self::TEST_ACTION);

        $host_url = (string) get_option('anypress_host_website_url', '');
        StorefrontStatus::test_connection(new HttpService($host_url . '/wp-json/' . StorefrontStatus::API_NAMESPACE), $host_url);

        wp_safe_redirect(add_query_arg('anypress-tested', '1', admin_url('admin.php?page=' . self::PAGE)));
        exit;
    }

    public function render_anypress_admin_tenant_page()
    {
        $host_url = (string) get_option('anypress_host_website_url', '');
        $last_test = StorefrontStatus::last(StorefrontStatus::TEST_OPTION);
        $last_ping = StorefrontStatus::last(StorefrontStatus::PING_OPTION);

        echo '<div class="wrap anypress-client-admin">';
        echo '<h1>' . esc_html(sprintf(/* translators: %s: the plugin's name */ __('%s settings', 'anypress-client'), Brand::plugin_name())) . '</h1>';
        settings_errors();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a flag this page sets on itself after the test, for the notice.
        if (isset($_GET['anypress-tested']) && $last_test !== null) {
            StorefrontStatus::render_notice($last_test);
        }

        echo '<h2>' . esc_html__('Status', 'anypress-client') . '</h2>';
        echo '<table class="form-table anypress-status-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Storefront URL', 'anypress-client') . '</th><td>';
        if ($host_url === '') {
            echo '<span class="anypress-status anypress-status-error"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span> '
                . esc_html__('Not set. Plan role changes cannot reach a storefront until you add it below.', 'anypress-client') . '</span>';
        } else {
            echo '<span class="anypress-status anypress-status-success"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> '
                . esc_html__('Set:', 'anypress-client') . '</span> <a href="' . esc_url($host_url) . '" target="_blank" rel="noopener"><code>' . esc_html($host_url) . '</code></a>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Connection', 'anypress-client') . '</th><td>';
        echo '<p>' . StorefrontStatus::describe($last_test, __('Not tested yet.', 'anypress-client')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- describe() escapes.
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="anypress-inline-form">';
        wp_nonce_field(self::TEST_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::TEST_ACTION) . '">';
        submit_button(__('Test connection', 'anypress-client'), 'secondary', 'submit', false, $host_url === '' ? ['disabled' => 'disabled'] : []);
        echo '</form>';
        echo '<p class="description">' . esc_html__('Checks that the storefront answers and has AnyPress Host active. It does not change or reload anything.', 'anypress-client') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Last plan role update', 'anypress-client') . '</th><td>';
        echo '<p>' . StorefrontStatus::describe($last_ping, __('Not sent yet. The storefront is told each time you add a role or save the plan roles.', 'anypress-client')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- describe() escapes.
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Where the storefront gets roles', 'anypress-client') . '</th><td>';
        echo '<p>' . esc_html__('The storefront always loads plan roles from the template version labelled Production in the AnyPress dashboard, not from whichever website sent the update. Changes you make here reach the storefront once this website\'s version is labelled Production and deployed.', 'anypress-client') . '</p>';
        /* translators: %s: this website's address */
        echo '<p class="description">' . sprintf(esc_html__('This website: %s', 'anypress-client'), '<code>' . esc_html(home_url()) . '</code>') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        echo '<form method="post" action="options.php">';
        settings_fields(self::PAGE);
        do_settings_sections(self::PAGE);
        submit_button();
        echo '</form></div>';
    }

    public function add_anypress_admin_settings()
    {
        add_settings_section(
            'anypress_admin_tenant_settings',
            __('Template website', 'anypress-client'),
            fn () => print('<p>' . esc_html__('This is a template website: customer websites are created as copies of it. Set up its plan roles under AnyPress Client > Plan roles; each website gets the plugins of its plan\'s role.', 'anypress-client') . '</p>'),
            self::PAGE
        );

        register_setting(self::PAGE, 'anypress_host_website_url', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        add_settings_field(
            'anypress_host_website_url',
            __('Storefront URL', 'anypress-client'),
            [$this, 'render_settings_field'],
            self::PAGE,
            'anypress_admin_tenant_settings',
            [
                'id' => 'anypress_host_website_url',
                'label_for' => 'anypress_host_website_url',
                'type' => 'text',
                'placeholder' => 'https://shop.example.com',
                'hint' => __('The storefront (with AnyPress Host) that sells websites made from this template. When you change the plan roles, the storefront is told to reload them.', 'anypress-client'),
            ]
        );
    }

    function render_settings_field($args)
    {
        printf(
            '<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" placeholder="%4$s" class="regular-text code" aria-describedby="%2$s-description" />',
            esc_attr($args['type']),
            esc_attr($args['id']),
            esc_attr((string) get_option($args['id'], '')),
            esc_attr($args['placeholder'] ?? '')
        );
        if (!empty($args['hint'])) {
            echo '<p class="description" id="' . esc_attr($args['id'] . '-description') . '">' . esc_html($args['hint']) . '</p>';
        }
    }
}

<?php

namespace WaaSClient\Features;

use WaaSClient\Core\HttpService;

/**
 * WaaS Client's settings page, on the template website only (stores never show it): the storefront this
 * template's plan roles are sent to.
 */
class AdminTenantSettings
{
    const PAGE = 'waas-admin-tenant';
    const TEST_ACTION = 'waas_test_storefront';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_waas_admin_page'], 11);
        add_action('admin_enqueue_scripts', [$this, 'add_admin_styles']);
        add_action('admin_post_' . self::TEST_ACTION, [$this, 'test_storefront_connection']);
        add_action('admin_init', [$this, 'add_waas_admin_settings']);
        add_filter("pre_update_option_waas_host_website_url", [__CLASS__, 'normalize_host_url']);
        add_filter('plugin_action_links_' . WAAS_CLIENT_BASENAME, [$this, 'add_settings_link']);
    }

    /** Stored as https://<host>, whatever scheme was typed; empty stays empty. */
    public static function normalize_host_url($value): string
    {
        $host = trim(preg_replace('/^(http|https):\/\//i', '', trim((string) $value)), '/');

        return $host === '' ? '' : 'https://' . $host;
    }

    public function add_settings_link($links)
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE)) . '">' . esc_html__('Settings', WAAS_CLIENT_TEXTDOMAIN) . '</a>');

        return $links;
    }

    public function add_waas_admin_page()
    {
        add_menu_page(
            __('WaaS Client', WAAS_CLIENT_TEXTDOMAIN),
            __('WaaS Client', WAAS_CLIENT_TEXTDOMAIN),
            'manage_options',
            self::PAGE,
            [$this, 'render_waas_admin_tenant_page'],
            'dashicons-networking',
            10
        );

        add_submenu_page(
            self::PAGE,
            __('WaaS Client settings', WAAS_CLIENT_TEXTDOMAIN),
            __('Settings', WAAS_CLIENT_TEXTDOMAIN),
            'manage_options',
            self::PAGE,
            [$this, 'render_waas_admin_tenant_page']
        );
    }

    public function add_admin_styles($hook)
    {
        if (strpos((string) $hook, self::PAGE) === false) {
            return;
        }

        wp_enqueue_style('waas-admin-styles', WAAS_PLUGIN_DIR_URI . 'assets/style.css', [], WAAS_CLIENT_VERSION);
    }

    /** admin-post handler for the Test connection button: a harmless read of the storefront's API index. */
    public function test_storefront_connection()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', WAAS_CLIENT_TEXTDOMAIN), 403);
        }
        check_admin_referer(self::TEST_ACTION);

        $host_url = (string) get_option('waas_host_website_url', '');
        StorefrontStatus::test_connection(new HttpService($host_url . '/wp-json/' . StorefrontStatus::API_NAMESPACE), $host_url);

        wp_safe_redirect(add_query_arg('waas-tested', '1', admin_url('admin.php?page=' . self::PAGE)));
        exit;
    }

    public function render_waas_admin_tenant_page()
    {
        $host_url = (string) get_option('waas_host_website_url', '');
        $last_test = StorefrontStatus::last(StorefrontStatus::TEST_OPTION);
        $last_ping = StorefrontStatus::last(StorefrontStatus::PING_OPTION);

        echo '<div class="wrap waas-client-admin">';
        echo '<h1>' . esc_html__('WaaS Client settings', WAAS_CLIENT_TEXTDOMAIN) . '</h1>';
        settings_errors();
        if (isset($_GET['waas-tested']) && $last_test !== null) {
            StorefrontStatus::render_notice($last_test);
        }

        echo '<h2>' . esc_html__('Status', WAAS_CLIENT_TEXTDOMAIN) . '</h2>';
        echo '<table class="form-table waas-status-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Storefront URL', WAAS_CLIENT_TEXTDOMAIN) . '</th><td>';
        if ($host_url === '') {
            echo '<span class="waas-status waas-status-error"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span> '
                . esc_html__('Not set. Plan role changes cannot reach a storefront until you add it below.', WAAS_CLIENT_TEXTDOMAIN) . '</span>';
        } else {
            echo '<span class="waas-status waas-status-success"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> '
                . esc_html__('Set:', WAAS_CLIENT_TEXTDOMAIN) . '</span> <a href="' . esc_url($host_url) . '" target="_blank" rel="noopener"><code>' . esc_html($host_url) . '</code></a>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Connection', WAAS_CLIENT_TEXTDOMAIN) . '</th><td>';
        echo '<p>' . StorefrontStatus::describe($last_test, __('Not tested yet.', WAAS_CLIENT_TEXTDOMAIN)) . '</p>'; // describe() escapes.
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="waas-inline-form">';
        wp_nonce_field(self::TEST_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::TEST_ACTION) . '">';
        submit_button(__('Test connection', WAAS_CLIENT_TEXTDOMAIN), 'secondary', 'submit', false, $host_url === '' ? ['disabled' => 'disabled'] : []);
        echo '</form>';
        echo '<p class="description">' . esc_html__('Checks that the storefront answers and has WaaS Host active. It does not change or reload anything.', WAAS_CLIENT_TEXTDOMAIN) . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Last plan role update', WAAS_CLIENT_TEXTDOMAIN) . '</th><td>';
        echo '<p>' . StorefrontStatus::describe($last_ping, __('Not sent yet. The storefront is told each time you add a role or save the plan roles.', WAAS_CLIENT_TEXTDOMAIN)) . '</p>'; // describe() escapes.
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Where the storefront gets roles', WAAS_CLIENT_TEXTDOMAIN) . '</th><td>';
        echo '<p>' . esc_html__('The storefront always loads plan roles from the template version labelled Production in WaaS, not from whichever website sent the update. Changes you make here reach the storefront once this website\'s version is labelled Production and deployed.', WAAS_CLIENT_TEXTDOMAIN) . '</p>';
        /* translators: %s: this website's address */
        echo '<p class="description">' . sprintf(esc_html__('This website: %s', WAAS_CLIENT_TEXTDOMAIN), '<code>' . esc_html(home_url()) . '</code>') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        echo '<form method="post" action="options.php">';
        settings_fields(self::PAGE);
        do_settings_sections(self::PAGE);
        submit_button();
        echo '</form></div>';
    }

    public function add_waas_admin_settings()
    {
        add_settings_section(
            'waas_admin_tenant_settings',
            __('Template website', WAAS_CLIENT_TEXTDOMAIN),
            fn () => print('<p>' . esc_html__('This is a template website: customer websites are created as copies of it. Set up its plan roles under WaaS Client > Plan roles; each website gets the plugins of its plan\'s role.', WAAS_CLIENT_TEXTDOMAIN) . '</p>'),
            self::PAGE
        );

        register_setting(self::PAGE, 'waas_host_website_url', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        add_settings_field(
            'waas_host_website_url',
            __('Storefront URL', WAAS_CLIENT_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            self::PAGE,
            'waas_admin_tenant_settings',
            [
                'id' => 'waas_host_website_url',
                'label_for' => 'waas_host_website_url',
                'type' => 'text',
                'placeholder' => 'https://shop.example.com',
                'hint' => __('The storefront (with WaaS Host) that sells websites made from this template. When you change the plan roles, the storefront is told to reload them.', WAAS_CLIENT_TEXTDOMAIN),
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

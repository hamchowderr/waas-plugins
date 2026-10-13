<?php

namespace WaaSClient\Features;

/**
 * WaaS Client's settings page, on the template website only (stores never show it): the storefront this
 * template's plan roles are sent to.
 */
class AdminTenantSettings
{
    const PAGE = 'waas-admin-tenant';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_waas_admin_page'], 11);
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

    public function render_waas_admin_tenant_page()
    {
        echo '<div class="wrap waas-client-admin">';
        echo '<h1>' . esc_html__('WaaS Client settings', WAAS_CLIENT_TEXTDOMAIN) . '</h1>';
        settings_errors();
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

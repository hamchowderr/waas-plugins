<?php

namespace WaaSHost\Features;

use WaaSHost\Core\ConfigService;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;

class AdminWpcsSettings
{
    const SHOULD_SEND_TENANT_READY_EMAIL_OPTION = 'wpcs_notification_settings_send_tenant_ready_email';
    const TENANT_READY_EMAIL_SUBJECT_OPTION = 'wpcs_notification_settings_tenant_ready_email_subject';
    const TENANT_READY_EMAIL_BODY_OPTION = 'wpcs_notification_settings_tenant_ready_email_body';
    const SHOULD_SEND_SUSPEND_SCHEDULED_EMAIL_OPTION = 'wpcs_notification_settings_send_suspend_scheduled_email';
    const SUSPEND_SCHEDULED_EMAIL_SUBJECT_OPTION = 'wpcs_notification_settings_suspend_scheduled_email_subject';
    const SUSPEND_SCHEDULED_EMAIL_BODY_OPTION = 'wpcs_notification_settings_suspend_scheduled_email_body';
    const SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION = 'wpcs_host_setting_remove_admininistrator_plugin_capabilities_on_tenant';

    private WPCSService $wpcsService;
    private ConfigService $wpcsConfigService;

    public function __construct(WPCSService $wpcsService, ConfigService $wpcsConfigService)
    {
        $this->wpcsService = $wpcsService;
        $this->wpcsConfigService = $wpcsConfigService;

        add_action('admin_menu', [$this, 'add_wpcs_admin_page'], 12);
        add_action('admin_init', [$this, 'add_wpcs_admin_settings']);
        add_action('init', [$this, 'register_wpcs_admin_settings']);
        add_filter('wpcs_tenant_ready_email_allowed', [$this, 'allow_tenant_ready_email']);
        add_filter('wpcs_tenant_ready_email_subject', [$this, 'tenant_ready_email_subject'], 10, 1);
        add_filter('wpcs_tenant_ready_email_body', [$this, 'tenant_ready_email_body'], 10, 1);
        add_filter('wpcs_suspend_scheduled_email_allowed', [$this, 'allow_suspend_scheduled_email']);
        add_filter('wpcs_suspend_scheduled_email_subject', [$this, 'suspend_scheduled_email_subject'], 10, 1);
        add_filter('wpcs_suspend_scheduled_email_body', [$this, 'suspend_scheduled_email_body'], 10, 1);
        add_filter('wpcs_remove_tenant_administrator_plugin_capabilities', [$this, 'remove_tenant_administrator_plugin_capabilities']);
    }

    public function add_wpcs_admin_page()
    {
        add_submenu_page(
            'wpcs-admin',
            __('WPCS.io settings', WPCS_WAAS_HOST_TEXTDOMAIN),
            __('Settings', WPCS_WAAS_HOST_TEXTDOMAIN),
            'manage_options',
            'wpcs-admin-settings',
            [$this, 'render_wpcs_admin_page'],
        );
    }

    public function render_wpcs_admin_page()
    {
        $is_wpcs_api_setup = $this->wpcsConfigService->check_credentials();

        if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
            if ($is_wpcs_api_setup) {
                try {
                    $this->wpcsService->test_reachability();
                    $this->wpcsService->update_is_reachable(true);
                } catch (\Exception $ei) {
                    $this->wpcsService->update_is_reachable(false);
                    echo $this->wpcs_could_not_connect_to_api_notice_error();
                }
            }
        } elseif ($is_wpcs_api_setup && !$this->wpcsService->is_reachable()) {
            echo $this->wpcs_could_not_connect_to_api_notice_error();
        }

        echo '<h1>WPCS.io Admin</h1><form method="POST" action="options.php">';
        settings_fields('wpcs-admin');
        do_settings_sections('wpcs-admin');
        submit_button();
        echo '</form>';
    }

    public function register_wpcs_admin_settings()
    {
        register_setting('wpcs-admin', 'wpcs_credentials_region_setting');
        register_setting('wpcs-admin', 'wpcs_credentials_api_base_url_setting', [
            'sanitize_callback' => 'esc_url_raw'
        ]);
        register_setting('wpcs-admin', 'wpcs_credentials_api_key_setting');
        register_setting('wpcs-admin', 'wpcs_credentials_api_secret_setting');
        register_setting('wpcs-admin', 'wpcs_host_settings_root_domain');
        register_setting('wpcs-admin', 'wpcs_dns_a_records_setting', [
            'sanitize_callback' => [ConfigService::class, 'sanitize_ip_list']
        ]);
        register_setting('wpcs-admin', 'wpcs_dns_cname_target_setting', [
            'sanitize_callback' => [ConfigService::class, 'sanitize_hostname']
        ]);
        register_setting('wpcs-admin', WPCSTenant::WPCS_DEFAULT_USER_ROLE, [
            'default' => 'administrator',
            'sanitize_callback' => [$this, 'sanitize_user_role']
        ]);
        register_setting('wpcs-admin', TenantsSubscriptionManager::DELETION_GRACE_DAYS_OPTION, [
            'default' => TenantsSubscriptionManager::DEFAULT_DELETION_GRACE_DAYS,
            'sanitize_callback' => fn ($value) => max(1, absint($value)),
        ]);
        register_setting('wpcs-admin', self::SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION, [
            'default' => 'on'
        ]);
        register_setting('wpcs-admin', self::TENANT_READY_EMAIL_SUBJECT_OPTION, [
            'default' => Notifications::get_default_tenant_ready_subject()
        ]);
        register_setting('wpcs-admin', self::TENANT_READY_EMAIL_BODY_OPTION, [
            'default' => Notifications::get_default_tenant_ready_body()
        ]);
        register_setting('wpcs-admin', self::SHOULD_SEND_TENANT_READY_EMAIL_OPTION, [
            'default' => 'on'
        ]);
        register_setting('wpcs-admin', self::SUSPEND_SCHEDULED_EMAIL_SUBJECT_OPTION, [
            'default' => Notifications::get_default_suspend_scheduled_subject()
        ]);
        register_setting('wpcs-admin', self::SUSPEND_SCHEDULED_EMAIL_BODY_OPTION, [
            'default' => Notifications::get_default_suspend_scheduled_body()
        ]);
        register_setting('wpcs-admin', self::SHOULD_SEND_SUSPEND_SCHEDULED_EMAIL_OPTION, [
            'default' => 'on'
        ]);
    }

    public function add_wpcs_admin_settings()
    {
        add_settings_section(
            'wpcs_credentials',
            __('WPCS Credentials', WPCS_WAAS_HOST_TEXTDOMAIN),
            fn () => "<p>Intro text for our settings section</p>",
            'wpcs-admin'
        );

        add_settings_field(
            'wpcs_credentials_region_setting',
            __('WPCS Region', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_credentials',
            [
                "id" => "wpcs_credentials_region_setting",
                "disabled" => defined('WPCS_API_REGION'),
                "hint" => defined('WPCS_API_REGION') ? __('Defined as constant', WPCS_WAAS_HOST_TEXTDOMAIN) : "",
                "type" => "select",
                "choices" => [
                    'eu1' => __('EU1', WPCS_WAAS_HOST_TEXTDOMAIN),
                    'us1' => __('US1', WPCS_WAAS_HOST_TEXTDOMAIN),
                ],
            ]
        );

        add_settings_field(
            'wpcs_credentials_api_base_url_setting',
            __('API Base URL', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_credentials',
            [
                "id" => "wpcs_credentials_api_base_url_setting",
                "disabled" => defined('WPCS_API_BASE_URL'),
                "hint" => defined('WPCS_API_BASE_URL')
                    ? __('Defined as constant', WPCS_WAAS_HOST_TEXTDOMAIN)
                    : __('The provisioning API, for example https://api.example.com. Overrides the region when set.', WPCS_WAAS_HOST_TEXTDOMAIN),
                "type" => "text",
                "styles" => [
                    "width" => "100%",
                ]
            ]
        );

        add_settings_field(
            'wpcs_credentials_api_key_setting',
            __('WPCS API Key', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_credentials',
            [
                "id" => "wpcs_credentials_api_key_setting",
                "disabled" => defined('WPCS_API_KEY'),
                "hint" => defined('WPCS_API_KEY') ? __('Defined as constant', WPCS_WAAS_HOST_TEXTDOMAIN) : "",
                "type" => "text"
            ]
        );

        add_settings_field(
            'wpcs_credentials_api_secret_setting',
            __('WPCS API Secret', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_credentials',
            [
                "id" => "wpcs_credentials_api_secret_setting",
                "disabled" => defined('WPCS_API_SECRET'),
                "hint" => defined('WPCS_API_SECRET') ? __('Defined as constant', WPCS_WAAS_HOST_TEXTDOMAIN) : "",
                "type" => "password"
            ]
        );

        add_settings_section(
            'wpcs_host_settings',
            'WPCS Host Settings',
            fn () => "<p>Intro text for our settings section</p>",
            'wpcs-admin'
        );

        add_settings_field(
            'wpcs_host_settings_root_domain',
            __('Tenants Root Domain', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_host_settings',
            [
                "id" => "wpcs_host_settings_root_domain",
                "hint" => __("The root domain your customer's tenant will be created with. If you fill out mywaas.com, an example tenant domain will be customer.mywaas.com.", WPCS_WAAS_HOST_TEXTDOMAIN),
                "type" => "text"
            ]
        );

        add_settings_field(
            'wpcs_dns_a_records_setting',
            __('Custom domain A records', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_host_settings',
            [
                "id" => "wpcs_dns_a_records_setting",
                "disabled" => defined('WPCS_DNS_A_RECORDS'),
                "hint" => defined('WPCS_DNS_A_RECORDS')
                    ? __('Defined as constant', WPCS_WAAS_HOST_TEXTDOMAIN)
                    : __("The IPs customers point their domain's A records at, separated by commas. Shown on the customer's domain page.", WPCS_WAAS_HOST_TEXTDOMAIN),
                "type" => "text",
                "styles" => [
                    "width" => "100%",
                ]
            ]
        );

        add_settings_field(
            'wpcs_dns_cname_target_setting',
            __('Custom domain CNAME target', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_host_settings',
            [
                "id" => "wpcs_dns_cname_target_setting",
                "disabled" => defined('WPCS_DNS_CNAME_TARGET'),
                "hint" => defined('WPCS_DNS_CNAME_TARGET')
                    ? __('Defined as constant', WPCS_WAAS_HOST_TEXTDOMAIN)
                    : __("The hostname customers point a subdomain's CNAME record at. Shown on the customer's domain page.", WPCS_WAAS_HOST_TEXTDOMAIN),
                "type" => "text",
                "styles" => [
                    "width" => "100%",
                ]
            ]
        );

        add_settings_field(
            WPCSTenant::WPCS_DEFAULT_USER_ROLE,
            __("Customer user role", WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_host_settings',
            [
                "id" => WPCSTenant::WPCS_DEFAULT_USER_ROLE,
                "hint" => __("The role of the user that is created when your customer logs in via the one-click login button in their my-account page.", WPCS_WAAS_HOST_TEXTDOMAIN),
                "type" => "text"
            ]
        );

        add_settings_field(
            self::SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION,
            __('Remove Administrator Plugin Capabilities on tenant creation?', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_host_settings',
            [
                "id" => self::SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION,
                "hint" => __("Check this box if you want the administrator role in your tenants to not be able to manage plugins. Enabling or disabling this will NOT have an effect on existing tenants.", WPCS_WAAS_HOST_TEXTDOMAIN),
                "type" => "checkbox",
            ]
        );

        add_settings_field(
            TenantsSubscriptionManager::DELETION_GRACE_DAYS_OPTION,
            __('Days before a cancelled website is deleted', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_host_settings',
            [
                "id" => TenantsSubscriptionManager::DELETION_GRACE_DAYS_OPTION,
                "hint" => __("When a subscription is cancelled or expires, its website is backed up and taken offline, then deleted after this many days. If the subscription becomes active again before then, the website comes back as it was.", WPCS_WAAS_HOST_TEXTDOMAIN),
                "type" => "number",
            ]
        );

        add_settings_section(
            'wpcs_notification_settings',
            __('WPCS Notification Settings', WPCS_WAAS_HOST_TEXTDOMAIN),
            fn () => "",
            'wpcs-admin'
        );

        add_settings_field(
            self::TENANT_READY_EMAIL_SUBJECT_OPTION,
            __('E-mail subject', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_notification_settings',
            [
                "id" => self::TENANT_READY_EMAIL_SUBJECT_OPTION,
                "hint" => __("The subject of the E-mail to send to your customer when their tenant is available.", WPCS_WAAS_HOST_TEXTDOMAIN),
                "type" => "text",
                "styles" => [
                    "width" => "100%",
                    "margin-right" => "1em",
                ]
            ]
        );

        add_settings_field(
            self::TENANT_READY_EMAIL_BODY_OPTION,
            __('E-mail body', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_notification_settings',
            [
                "id" => self::TENANT_READY_EMAIL_BODY_OPTION,
                "hint" => __("The body of the E-mail to send to your customer when their tenant is available.", WPCS_WAAS_HOST_TEXTDOMAIN),
                "type" => "html-editor",
                "media_buttons" => false,
            ]
        );

        add_settings_field(
            self::SHOULD_SEND_TENANT_READY_EMAIL_OPTION,
            __('Send E-mail when tenant is ready?', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_notification_settings',
            [
                "id" => self::SHOULD_SEND_TENANT_READY_EMAIL_OPTION,
                "hint" => __("Whether or not to send the default E-mail to your customer informing them that their tenant is available.", WPCS_WAAS_HOST_TEXTDOMAIN),
                "type" => "checkbox",
            ]
        );

        add_settings_field(
            self::SUSPEND_SCHEDULED_EMAIL_SUBJECT_OPTION,
            __('Cancellation e-mail subject', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_notification_settings',
            [
                "id" => self::SUSPEND_SCHEDULED_EMAIL_SUBJECT_OPTION,
                "hint" => __("The subject of the E-mail sent when a customer cancels: their website stays online until the end of the period they paid for. {{ suspend_date }} is that date.", WPCS_WAAS_HOST_TEXTDOMAIN),
                "type" => "text",
                "styles" => [
                    "width" => "100%",
                    "margin-right" => "1em",
                ]
            ]
        );

        add_settings_field(
            self::SUSPEND_SCHEDULED_EMAIL_BODY_OPTION,
            __('Cancellation e-mail body', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_notification_settings',
            [
                "id" => self::SUSPEND_SCHEDULED_EMAIL_BODY_OPTION,
                "hint" => __("The body of that E-mail. Besides the website-ready placeholders it can use {{ suspend_date }} and {{ deletion_grace_days }}.", WPCS_WAAS_HOST_TEXTDOMAIN),
                "type" => "html-editor",
                "media_buttons" => false,
            ]
        );

        add_settings_field(
            self::SHOULD_SEND_SUSPEND_SCHEDULED_EMAIL_OPTION,
            __('Send E-mail when a customer cancels?', WPCS_WAAS_HOST_TEXTDOMAIN),
            [$this, 'render_settings_field'],
            'wpcs-admin',
            'wpcs_notification_settings',
            [
                "id" => self::SHOULD_SEND_SUSPEND_SCHEDULED_EMAIL_OPTION,
                "hint" => __("Whether to tell the customer, when they cancel, until when their website stays online.", WPCS_WAAS_HOST_TEXTDOMAIN),
                "type" => "checkbox",
            ]
        );
    }

    function render_settings_field($args)
    {
        $is_disabled_att = array_key_exists('disabled', $args) && $args['disabled'] ? "disabled" : "";

        switch ($args['type']) {
            case 'checkbox':
                echo "<input type='{$args["type"]}' id='{$args["id"]}' name='{$args["id"]}' " . checked(get_option($args["id"]), "on", false) . ">";
                break;
            case 'select':
                $current_value = get_option($args["id"]);
                echo "<select {$is_disabled_att} id='{$args["id"]}' name='{$args["id"]}'>";
                foreach ($args['choices'] as $value => $label) {
                    $selected = selected($current_value, $value, false);
                    echo "<option value='{$value}' {$selected}>{$label}</option>";
                }
                echo "</select>";
                break;
            case 'html-editor':
                $current_value = get_option($args['id']);
                wp_editor($current_value, $args['id'], [
                    "media_buttons" => array_key_exists('media_buttons', $args) ? $args['media_buttons'] : false,
                    "wpautop" => false,
                ]);
                break;
            default:
                $attributes = [
                    "type" => $args["type"],
                    "id" => $args["id"],
                    "name" => $args["id"],
                    "value" => get_option($args["id"]),
                ];

                if(array_key_exists('styles', $args))
                {
                    $style_strings = array_map(function($att, $value) {
                        return sprintf('%s:%s;', $att, $value);
                    }, array_keys($args['styles']), $args['styles']);
                    $attributes['style'] = join('', $style_strings);
                }

                $attribute_strings = array_map(function($att, $value) {
                    return sprintf('%s="%s"', $att, $value);
                }, array_keys($attributes), $attributes);

                $attribute_string = join(" ", $attribute_strings);

                echo "<input {$is_disabled_att} {$attribute_string} />";
                break;
        }

        if (array_key_exists('hint', $args))
        {
            echo "<span class='wpcs-setting-hint'>{$args['hint']}</span>";
        }
    }

    function allow_tenant_ready_email($allowed)
    {
        // If somebody else disallowed, respect their decision.
        if (!$allowed) {
            return $allowed;
        }

        return get_option(self::SHOULD_SEND_TENANT_READY_EMAIL_OPTION) === 'on';
    }

    function allow_suspend_scheduled_email($allowed)
    {
        if (!$allowed) {
            return $allowed;
        }

        return get_option(self::SHOULD_SEND_SUSPEND_SCHEDULED_EMAIL_OPTION, 'on') === 'on';
    }

    function suspend_scheduled_email_subject($text)
    {
        $option_val = get_option(self::SUSPEND_SCHEDULED_EMAIL_SUBJECT_OPTION);

        return !empty($option_val) ? $option_val : $text;
    }

    function suspend_scheduled_email_body($text)
    {
        $option_val = get_option(self::SUSPEND_SCHEDULED_EMAIL_BODY_OPTION);

        return !empty($option_val) ? $option_val : $text;
    }

    function remove_tenant_administrator_plugin_capabilities($allowed)
    {
        // If somebody else disallowed, respect their decision.
        if (!$allowed) {
            return $allowed;
        }

        return get_option(self::SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION) === 'on';
    }

    function tenant_ready_email_subject($text)
    {
        $option_val = get_option(self::TENANT_READY_EMAIL_SUBJECT_OPTION);

        return !empty($option_val) ? $option_val : $text;
    }

    function tenant_ready_email_body($text)
    {
        $option_val = get_option(self::TENANT_READY_EMAIL_BODY_OPTION);

        return !empty($option_val) ? $option_val : $text;
    }

    public function wpcs_could_not_connect_to_api_notice_error()
    {
        echo '<div class="notice notice-error is-dismissible">
        <p>Important: your API Key/Secret combination or selected region seem to be incorrect, we could not connect.</p>
        </div>';
    }

    public function sanitize_user_role($value)
    {
        $trimmed = trim($value);
        return empty($trimmed) ? 'administrator' : $trimmed;
    }
}

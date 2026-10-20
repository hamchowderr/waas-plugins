<?php

namespace WaaSHost\Features;

use WaaSHost\Core\ConfigService;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;

class AdminSettings
{
    const SHOULD_SEND_TENANT_READY_EMAIL_OPTION = 'wpcs_notification_settings_send_tenant_ready_email';
    const TENANT_READY_EMAIL_SUBJECT_OPTION = 'wpcs_notification_settings_tenant_ready_email_subject';
    const TENANT_READY_EMAIL_BODY_OPTION = 'wpcs_notification_settings_tenant_ready_email_body';
    const SHOULD_SEND_SUSPEND_SCHEDULED_EMAIL_OPTION = 'wpcs_notification_settings_send_suspend_scheduled_email';
    const SUSPEND_SCHEDULED_EMAIL_SUBJECT_OPTION = 'wpcs_notification_settings_suspend_scheduled_email_subject';
    const SUSPEND_SCHEDULED_EMAIL_BODY_OPTION = 'wpcs_notification_settings_suspend_scheduled_email_body';
    const SHOULD_SEND_SUSPENDED_EMAIL_OPTION = 'waas_notification_settings_send_suspended_email';
    const SUSPENDED_EMAIL_SUBJECT_OPTION = 'waas_notification_settings_suspended_email_subject';
    const SUSPENDED_EMAIL_BODY_OPTION = 'waas_notification_settings_suspended_email_body';
    const SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION = 'wpcs_host_setting_remove_admininistrator_plugin_capabilities_on_tenant';

    private ProvisioningApi $provisioningApi;
    private ConfigService $configService;

    public function __construct(ProvisioningApi $provisioningApi, ConfigService $configService)
    {
        $this->provisioningApi = $provisioningApi;
        $this->configService = $configService;

        add_action('admin_menu', [$this, 'add_waas_admin_page'], 12);
        add_action('admin_init', [$this, 'add_waas_admin_settings']);
        add_action('init', [$this, 'register_waas_admin_settings']);
        add_filter('waas_tenant_ready_email_allowed', [$this, 'allow_tenant_ready_email']);
        add_filter('waas_tenant_ready_email_subject', [$this, 'tenant_ready_email_subject'], 10, 1);
        add_filter('waas_tenant_ready_email_body', [$this, 'tenant_ready_email_body'], 10, 1);
        add_filter('waas_suspend_scheduled_email_allowed', [$this, 'allow_suspend_scheduled_email']);
        add_filter('waas_suspend_scheduled_email_subject', [$this, 'suspend_scheduled_email_subject'], 10, 1);
        add_filter('waas_suspend_scheduled_email_body', [$this, 'suspend_scheduled_email_body'], 10, 1);
        add_filter('waas_suspended_email_allowed', [$this, 'allow_suspended_email']);
        add_filter('waas_suspended_email_subject', [$this, 'suspended_email_subject'], 10, 1);
        add_filter('waas_suspended_email_body', [$this, 'suspended_email_body'], 10, 1);
        add_filter('waas_remove_tenant_administrator_plugin_capabilities', [$this, 'remove_tenant_administrator_plugin_capabilities']);
        if (defined('WAAS_HOST_BASENAME')) {
            add_filter('plugin_action_links_' . WAAS_HOST_BASENAME, [$this, 'add_settings_link']);
        }
    }

    /** A Settings link on the Plugins screen. */
    public function add_settings_link($links)
    {
        array_unshift($links, '<a href="' . esc_url(self::url()) . '">' . esc_html__('Settings', WAAS_HOST_TEXTDOMAIN) . '</a>');

        return $links;
    }

    /** The settings page's tabs: slug => [title, option group]. Each tab is its own form and option group. */
    public static function tabs(): array
    {
        return [
            'connection' => [__('Connection', WAAS_HOST_TEXTDOMAIN), 'waas-admin'],
            'stores' => [__('Websites', WAAS_HOST_TEXTDOMAIN), 'waas-admin-stores'],
            'emails' => [__('Emails', WAAS_HOST_TEXTDOMAIN), 'waas-admin-emails'],
        ];
    }

    public static function current_tab(): string
    {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

        return array_key_exists($tab, self::tabs()) ? $tab : 'connection';
    }

    public static function url(string $tab = 'connection'): string
    {
        return admin_url('admin.php?page=waas-admin-settings&tab=' . $tab);
    }

    public function add_waas_admin_page()
    {
        add_submenu_page(
            'waas-admin',
            __('WaaS Host settings', WAAS_HOST_TEXTDOMAIN),
            __('Settings', WAAS_HOST_TEXTDOMAIN),
            'manage_options',
            'waas-admin-settings',
            [$this, 'render_waas_admin_page'],
        );
    }

    public function render_waas_admin_page()
    {
        $tab = self::current_tab();
        $is_api_setup = $this->configService->check_credentials();

        echo '<div class="wrap waas-admin">';
        echo '<h1>' . esc_html__('WaaS Host settings', WAAS_HOST_TEXTDOMAIN) . '</h1>';

        if ($tab === 'connection' && !empty($_GET['settings-updated']) && $is_api_setup) {
            try {
                $reachable = (bool) $this->provisioningApi->test_reachability();
            } catch (\Exception $e) {
                $reachable = false;
            }
            $this->provisioningApi->update_is_reachable($reachable);
            if ($reachable) {
                // Before the sections below, so the Webhooks section shows the outcome.
                do_action('waas_api_connection_saved');
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Connected to the WaaS API.', WAAS_HOST_TEXTDOMAIN) . '</p></div>';
            } else {
                $this->waas_could_not_connect_to_api_notice_error();
            }
        } elseif ($is_api_setup && !$this->provisioningApi->is_reachable()) {
            $this->waas_could_not_connect_to_api_notice_error();
        }

        // options.php only adds this notice on pages under Settings.
        settings_errors();

        echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__('Settings sections', WAAS_HOST_TEXTDOMAIN) . '">';
        foreach (self::tabs() as $slug => [$title]) {
            printf(
                '<a href="%s" class="nav-tab%s"%s>%s</a>',
                esc_url(self::url($slug)),
                $slug === $tab ? ' nav-tab-active' : '',
                $slug === $tab ? ' aria-current="page"' : '',
                esc_html($title)
            );
        }
        echo '</nav>';

        $group = self::tabs()[$tab][1];
        echo '<form method="post" action="options.php">';
        settings_fields($group);
        do_settings_sections($group);
        submit_button();
        echo '</form></div>';
    }

    public function register_waas_admin_settings()
    {
        register_setting('waas-admin', 'wpcs_credentials_api_base_url_setting', [
            'sanitize_callback' => 'esc_url_raw'
        ]);
        register_setting('waas-admin', 'wpcs_credentials_api_key_setting', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('waas-admin', 'wpcs_credentials_api_secret_setting', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);

        register_setting('waas-admin-stores', 'wpcs_host_settings_root_domain', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('waas-admin-stores', 'wpcs_dns_a_records_setting', [
            'sanitize_callback' => [ConfigService::class, 'sanitize_ip_list']
        ]);
        register_setting('waas-admin-stores', 'wpcs_dns_cname_target_setting', [
            'sanitize_callback' => [ConfigService::class, 'sanitize_hostname']
        ]);
        register_setting('waas-admin-stores', Tenant::DEFAULT_USER_ROLE, [
            'default' => 'administrator',
            'sanitize_callback' => [$this, 'sanitize_user_role']
        ]);
        register_setting('waas-admin-stores', TenantsSubscriptionManager::DELETION_GRACE_DAYS_OPTION, [
            'default' => TenantsSubscriptionManager::DEFAULT_DELETION_GRACE_DAYS,
            'sanitize_callback' => fn ($value) => max(1, absint($value)),
        ]);
        register_setting('waas-admin-stores', self::SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION, [
            'default' => 'on'
        ]);
        // Off by default: turning it on changes prices on the live shop.
        register_setting('waas-admin-stores', VolumeDiscount::ENABLED_OPTION, [
            'default' => ''
        ]);

        register_setting('waas-admin-emails', self::TENANT_READY_EMAIL_SUBJECT_OPTION, [
            'default' => Notifications::get_default_tenant_ready_subject()
        ]);
        register_setting('waas-admin-emails', self::TENANT_READY_EMAIL_BODY_OPTION, [
            'default' => Notifications::get_default_tenant_ready_body()
        ]);
        register_setting('waas-admin-emails', self::SHOULD_SEND_TENANT_READY_EMAIL_OPTION, [
            'default' => 'on'
        ]);
        register_setting('waas-admin-emails', self::SUSPEND_SCHEDULED_EMAIL_SUBJECT_OPTION, [
            'default' => Notifications::get_default_suspend_scheduled_subject()
        ]);
        register_setting('waas-admin-emails', self::SUSPEND_SCHEDULED_EMAIL_BODY_OPTION, [
            'default' => Notifications::get_default_suspend_scheduled_body()
        ]);
        register_setting('waas-admin-emails', self::SHOULD_SEND_SUSPEND_SCHEDULED_EMAIL_OPTION, [
            'default' => 'on'
        ]);
        register_setting('waas-admin-emails', self::SUSPENDED_EMAIL_SUBJECT_OPTION, [
            'default' => Notifications::get_default_suspended_subject()
        ]);
        register_setting('waas-admin-emails', self::SUSPENDED_EMAIL_BODY_OPTION, [
            'default' => Notifications::get_default_suspended_body()
        ]);
        register_setting('waas-admin-emails', self::SHOULD_SEND_SUSPENDED_EMAIL_OPTION, [
            'default' => 'on'
        ]);
    }

    /** "Set in wp-config.php as WAAS_<NAME>." when the constant is defined, else $hint. */
    private static function constant_hint(string $name, string $hint): string
    {
        if (!ConfigService::has_constant($name)) {
            return $hint;
        }

        /* translators: %s: a wp-config.php constant name */
        return sprintf(__('Set in wp-config.php as %s, so it can\'t be changed here.', WAAS_HOST_TEXTDOMAIN), defined('WAAS_' . $name) ? 'WAAS_' . $name : 'WPCS_' . $name);
    }

    private function add_field(string $id, string $title, string $page, string $section, array $args)
    {
        $args['id'] = $id;
        if (!in_array($args['type'], ['checkbox', 'html-editor'], true)) {
            $args['label_for'] = $id;
        }

        add_settings_field($id, $title, [$this, 'render_settings_field'], $page, $section, $args);
    }

    public function add_waas_admin_settings()
    {
        $placeholders = '<code>{{ tenant_domain }}</code> ' . esc_html__('(the website address),', WAAS_HOST_TEXTDOMAIN)
            . ' <code>{{ tenant_one_click_login_url }}</code> ' . esc_html__('(the login page),', WAAS_HOST_TEXTDOMAIN)
            . ' <code>{{ storefront_name }}</code>, <code>{{ storefront_url }}</code>, <code>{{ storefront_subscription_details_url }}</code>';

        // Connection
        add_settings_section(
            'api_credentials',
            __('WaaS API', WAAS_HOST_TEXTDOMAIN),
            fn () => print('<p>' . esc_html__('This storefront creates, suspends and restores customer websites through the WaaS provisioning API. Your API key and secret come from whoever runs the API for you.', WAAS_HOST_TEXTDOMAIN) . '</p>'),
            'waas-admin'
        );

        $this->add_field('wpcs_credentials_api_base_url_setting', __('API URL', WAAS_HOST_TEXTDOMAIN), 'waas-admin', 'api_credentials', [
            'type' => 'url',
            'class_name' => 'regular-text code',
            'placeholder' => 'https://api.example.com',
            'constant' => 'API_BASE_URL',
            'hint' => self::constant_hint('API_BASE_URL', __('The address of the provisioning API.', WAAS_HOST_TEXTDOMAIN)),
        ]);
        $this->add_field('wpcs_credentials_api_key_setting', __('API key', WAAS_HOST_TEXTDOMAIN), 'waas-admin', 'api_credentials', [
            'type' => 'text',
            'class_name' => 'regular-text code',
            'constant' => 'API_KEY',
            'hint' => self::constant_hint('API_KEY', ''),
        ]);
        $this->add_field('wpcs_credentials_api_secret_setting', __('API secret', WAAS_HOST_TEXTDOMAIN), 'waas-admin', 'api_credentials', [
            'type' => 'password',
            'class_name' => 'regular-text code',
            'constant' => 'API_SECRET',
            'hint' => self::constant_hint('API_SECRET', __('Saving checks the connection and registers this storefront for the API\'s webhooks.', WAAS_HOST_TEXTDOMAIN)),
        ]);

        // Stores
        add_settings_section(
            'waas_host_settings',
            __('Website addresses', WAAS_HOST_TEXTDOMAIN),
            fn () => print('<p>' . esc_html__('Where new websites live, and the DNS records customers use to connect their own domain.', WAAS_HOST_TEXTDOMAIN) . '</p>'),
            'waas-admin-stores'
        );

        $this->add_field('wpcs_host_settings_root_domain', __('Website domain', WAAS_HOST_TEXTDOMAIN), 'waas-admin-stores', 'waas_host_settings', [
            'type' => 'text',
            'class_name' => 'regular-text code',
            'placeholder' => 'sites.example.com',
            'hint' => __('Each new website gets an address under this domain: with sites.example.com, a website named shop is at shop.sites.example.com.', WAAS_HOST_TEXTDOMAIN),
        ]);
        $this->add_field('wpcs_dns_a_records_setting', __('A record IPs', WAAS_HOST_TEXTDOMAIN), 'waas-admin-stores', 'waas_host_settings', [
            'type' => 'text',
            'class_name' => 'regular-text code',
            'placeholder' => '203.0.113.10',
            'constant' => 'DNS_A_RECORDS',
            'hint' => self::constant_hint('DNS_A_RECORDS', __('The IP addresses a customer points their own domain at, separated by commas. Shown on the customer\'s domain page.', WAAS_HOST_TEXTDOMAIN)),
        ]);
        $this->add_field('wpcs_dns_cname_target_setting', __('CNAME target', WAAS_HOST_TEXTDOMAIN), 'waas-admin-stores', 'waas_host_settings', [
            'type' => 'text',
            'class_name' => 'regular-text code',
            'placeholder' => 'sites.example.com',
            'constant' => 'DNS_CNAME_TARGET',
            'hint' => self::constant_hint('DNS_CNAME_TARGET', __('The hostname a customer points a subdomain (like www) at. Shown on the customer\'s domain page.', WAAS_HOST_TEXTDOMAIN)),
        ]);

        add_settings_section(
            'waas_store_access',
            __('Customer access', WAAS_HOST_TEXTDOMAIN),
            fn () => print('<p>' . esc_html__('What customers can do on their own website. Changes apply to websites created from now on.', WAAS_HOST_TEXTDOMAIN) . '</p>'),
            'waas-admin-stores'
        );

        $this->add_field(Tenant::DEFAULT_USER_ROLE, __('Customer\'s role', WAAS_HOST_TEXTDOMAIN), 'waas-admin-stores', 'waas_store_access', [
            'type' => 'text',
            'class_name' => 'regular-text code',
            'placeholder' => 'administrator',
            'hint' => __('The WordPress role the customer gets on their website when they use the login button on their account page. Empty means administrator.', WAAS_HOST_TEXTDOMAIN),
        ]);
        $this->add_field(self::SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION, __('Plugins', WAAS_HOST_TEXTDOMAIN), 'waas-admin-stores', 'waas_store_access', [
            'type' => 'checkbox',
            'label' => __('Customers can\'t install, activate or delete plugins', WAAS_HOST_TEXTDOMAIN),
            'hint' => __('The plugins a website has come from its plan. Websites that already exist keep what they have.', WAAS_HOST_TEXTDOMAIN),
        ]);

        add_settings_section(
            'waas_store_cancellation',
            __('Cancelled subscriptions', WAAS_HOST_TEXTDOMAIN),
            fn () => '',
            'waas-admin-stores'
        );

        $this->add_field(TenantsSubscriptionManager::DELETION_GRACE_DAYS_OPTION, __('Delete after', WAAS_HOST_TEXTDOMAIN), 'waas-admin-stores', 'waas_store_cancellation', [
            'type' => 'number',
            'class_name' => 'small-text',
            'min' => 1,
            'suffix' => __('days', WAAS_HOST_TEXTDOMAIN),
            'hint' => __('When a subscription is cancelled or expires, its website is backed up and taken offline, then deleted after this many days. If the subscription becomes active again before then, the website comes back as it was.', WAAS_HOST_TEXTDOMAIN),
        ]);

        add_settings_section(
            'waas_volume_discount',
            __('Volume discount', WAAS_HOST_TEXTDOMAIN),
            fn () => print('<p>' . esc_html__('A discount on new websites for customers who already have several. Their live websites (active website plan subscriptions) are counted together with the one they are buying.', WAAS_HOST_TEXTDOMAIN) . '</p>'),
            'waas-admin-stores'
        );

        $this->add_field(VolumeDiscount::ENABLED_OPTION, __('Volume discount', WAAS_HOST_TEXTDOMAIN), 'waas-admin-stores', 'waas_volume_discount', [
            'type' => 'checkbox',
            'label' => __('Discount website plans at checkout by how many live websites the customer has', WAAS_HOST_TEXTDOMAIN),
            /* translators: %s: the discount tiers, e.g. "10% off at 3 to 9 websites, 20% off at 10 to 24 websites" */
            'hint' => sprintf(__('%s. Applied automatically to signed-in customers and to customers checking out with the email of an existing customer, on the first payment and every renewal. Subscriptions that already exist keep their price. Needs FluentCart.', WAAS_HOST_TEXTDOMAIN), VolumeDiscount::describe_tiers()),
        ]);

        // Emails
        add_settings_section(
            'waas_notification_settings',
            __('Website ready', WAAS_HOST_TEXTDOMAIN),
            fn () => print('<p>' . esc_html__('Sent to the customer when their new website is up.', WAAS_HOST_TEXTDOMAIN) . '</p>'),
            'waas-admin-emails'
        );

        $this->add_field(self::SHOULD_SEND_TENANT_READY_EMAIL_OPTION, __('Send', WAAS_HOST_TEXTDOMAIN), 'waas-admin-emails', 'waas_notification_settings', [
            'type' => 'checkbox',
            'label' => __('Email the customer when their website is ready', WAAS_HOST_TEXTDOMAIN),
        ]);
        $this->add_field(self::TENANT_READY_EMAIL_SUBJECT_OPTION, __('Subject', WAAS_HOST_TEXTDOMAIN), 'waas-admin-emails', 'waas_notification_settings', [
            'type' => 'text',
            'class_name' => 'large-text',
        ]);
        $this->add_field(self::TENANT_READY_EMAIL_BODY_OPTION, __('Message', WAAS_HOST_TEXTDOMAIN), 'waas-admin-emails', 'waas_notification_settings', [
            'type' => 'html-editor',
            'hint_html' => esc_html__('Placeholders:', WAAS_HOST_TEXTDOMAIN) . ' ' . $placeholders,
        ]);

        add_settings_section(
            'waas_cancellation_email_settings',
            __('Subscription cancelled', WAAS_HOST_TEXTDOMAIN),
            fn () => print('<p>' . esc_html__('Sent when a customer cancels: their website stays online until the end of the period they paid for.', WAAS_HOST_TEXTDOMAIN) . '</p>'),
            'waas-admin-emails'
        );

        $this->add_field(self::SHOULD_SEND_SUSPEND_SCHEDULED_EMAIL_OPTION, __('Send', WAAS_HOST_TEXTDOMAIN), 'waas-admin-emails', 'waas_cancellation_email_settings', [
            'type' => 'checkbox',
            'label' => __('Email the customer how long their website stays online', WAAS_HOST_TEXTDOMAIN),
        ]);
        $this->add_field(self::SUSPEND_SCHEDULED_EMAIL_SUBJECT_OPTION, __('Subject', WAAS_HOST_TEXTDOMAIN), 'waas-admin-emails', 'waas_cancellation_email_settings', [
            'type' => 'text',
            'class_name' => 'large-text',
        ]);
        $this->add_field(self::SUSPEND_SCHEDULED_EMAIL_BODY_OPTION, __('Message', WAAS_HOST_TEXTDOMAIN), 'waas-admin-emails', 'waas_cancellation_email_settings', [
            'type' => 'html-editor',
            'hint_html' => esc_html__('Placeholders:', WAAS_HOST_TEXTDOMAIN) . ' ' . $placeholders
                . ', <code>{{ suspend_date }}</code> ' . esc_html__('(the last day online),', WAAS_HOST_TEXTDOMAIN)
                . ' <code>{{ deletion_grace_days }}</code>',
        ]);

        add_settings_section(
            'waas_suspended_email_settings',
            __('Website taken offline', WAAS_HOST_TEXTDOMAIN),
            fn () => print('<p>' . esc_html__('Sent when a subscription ends (a refund, the end of a cancelled plan\'s paid period, or expiry) and the website goes offline.', WAAS_HOST_TEXTDOMAIN) . '</p>'),
            'waas-admin-emails'
        );

        $this->add_field(self::SHOULD_SEND_SUSPENDED_EMAIL_OPTION, __('Send', WAAS_HOST_TEXTDOMAIN), 'waas-admin-emails', 'waas_suspended_email_settings', [
            'type' => 'checkbox',
            'label' => __('Email the customer when their website is taken offline', WAAS_HOST_TEXTDOMAIN),
        ]);
        $this->add_field(self::SUSPENDED_EMAIL_SUBJECT_OPTION, __('Subject', WAAS_HOST_TEXTDOMAIN), 'waas-admin-emails', 'waas_suspended_email_settings', [
            'type' => 'text',
            'class_name' => 'large-text',
        ]);
        $this->add_field(self::SUSPENDED_EMAIL_BODY_OPTION, __('Message', WAAS_HOST_TEXTDOMAIN), 'waas-admin-emails', 'waas_suspended_email_settings', [
            'type' => 'html-editor',
            'hint_html' => esc_html__('Placeholders:', WAAS_HOST_TEXTDOMAIN) . ' ' . $placeholders
                . ', <code>{{ delete_date }}</code> ' . esc_html__('(when the website is deleted),', WAAS_HOST_TEXTDOMAIN)
                . ' <code>{{ deletion_grace_days }}</code>',
        ]);
    }

    public function render_settings_field($args)
    {
        $id = $args['id'];
        $value = get_option($id);
        $disabled = !empty($args['constant']) && ConfigService::has_constant($args['constant']);
        if ($disabled && $args['type'] !== 'password') {
            $constant = ConfigService::constant($args['constant']);
            $value = is_array($constant) ? implode(', ', $constant) : (string) $constant;
        }

        switch ($args['type']) {
            case 'checkbox':
                printf(
                    '<label for="%1$s"><input type="checkbox" id="%1$s" name="%1$s" %2$s> %3$s</label>',
                    esc_attr($id),
                    checked($value, 'on', false),
                    esc_html($args['label'] ?? '')
                );
                break;
            case 'html-editor':
                wp_editor((string) $value, $id, [
                    'media_buttons' => false,
                    'wpautop' => false,
                    'textarea_rows' => 12,
                ]);
                break;
            default:
                $attributes = [
                    'type' => $args['type'],
                    'id' => $id,
                    'name' => $id,
                    'value' => $disabled && $args['type'] === 'password' ? '' : (string) $value,
                    'class' => $args['class_name'] ?? 'regular-text',
                ];
                if (!empty($args['placeholder'])) {
                    $attributes['placeholder'] = $disabled && $args['type'] === 'password' ? '••••••••' : $args['placeholder'];
                }
                if (isset($args['min'])) {
                    $attributes['min'] = $args['min'];
                }
                if (!empty($args['hint']) || !empty($args['hint_html'])) {
                    $attributes['aria-describedby'] = $id . '-description';
                }

                $html = '';
                foreach ($attributes as $att => $att_value) {
                    $html .= sprintf(' %s="%s"', $att, esc_attr((string) $att_value));
                }
                echo '<input' . $html . ($disabled ? ' disabled' : '') . ' />';
                if (!empty($args['suffix'])) {
                    echo ' ' . esc_html($args['suffix']);
                }
                break;
        }

        if (!empty($args['hint'])) {
            echo '<p class="description" id="' . esc_attr($id . '-description') . '">' . esc_html($args['hint']) . '</p>';
        } elseif (!empty($args['hint_html'])) {
            echo '<p class="description" id="' . esc_attr($id . '-description') . '">' . wp_kses($args['hint_html'], ['code' => []]) . '</p>';
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

    function allow_suspended_email($allowed)
    {
        if (!$allowed) {
            return $allowed;
        }

        return get_option(self::SHOULD_SEND_SUSPENDED_EMAIL_OPTION, 'on') === 'on';
    }

    function suspended_email_subject($text)
    {
        $option_val = get_option(self::SUSPENDED_EMAIL_SUBJECT_OPTION);

        return !empty($option_val) ? $option_val : $text;
    }

    function suspended_email_body($text)
    {
        $option_val = get_option(self::SUSPENDED_EMAIL_BODY_OPTION);

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

    public function waas_could_not_connect_to_api_notice_error()
    {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Couldn\'t connect to the WaaS API. Check the API URL, key and secret on the Connection tab.', WAAS_HOST_TEXTDOMAIN)
            . '</p></div>';
    }

    public function sanitize_user_role($value)
    {
        $trimmed = trim($value);
        return empty($trimmed) ? 'administrator' : $trimmed;
    }
}

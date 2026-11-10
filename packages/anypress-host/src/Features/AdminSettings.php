<?php

namespace AnyPressHost\Features;

use AnyPressHost\Core\ConfigService;
use AnyPressHost\Core\ProvisioningApi;

class AdminSettings
{
    public const SHOULD_SEND_TENANT_READY_EMAIL_OPTION = 'anypress_notification_settings_send_tenant_ready_email';
    public const TENANT_READY_EMAIL_SUBJECT_OPTION = 'anypress_notification_settings_tenant_ready_email_subject';
    public const TENANT_READY_EMAIL_BODY_OPTION = 'anypress_notification_settings_tenant_ready_email_body';
    public const SHOULD_SEND_SUSPEND_SCHEDULED_EMAIL_OPTION = 'anypress_notification_settings_send_suspend_scheduled_email';
    public const SUSPEND_SCHEDULED_EMAIL_SUBJECT_OPTION = 'anypress_notification_settings_suspend_scheduled_email_subject';
    public const SUSPEND_SCHEDULED_EMAIL_BODY_OPTION = 'anypress_notification_settings_suspend_scheduled_email_body';
    public const SHOULD_SEND_SUSPENDED_EMAIL_OPTION = 'anypress_notification_settings_send_suspended_email';
    public const SUSPENDED_EMAIL_SUBJECT_OPTION = 'anypress_notification_settings_suspended_email_subject';
    public const SUSPENDED_EMAIL_BODY_OPTION = 'anypress_notification_settings_suspended_email_body';
    public const SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION = 'anypress_remove_administrator_plugin_caps';
    public const SLUG = 'anypress-admin-settings';

    private ProvisioningApi $provisioningApi;
    private ConfigService $configService;

    public function __construct(ProvisioningApi $provisioningApi, ConfigService $configService)
    {
        $this->provisioningApi = $provisioningApi;
        $this->configService = $configService;

        add_action('admin_menu', [$this, 'add_anypress_admin_page'], 12);
        add_filter('anypress_remove_tenant_administrator_plugin_capabilities', [$this, 'remove_tenant_administrator_plugin_capabilities']);
        new SettingsFields();
        new EmailSettings();
        if (defined('ANYPRESS_HOST_BASENAME')) {
            add_filter('plugin_action_links_' . ANYPRESS_HOST_BASENAME, [$this, 'add_settings_link']);
        }
    }

    /** A Settings link on the Plugins screen. */
    public function add_settings_link($links)
    {
        array_unshift($links, '<a href="' . esc_url(self::url()) . '">' . esc_html__('Settings', 'anypress-host') . '</a>');

        return $links;
    }

    /**
     * The settings page's sections: slug => [title, option group]. Each section is its own form and option group, so
     * saving one never touches another's options. The emails section has a group per email (see EmailSettings).
     */
    public static function tabs(): array
    {
        $managed = self::is_ready_made();

        return array_filter([
            'connection' => [__('Connection', 'anypress-host'), 'anypress-admin'],
            'addresses' => $managed ? null : [__('Website addresses', 'anypress-host'), 'anypress-admin-addresses'],
            'access' => $managed ? null : [__('Customer access', 'anypress-host'), 'anypress-admin-access'],
            'cancellations' => [__('Cancellations', 'anypress-host'), 'anypress-admin-cancellations'],
            'discount' => [__('Volume discount', 'anypress-host'), 'anypress-admin-discount'],
            'domains' => [__('Domains', 'anypress-host'), 'anypress-admin-domains'],
            'emails' => [__('Emails', 'anypress-host'), ''],
        ]);
    }

    public static function current_tab(): string
    {
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which settings tab to show.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        // Links from before 2.7.0, when addresses, access, cancellations and the discount were one "Websites" tab.
        if ($tab === 'stores') {
            $tab = 'addresses';
        }

        return array_key_exists($tab, self::tabs()) ? $tab : 'connection';
    }

    public static function url(string $tab = 'connection', array $args = []): string
    {
        return add_query_arg($args, admin_url('admin.php?page=' . self::SLUG . '&tab=' . $tab));
    }


    public function add_anypress_admin_page()
    {
        add_submenu_page(
            AdminHome::SLUG,
            __('AnyPress settings', 'anypress-host'),
            __('Settings', 'anypress-host'),
            'manage_options',
            self::SLUG,
            [$this, 'render_anypress_admin_page'],
        );
    }

    /**
     * The storefront was made by AnyPress: its API address, key and secret are in wp-config, set for the seller. AnyPress
     * also sets its website addresses, customer access and assistant, so those aren't shown as settings.
     */
    public static function is_ready_made(): bool
    {
        return ConfigService::has_constant('API_KEY') && ConfigService::has_constant('API_SECRET');
    }

    public function render_anypress_admin_page()
    {
        $tab = self::current_tab();
        $is_api_setup = $this->configService->check_credentials();

        echo '<div class="wrap ap-admin">';
        AdminHome::render_header(__('Settings', 'anypress-host'), $this->configService);
        echo '<hr class="wp-header-end">';

        // options.php only adds this notice on pages under Settings.
        settings_errors();

        echo '<div class="ap-settings"><nav class="ap-setnav" aria-label="' . esc_attr__('Settings sections', 'anypress-host') . '">';
        foreach (self::tabs() as $slug => [$title]) {
            printf(
                '<a href="%s"%s>%s</a>',
                esc_url(self::url($slug)),
                $slug === $tab ? ' aria-current="page"' : '',
                esc_html($title)
            );
        }
        echo '</nav><div class="ap-card ap-setcard">';

        if ($tab === 'connection') {
            $this->render_connection_status($is_api_setup);
        }

        if ($tab === 'emails') {
            EmailSettings::render();
        } elseif ($tab === 'connection' && self::is_ready_made()) {
            $this->render_managed_settings();
            do_settings_sections('anypress-admin');
        } else {
            $group = self::tabs()[$tab][1];
            echo '<form method="post" action="options.php" class="ap-form">';
            settings_fields($group);
            do_settings_sections($group);
            echo '<div class="ap-save">';
            if ($tab === 'connection') {
                echo '<span class="ap-muted">' . esc_html__('Saving checks the connection and turns on live updates.', 'anypress-host') . '</span>';
                submit_button(__('Save and connect', 'anypress-host'), 'primary ap-btn is-ink', 'submit', false);
            } else {
                submit_button(__('Save', 'anypress-host'), 'primary ap-btn is-ink', 'submit', false);
            }
            echo '</div></form>';
        }

        echo '</div></div></div>';
    }

    /** The banner above the connection fields: connected, can't connect, or not set up yet. */
    private function render_connection_status(bool $is_api_setup): void
    {
        $reachable = null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- set by options.php after it checked its own nonce, or by the Test link below.
        if ($is_api_setup && (!empty($_GET['settings-updated']) || !empty($_GET['test']))) {
            try {
                $reachable = (bool) $this->provisioningApi->test_reachability();
            } catch (\Exception $e) {
                $reachable = false;
            }
            $this->provisioningApi->update_is_reachable($reachable);
            if ($reachable) {
                // Before the sections below, so the Webhooks section shows the outcome.
                do_action('anypress_api_connection_saved');
            }
        } elseif ($is_api_setup) {
            $reachable = (bool) $this->provisioningApi->is_reachable();
        }

        $test = '<a class="ap-btn is-sm" href="' . esc_url(self::url('connection', ['test' => 1])) . '">' . esc_html__('Test again', 'anypress-host') . '</a>';
        if (!$is_api_setup) {
            echo '<div class="ap-banner is-warn"><span><strong>' . esc_html__('Not connected yet.', 'anypress-host') . '</strong> '
                . esc_html__('Paste the API key and secret from the API keys page of your AnyPress dashboard, then save.', 'anypress-host') . '</span></div>';
        } elseif ($reachable) {
            echo '<div class="ap-banner is-ok"><span><strong>' . esc_html(self::is_ready_made() ? __('Connected by AnyPress.', 'anypress-host') : __('Connected to the AnyPress API.', 'anypress-host')) . '</strong> '
                . esc_html(self::is_ready_made()
                    ? __('This storefront\'s key was set up for you when it was made, so there is nothing to fill in.', 'anypress-host')
                    : __('This storefront can create websites.', 'anypress-host')) . '</span>' . $test . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $test is escaped above.
        } else {
            echo '<div class="ap-banner is-bad"><span><strong>' . esc_html__('Can\'t connect to the AnyPress API.', 'anypress-host') . '</strong> '
                . esc_html(self::is_ready_made()
                    ? __('The key is set in wp-config.php. Contact support@anypress.io if this keeps happening.', 'anypress-host')
                    : __('Check the API address, key and secret below.', 'anypress-host')) . '</span>' . $test . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $test is escaped above.
        }
    }

    /** A ready-made storefront's Connection section: what AnyPress set up for it, to read, not to change. */
    private function render_managed_settings(): void
    {
        $root_domain = (string) get_option('anypress_root_domain', '');
        $assistant = $this->configService->get_assistant_url();
        $rows = [
            [__('Website addresses', 'anypress-host'), $root_domain !== '' ? sprintf(
                /* translators: %s: a website domain, such as anypress.site */
                __('New websites are made at name.%s', 'anypress-host'),
                $root_domain
            ) : __('Set by AnyPress', 'anypress-host')],
            [__('Custom domains', 'anypress-host'), $this->configService->get_dns_a_records()
                ? __('Customers get the DNS records for their own domain on their account page.', 'anypress-host')
                : __('Not ready yet: customers are asked to contact you to connect their own domain.', 'anypress-host')],
            [__('Customer access', 'anypress-host'), __('Each website gets the plugins of its plan.', 'anypress-host')],
            [__('Website assistant', 'anypress-host'), $assistant !== '' ? __('On: each ready website has an "Ask the website assistant" button.', 'anypress-host') : __('Off', 'anypress-host')],
        ];

        echo '<div class="ap-health"><h2>' . esc_html__('Set up by AnyPress', 'anypress-host') . '</h2>';
        foreach ($rows as [$label, $detail]) {
            echo '<div class="ap-health-row"><div>' . esc_html($label) . '<small>' . esc_html($detail) . '</small></div><div></div></div>';
        }
        echo '</div>';
    }


        public function remove_tenant_administrator_plugin_capabilities($allowed)
    {
        // If somebody else disallowed, respect their decision.
        if (!$allowed) {
            return $allowed;
        }

        return get_option(self::SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION) === 'on';
        }
}

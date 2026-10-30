<?php

namespace WaaSHost\Features;

use WaaSHost\Core\ConfigService;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;

class AdminSettings
{
    const SHOULD_SEND_TENANT_READY_EMAIL_OPTION = 'waas_notification_settings_send_tenant_ready_email';
    const TENANT_READY_EMAIL_SUBJECT_OPTION = 'waas_notification_settings_tenant_ready_email_subject';
    const TENANT_READY_EMAIL_BODY_OPTION = 'waas_notification_settings_tenant_ready_email_body';
    const SHOULD_SEND_SUSPEND_SCHEDULED_EMAIL_OPTION = 'waas_notification_settings_send_suspend_scheduled_email';
    const SUSPEND_SCHEDULED_EMAIL_SUBJECT_OPTION = 'waas_notification_settings_suspend_scheduled_email_subject';
    const SUSPEND_SCHEDULED_EMAIL_BODY_OPTION = 'waas_notification_settings_suspend_scheduled_email_body';
    const SHOULD_SEND_SUSPENDED_EMAIL_OPTION = 'waas_notification_settings_send_suspended_email';
    const SUSPENDED_EMAIL_SUBJECT_OPTION = 'waas_notification_settings_suspended_email_subject';
    const SUSPENDED_EMAIL_BODY_OPTION = 'waas_notification_settings_suspended_email_body';
    const SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION = 'waas_remove_administrator_plugin_caps';
    const SLUG = 'waas-admin-settings';
    const RESET_EMAIL_ACTION = 'waas_reset_email';
    const EMAIL_AJAX_NONCE = 'waas_email_editor';

    private ProvisioningApi $provisioningApi;
    private ConfigService $configService;

    public function __construct(ProvisioningApi $provisioningApi, ConfigService $configService)
    {
        $this->provisioningApi = $provisioningApi;
        $this->configService = $configService;

        add_action('admin_menu', [$this, 'add_waas_admin_page'], 12);
        add_action('admin_init', [$this, 'add_waas_admin_settings']);
        add_action('init', [$this, 'register_waas_admin_settings']);
        add_action('wp_ajax_waas_email_preview', [$this, 'handle_email_preview']);
        add_action('admin_post_' . self::RESET_EMAIL_ACTION, [$this, 'handle_reset_email']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_email_editor']);
        add_filter('waas_tenant_ready_email_allowed', [$this, 'allow_tenant_ready_email']);
        add_filter('waas_tenant_ready_email_subject', [$this, 'tenant_ready_email_subject'], 10, 1);
        add_filter('waas_tenant_ready_email_body', [$this, 'tenant_ready_email_body'], 10, 1);
        add_filter('waas_suspend_scheduled_email_allowed', [$this, 'allow_suspend_scheduled_email']);
        add_filter('waas_notification_settings_suspend_scheduled_email_subject', [$this, 'suspend_scheduled_email_subject'], 10, 1);
        add_filter('waas_notification_settings_suspend_scheduled_email_body', [$this, 'suspend_scheduled_email_body'], 10, 1);
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
        array_unshift($links, '<a href="' . esc_url(self::url()) . '">' . esc_html__('Settings', 'waas-host') . '</a>');

        return $links;
    }

    /**
     * The settings page's sections: slug => [title, option group]. Each section is its own form and option group, so
     * saving one never touches another's options. The emails section has a group per email (see emails()).
     */
    public static function tabs(): array
    {
        $managed = self::is_ready_made();

        return array_filter([
            'connection' => [__('Connection', 'waas-host'), 'waas-admin'],
            'addresses' => $managed ? null : [__('Website addresses', 'waas-host'), 'waas-admin-addresses'],
            'access' => $managed ? null : [__('Customer access', 'waas-host'), 'waas-admin-access'],
            'cancellations' => [__('Cancellations', 'waas-host'), 'waas-admin-cancellations'],
            'discount' => [__('Volume discount', 'waas-host'), 'waas-admin-discount'],
            'emails' => [__('Emails', 'waas-host'), ''],
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

    /**
     * The customer emails: key => what the editor needs. Option names and placeholders are the ones the emails have
     * always used, so saved texts carry over.
     */
    public static function emails(): array
    {
        $common = ['tenant_domain', 'tenant_one_click_login_url', 'storefront_name', 'storefront_url', 'storefront_subscription_details_url'];

        return [
            'ready' => [
                'title' => __('Website ready', 'waas-host'),
                'when' => __('Sent when a new website is up.', 'waas-host'),
                'send' => self::SHOULD_SEND_TENANT_READY_EMAIL_OPTION,
                'subject' => self::TENANT_READY_EMAIL_SUBJECT_OPTION,
                'body' => self::TENANT_READY_EMAIL_BODY_OPTION,
                'default_subject' => [Notifications::class, 'get_default_tenant_ready_subject'],
                'default_body' => [Notifications::class, 'get_default_tenant_ready_body'],
                'placeholders' => $common,
            ],
            'cancelled' => [
                'title' => __('Subscription cancelled', 'waas-host'),
                'when' => __('Sent when a customer cancels: their website stays online until the end of the period they paid for.', 'waas-host'),
                'send' => self::SHOULD_SEND_SUSPEND_SCHEDULED_EMAIL_OPTION,
                'subject' => self::SUSPEND_SCHEDULED_EMAIL_SUBJECT_OPTION,
                'body' => self::SUSPEND_SCHEDULED_EMAIL_BODY_OPTION,
                'default_subject' => [Notifications::class, 'get_default_suspend_scheduled_subject'],
                'default_body' => [Notifications::class, 'get_default_suspend_scheduled_body'],
                'placeholders' => array_merge($common, ['suspend_date', 'deletion_grace_days']),
            ],
            'offline' => [
                'title' => __('Website taken offline', 'waas-host'),
                'when' => __('Sent when a subscription ends (a refund, the end of a cancelled plan\'s paid period, or expiry) and the website goes offline.', 'waas-host'),
                'send' => self::SHOULD_SEND_SUSPENDED_EMAIL_OPTION,
                'subject' => self::SUSPENDED_EMAIL_SUBJECT_OPTION,
                'body' => self::SUSPENDED_EMAIL_BODY_OPTION,
                'default_subject' => [Notifications::class, 'get_default_suspended_subject'],
                'default_body' => [Notifications::class, 'get_default_suspended_body'],
                'placeholders' => array_merge($common, ['delete_date', 'deletion_grace_days']),
            ],
        ];
    }

    public static function email_group(string $key): string
    {
        return 'waas-admin-email-' . $key;
    }

    public function add_waas_admin_page()
    {
        add_submenu_page(
            AdminHome::SLUG,
            __('AnyPress settings', 'waas-host'),
            __('Settings', 'waas-host'),
            'manage_options',
            self::SLUG,
            [$this, 'render_waas_admin_page'],
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

    public function render_waas_admin_page()
    {
        $tab = self::current_tab();
        $is_api_setup = $this->configService->check_credentials();

        echo '<div class="wrap ap-admin">';
        AdminHome::render_header(__('Settings', 'waas-host'), $this->configService);
        echo '<hr class="wp-header-end">';

        // options.php only adds this notice on pages under Settings.
        settings_errors();

        echo '<div class="ap-settings"><nav class="ap-setnav" aria-label="' . esc_attr__('Settings sections', 'waas-host') . '">';
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
            $this->render_emails();
        } elseif ($tab === 'connection' && self::is_ready_made()) {
            $this->render_managed_settings();
            do_settings_sections('waas-admin');
        } else {
            $group = self::tabs()[$tab][1];
            echo '<form method="post" action="options.php" class="ap-form">';
            settings_fields($group);
            do_settings_sections($group);
            echo '<div class="ap-save">';
            if ($tab === 'connection') {
                echo '<span class="ap-muted">' . esc_html__('Saving checks the connection and turns on live updates.', 'waas-host') . '</span>';
                submit_button(__('Save and connect', 'waas-host'), 'primary ap-btn is-ink', 'submit', false);
            } else {
                submit_button(__('Save', 'waas-host'), 'primary ap-btn is-ink', 'submit', false);
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
                do_action('waas_api_connection_saved');
            }
        } elseif ($is_api_setup) {
            $reachable = (bool) $this->provisioningApi->is_reachable();
        }

        $test = '<a class="ap-btn is-sm" href="' . esc_url(self::url('connection', ['test' => 1])) . '">' . esc_html__('Test again', 'waas-host') . '</a>';
        if (!$is_api_setup) {
            echo '<div class="ap-banner is-warn"><span><strong>' . esc_html__('Not connected yet.', 'waas-host') . '</strong> '
                . esc_html__('Paste the API key and secret from the API keys page of your AnyPress dashboard, then save.', 'waas-host') . '</span></div>';
        } elseif ($reachable) {
            echo '<div class="ap-banner is-ok"><span><strong>' . esc_html(self::is_ready_made() ? __('Connected by AnyPress.', 'waas-host') : __('Connected to the AnyPress API.', 'waas-host')) . '</strong> '
                . esc_html(self::is_ready_made()
                    ? __('This storefront\'s key was set up for you when it was made, so there is nothing to fill in.', 'waas-host')
                    : __('This storefront can create websites.', 'waas-host')) . '</span>' . $test . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $test is escaped above.
        } else {
            echo '<div class="ap-banner is-bad"><span><strong>' . esc_html__('Can\'t connect to the AnyPress API.', 'waas-host') . '</strong> '
                . esc_html(self::is_ready_made()
                    ? __('The key is set in wp-config.php. Contact support@anypress.io if this keeps happening.', 'waas-host')
                    : __('Check the API address, key and secret below.', 'waas-host')) . '</span>' . $test . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $test is escaped above.
        }
    }

    /** A ready-made storefront's Connection section: what AnyPress set up for it, to read, not to change. */
    private function render_managed_settings(): void
    {
        $root_domain = (string) get_option('waas_root_domain', '');
        $assistant = $this->configService->get_assistant_url();
        $rows = [
            [__('Website addresses', 'waas-host'), $root_domain !== '' ? sprintf(
                /* translators: %s: a website domain, such as anypress.site */
                __('New websites are made at name.%s', 'waas-host'),
                $root_domain
            ) : __('Set by AnyPress', 'waas-host')],
            [__('Custom domains', 'waas-host'), $this->configService->get_dns_a_records()
                ? __('Customers get the DNS records for their own domain on their account page.', 'waas-host')
                : __('Not ready yet: customers are asked to contact you to connect their own domain.', 'waas-host')],
            [__('Customer access', 'waas-host'), __('Each website gets the plugins of its plan.', 'waas-host')],
            [__('Website assistant', 'waas-host'), $assistant !== '' ? __('On: each ready website has an "Ask the website assistant" button.', 'waas-host') : __('Off', 'waas-host')],
        ];

        echo '<div class="ap-health"><h2>' . esc_html__('Set up by AnyPress', 'waas-host') . '</h2>';
        foreach ($rows as [$label, $detail]) {
            echo '<div class="ap-health-row"><div>' . esc_html($label) . '<small>' . esc_html($detail) . '</small></div><div></div></div>';
        }
        echo '</div>';
    }

    /** The Emails section: the list, or one email's editor with a live preview. */
    private function render_emails(): void
    {
        $emails = self::emails();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which email to edit.
        $key = isset($_GET['email']) ? sanitize_key(wp_unslash($_GET['email'])) : '';

        if (!isset($emails[$key])) {
            echo '<h2>' . esc_html__('Customer emails', 'waas-host') . '</h2><p class="ap-muted">'
                . esc_html__('What customers get when their website is ready, when they cancel, and when it goes offline.', 'waas-host') . '</p><div class="ap-emails">';
            foreach ($emails as $slug => $email) {
                $on = get_option($email['send'], 'on') === 'on';
                $subject = (string) get_option($email['subject']) ?: call_user_func($email['default_subject']);
                echo '<div class="ap-email"><span class="ap-pill ' . ($on ? 'is-ok' : '') . '">' . esc_html($on ? __('On', 'waas-host') : __('Off', 'waas-host')) . '</span>'
                    . '<div><strong>' . esc_html($email['title']) . '</strong><p>' . esc_html($email['when']) . '</p>'
                    /* translators: %s: the email's subject line */
                    . '<p class="ap-subject">' . esc_html(sprintf(__('Subject: %s', 'waas-host'), $subject)) . '</p></div>'
                    . '<a class="ap-btn is-sm" href="' . esc_url(self::url('emails', ['email' => $slug])) . '">' . esc_html__('Edit', 'waas-host') . '</a></div>';
            }
            echo '</div>';
            return;
        }

        $email = $emails[$key];
        $subject = (string) get_option($email['subject']) ?: call_user_func($email['default_subject']);
        $body = (string) get_option($email['body']) ?: call_user_func($email['default_body']);
        $reset_url = wp_nonce_url(admin_url('admin-post.php?action=' . self::RESET_EMAIL_ACTION . '&email=' . $key), self::RESET_EMAIL_ACTION . '_' . $key);
        ?>
        <p><a class="ap-btn is-sm" href="<?php echo esc_url(self::url('emails')); ?>">← <?php esc_html_e('All emails', 'waas-host'); ?></a></p>
        <h2><?php echo esc_html($email['title']); ?></h2>
        <p class="ap-muted"><?php echo esc_html($email['when']); ?></p>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows a message after the reset redirect.
        if (!empty($_GET['reset'])) : ?>
            <div class="ap-banner is-ok" role="status"><span><?php esc_html_e('Back to the default subject and message.', 'waas-host'); ?></span></div>
        <?php endif; ?>
        <form method="post" action="options.php" class="ap-form ap-email-editor" data-email="<?php echo esc_attr($key); ?>">
            <?php settings_fields(self::email_group($key)); ?>
            <div class="ap-field">
                <label class="ap-switch" for="<?php echo esc_attr($email['send']); ?>">
                    <input type="checkbox" id="<?php echo esc_attr($email['send']); ?>" name="<?php echo esc_attr($email['send']); ?>" <?php checked(get_option($email['send'], 'on'), 'on'); ?>>
                    <span><?php esc_html_e('Send this email', 'waas-host'); ?></span>
                </label>
            </div>
            <div class="ap-field">
                <label for="ap-email-subject"><?php esc_html_e('Subject', 'waas-host'); ?></label>
                <input type="text" class="large-text" id="ap-email-subject" name="<?php echo esc_attr($email['subject']); ?>" value="<?php echo esc_attr($subject); ?>">
            </div>
            <div class="ap-editor">
                <div class="ap-field">
                    <label for="ap-email-body"><?php esc_html_e('Message (HTML)', 'waas-host'); ?></label>
                    <textarea id="ap-email-body" name="<?php echo esc_attr($email['body']); ?>" rows="18" spellcheck="false"><?php echo esc_textarea($body); ?></textarea>
                    <div class="ap-tokens" aria-label="<?php esc_attr_e('Insert a placeholder', 'waas-host'); ?>">
                        <?php foreach ($email['placeholders'] as $placeholder) : ?>
                            <button type="button" class="ap-token" data-token="<?php echo esc_attr('{{ ' . $placeholder . ' }}'); ?>"><?php echo esc_html('{{ ' . $placeholder . ' }}'); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="ap-field">
                    <span class="ap-label"><?php esc_html_e('Preview with sample values', 'waas-host'); ?></span>
                    <div class="ap-preview"><p class="ap-preview-subject" id="ap-preview-subject"></p><iframe id="ap-preview" title="<?php esc_attr_e('Email preview', 'waas-host'); ?>" sandbox=""></iframe></div>
                </div>
            </div>
            <div class="ap-save">
                <span class="ap-muted" id="ap-test-result" role="status"></span>
                <button type="button" class="ap-btn" id="ap-send-test"><?php
                    /* translators: %s: the signed-in admin's email address */
                    echo esc_html(sprintf(__('Send a test to %s', 'waas-host'), wp_get_current_user()->user_email));
                ?></button>
                <a class="ap-btn" href="<?php echo esc_url($reset_url); ?>"><?php esc_html_e('Reset to default', 'waas-host'); ?></a>
                <?php submit_button(__('Save', 'waas-host'), 'primary ap-btn is-ink', 'submit', false); ?>
            </div>
        </form>
        <?php
    }

    /** Preview and test send for the email editor: the text as typed, rendered the way it is sent. */
    public function handle_email_preview(): void
    {
        check_ajax_referer(self::EMAIL_AJAX_NONCE);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do this.', 'waas-host')], 403);
        }

        $key = isset($_POST['email']) ? sanitize_key(wp_unslash($_POST['email'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the admin's own email HTML, only rendered back to them or sent to them.
        $subject = isset($_POST['subject']) ? (string) wp_unslash($_POST['subject']) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- as above.
        $body = isset($_POST['body']) ? (string) wp_unslash($_POST['body']) : '';
        if (!isset(self::emails()[$key])) {
            wp_send_json_error(['message' => __('Unknown email.', 'waas-host')], 400);
        }

        $rendered = Notifications::render_email($subject, $body, Notifications::sample_replaceables());

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by check_ajax_referer() above.
        if (!empty($_POST['send'])) {
            $to = wp_get_current_user()->user_email;
            /* translators: %s: the email's subject */
            $sent = wp_mail($to, sprintf(__('[Test] %s', 'waas-host'), $rendered['subject']), $rendered['body'], ['Content-Type: text/html; charset=UTF-8']);
            if (!$sent) {
                wp_send_json_error(['message' => __('WordPress couldn\'t send the test. Check this site\'s email setup.', 'waas-host')], 500);
            }
            /* translators: %s: email address */
            wp_send_json_success(['message' => sprintf(__('Test sent to %s.', 'waas-host'), $to)]);
        }

        wp_send_json_success($rendered);
    }

    public function handle_reset_email(): void
    {
        $key = isset($_GET['email']) ? sanitize_key(wp_unslash($_GET['email'])) : '';
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'waas-host'), 403);
        }
        check_admin_referer(self::RESET_EMAIL_ACTION . '_' . $key);

        $emails = self::emails();
        if (isset($emails[$key])) {
            delete_option($emails[$key]['subject']);
            delete_option($emails[$key]['body']);
        }

        wp_safe_redirect(self::url('emails', ['email' => $key, 'reset' => 1]));
        exit;
    }

    public function enqueue_email_editor($hook): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which admin page is open.
        if (!AdminHome::is_plugin_page() || self::current_tab() !== 'emails' || empty($_GET['email'])) {
            return;
        }

        $path = dirname(__DIR__) . '/Assets/js/waas-email-editor.js';
        wp_enqueue_script('waas-email-editor', plugins_url('Assets/js/waas-email-editor.js', dirname(__FILE__)), [], (string) filemtime($path), true);
        wp_localize_script('waas-email-editor', 'waasEmailEditor', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::EMAIL_AJAX_NONCE),
            'sending' => __('Sending…', 'waas-host'),
        ]);
    }

    public function register_waas_admin_settings()
    {
        // A storefront connected through wp-config has no key fields on the page, and options.php would blank them.
        if (!self::is_ready_made()) {
            register_setting('waas-admin', 'waas_api_base_url', [
                'sanitize_callback' => 'esc_url_raw'
            ]);
            register_setting('waas-admin', 'waas_api_key', [
                'sanitize_callback' => 'sanitize_text_field'
            ]);
            register_setting('waas-admin', 'waas_api_secret', [
                'sanitize_callback' => 'sanitize_text_field'
            ]);
        }
        // AnyPress sets these for a ready-made storefront; they aren't on its settings page, so they can't be posted.
        if (!self::is_ready_made()) {
            // Shown filled in, so saving this page keeps the assistant on unless the field is cleared.
            register_setting('waas-admin', 'waas_assistant_url', [
                'sanitize_callback' => 'esc_url_raw',
                'default' => (new ConfigService())->default_assistant_url(),
            ]);

            register_setting('waas-admin-addresses', 'waas_root_domain', [
                'sanitize_callback' => 'sanitize_text_field'
            ]);
            register_setting('waas-admin-addresses', 'waas_dns_a_records', [
                'sanitize_callback' => [ConfigService::class, 'sanitize_ip_list']
            ]);
            register_setting('waas-admin-addresses', 'waas_dns_cname_target', [
                'sanitize_callback' => [ConfigService::class, 'sanitize_hostname']
            ]);
            register_setting('waas-admin-access', Tenant::DEFAULT_USER_ROLE, [
                'default' => 'administrator',
                'sanitize_callback' => [$this, 'sanitize_user_role']
            ]);
            register_setting('waas-admin-access', self::SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION, [
                'default' => 'on'
            ]);
        }
        register_setting('waas-admin-cancellations', TenantsSubscriptionManager::DELETION_GRACE_DAYS_OPTION, [
            'default' => TenantsSubscriptionManager::DEFAULT_DELETION_GRACE_DAYS,
            'sanitize_callback' => fn ($value) => max(1, absint($value)),
        ]);
        // Off by default: turning it on changes prices on the live shop.
        register_setting('waas-admin-discount', VolumeDiscount::ENABLED_OPTION, [
            'default' => ''
        ]);

        foreach (self::emails() as $key => $email) {
            register_setting(self::email_group($key), $email['subject'], [
                'default' => call_user_func($email['default_subject'])
            ]);
            register_setting(self::email_group($key), $email['body'], [
                'default' => call_user_func($email['default_body'])
            ]);
            register_setting(self::email_group($key), $email['send'], [
                'default' => 'on'
            ]);
        }
    }

    /** "Set in wp-config.php as WAAS_<NAME>." when the constant is defined, else $hint. */
    private static function constant_hint(string $name, string $hint): string
    {
        if (!ConfigService::has_constant($name)) {
            return $hint;
        }

        /* translators: %s: a wp-config.php constant name */
        return sprintf(__('Set in wp-config.php as %s, so it can\'t be changed here.', 'waas-host'), 'WAAS_' . $name);
    }

    private function add_field(string $id, string $title, string $page, string $section, array $args)
    {
        $args['id'] = $id;
        if (!in_array($args['type'], ['checkbox'], true)) {
            $args['label_for'] = $id;
        }

        add_settings_field($id, $title, [$this, 'render_settings_field'], $page, $section, $args);
    }

    public function add_waas_admin_settings()
    {
        // A ready-made storefront shows these as set by AnyPress instead (render_managed_settings()).
        if (!self::is_ready_made()) {
            // Connection
            add_settings_section(
                'api_credentials',
                __('AnyPress API', 'waas-host'),
                fn () => print('<p>' . esc_html__('This storefront creates, pauses and restores customer websites through the AnyPress API. Make a key for it on the API keys page of your AnyPress dashboard.', 'waas-host') . '</p>'),
                'waas-admin'
            );

            $this->add_field('waas_api_base_url', __('API address', 'waas-host'), 'waas-admin', 'api_credentials', [
                'type' => 'url',
                'class_name' => 'regular-text code',
                'placeholder' => 'https://api.anypress.io',
                'constant' => 'API_BASE_URL',
                'hint' => self::constant_hint('API_BASE_URL', __('https://api.anypress.io, unless you run your own AnyPress API.', 'waas-host')),
            ]);
            $this->add_field('waas_api_key', __('API key', 'waas-host'), 'waas-admin', 'api_credentials', [
                'type' => 'text',
                'class_name' => 'regular-text code',
                'constant' => 'API_KEY',
                'hint' => self::constant_hint('API_KEY', ''),
            ]);
            $this->add_field('waas_api_secret', __('API secret', 'waas-host'), 'waas-admin', 'api_credentials', [
                'type' => 'password',
                'class_name' => 'regular-text code',
                'constant' => 'API_SECRET',
                'hint' => self::constant_hint('API_SECRET', ''),
            ]);
            $this->add_field('waas_assistant_url', __('Website assistant', 'waas-host'), 'waas-admin', 'api_credentials', [
                'type' => 'url',
                'class_name' => 'regular-text code',
                'placeholder' => 'https://assistant.example.com',
                'constant' => 'ASSISTANT_URL',
                'hint' => self::constant_hint('ASSISTANT_URL', __('The website assistant\'s address. Each ready website on the customer\'s account page gets an "Ask the website assistant" button that opens it already signed in. On the AnyPress API this is AnyPress\'s own assistant; clear the field to hide the button.', 'waas-host')),
            ]);

            // Website addresses
            add_settings_section(
                'waas_host_settings',
                __('Website addresses', 'waas-host'),
                fn () => print('<p>' . esc_html__('Where new websites live, and the DNS records customers add to use their own domain.', 'waas-host') . '</p>'),
                'waas-admin-addresses'
            );

            $this->add_field('waas_root_domain', __('Website domain', 'waas-host'), 'waas-admin-addresses', 'waas_host_settings', [
                'type' => 'text',
                'class_name' => 'regular-text code',
                'placeholder' => 'sites.example.com',
                'hint' => __('Each new website gets an address under this domain: with sites.example.com, a website named shop is at shop.sites.example.com.', 'waas-host'),
            ]);
            $this->add_field('waas_dns_a_records', __('IP address for custom domains', 'waas-host'), 'waas-admin-addresses', 'waas_host_settings', [
                'type' => 'text',
                'class_name' => 'regular-text code',
                'placeholder' => '203.0.113.10',
                'constant' => 'DNS_A_RECORDS',
                'hint' => self::constant_hint('DNS_A_RECORDS', __('Customers add this as an A record for their own domain. Separate several with commas. Leave empty and the customer\'s domain page tells them to contact support.', 'waas-host')),
            ]);
            $this->add_field('waas_dns_cname_target', __('Address for www and other subdomains', 'waas-host'), 'waas-admin-addresses', 'waas_host_settings', [
                'type' => 'text',
                'class_name' => 'regular-text code',
                'placeholder' => 'sites.example.com',
                'constant' => 'DNS_CNAME_TARGET',
                'hint' => self::constant_hint('DNS_CNAME_TARGET', __('Customers add this as a CNAME record for a subdomain such as www.', 'waas-host')),
            ]);

            // Customer access
            add_settings_section(
                'waas_store_access',
                __('Customer access', 'waas-host'),
                fn () => print('<p>' . esc_html__('What customers can do on their own website. Changes apply to websites created from now on.', 'waas-host') . '</p>'),
                'waas-admin-access'
            );

            $this->add_field(Tenant::DEFAULT_USER_ROLE, __('Customer\'s role on their website', 'waas-host'), 'waas-admin-access', 'waas_store_access', [
                'type' => 'role',
                'hint' => __('The role the customer gets when they sign in to their website from their account page.', 'waas-host'),
            ]);
            $this->add_field(self::SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION, __('Plugins', 'waas-host'), 'waas-admin-access', 'waas_store_access', [
                'type' => 'checkbox',
                'label' => __('Plugins come from the plan only', 'waas-host'),
                'hint' => __('Customers can\'t install, turn on or delete plugins. Websites that already exist keep what they have.', 'waas-host'),
            ]);

        }

        // Cancellations
        add_settings_section(
            'waas_store_cancellation',
            __('Cancellations', 'waas-host'),
            fn () => print('<p>' . esc_html__('What happens to a website when its subscription ends.', 'waas-host') . '</p>'),
            'waas-admin-cancellations'
        );

        $this->add_field(TenantsSubscriptionManager::DELETION_GRACE_DAYS_OPTION, __('Keep an offline website for', 'waas-host'), 'waas-admin-cancellations', 'waas_store_cancellation', [
            'type' => 'number',
            'class_name' => 'small-text',
            'min' => 1,
            'suffix' => __('days', 'waas-host'),
            'hint' => __('When a subscription is cancelled or expires, its website is backed up and taken offline, then deleted after this many days. If the customer pays again before then, the website comes back as it was.', 'waas-host'),
        ]);

        // Volume discount
        add_settings_section(
            'waas_volume_discount',
            __('Volume discount', 'waas-host'),
            fn () => print('<p>' . esc_html__('A discount on new websites for customers who already have several. Their live websites are counted together with the one they are buying.', 'waas-host') . '</p>'),
            'waas-admin-discount'
        );

        $this->add_field(VolumeDiscount::ENABLED_OPTION, __('Volume discount', 'waas-host'), 'waas-admin-discount', 'waas_volume_discount', [
            'type' => 'checkbox',
            'label' => __('Discount website plans for customers with several live websites', 'waas-host'),
            'tiers' => true,
            'hint' => __('Applied at checkout to signed-in customers and to anyone using an existing customer\'s email, on the first payment and every renewal. When a customer moves to another tier, their other plans get the new price from their next renewal, with no charge now (plans billed by Stripe or by the store; others get a note on the subscription to change by hand). Needs FluentCart.', 'waas-host'),
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
                if (!empty($args['tiers'])) {
                    $this->render_discount_tiers();
                }
                break;
            case 'role':
                $roles = [
                    'administrator' => __('Administrator', 'waas-host'),
                    'editor' => __('Editor', 'waas-host'),
                    'author' => __('Author', 'waas-host'),
                ];
                $value = (string) ($value ?: 'administrator');
                if (!isset($roles[$value])) {
                    $roles[$value] = $value;
                }
                $describedby = !empty($args['hint']) ? ' aria-describedby="' . esc_attr($id . '-description') . '"' : '';
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($id) . '"' . $describedby . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
                foreach ($roles as $role => $label) {
                    printf('<option value="%s"%s>%s</option>', esc_attr($role), selected($value, $role, false), esc_html($label));
                }
                echo '</select>';
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
                echo '<input' . $html . ($disabled ? ' disabled' : '') . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every attribute value is esc_attr() above.
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

    /** The volume discount tiers as a small table, so sellers see what customers get before turning it on. */
    private function render_discount_tiers(): void
    {
        echo '<table class="ap-tiers"><thead><tr><th>' . esc_html__('Live websites', 'waas-host') . '</th><th>' . esc_html__('Discount', 'waas-host') . '</th></tr></thead><tbody>';
        $tiers = VolumeDiscount::tiers();
        foreach ($tiers as $i => $tier) {
            $next = $tiers[$i + 1]['sites'] ?? null;
            $range = $next === null ? sprintf('%d+', $tier['sites']) : sprintf('%d–%d', $tier['sites'], $next - 1);
            echo '<tr><td>' . esc_html($range) . '</td><td>' . esc_html(VolumeDiscount::format_percent($tier['percent']) . '%') . '</td></tr>';
        }
        echo '</tbody></table>';
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

        return !empty($option_val) && !Notifications::is_old_default_body((string) $option_val) ? $option_val : $text;
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

        return !empty($option_val) && !Notifications::is_old_default_body((string) $option_val) ? $option_val : $text;
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

        return !empty($option_val) && !Notifications::is_old_default_body((string) $option_val) ? $option_val : $text;
    }

    public function waas_could_not_connect_to_api_notice_error()
    {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Couldn\'t connect to the AnyPress API. Check the API address, key and secret in AnyPress → Settings → Connection.', 'waas-host')
            . '</p></div>';
    }

    public function sanitize_user_role($value)
    {
        $trimmed = trim($value);
        return empty($trimmed) ? 'administrator' : $trimmed;
    }
}

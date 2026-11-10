<?php

namespace AnyPressHost\Features;

/**
 * The customer emails on the settings page's Emails section: their settings, the editor with its live preview and test
 * send, and the filters that hand the saved texts to Notifications. The option names stay on AdminSettings.
 */
class EmailSettings
{
    public const RESET_EMAIL_ACTION = 'anypress_reset_email';
    public const EMAIL_AJAX_NONCE = 'anypress_email_editor';

    public function __construct()
    {
        add_action('init', [$this, 'register_settings']);
        add_action('wp_ajax_anypress_email_preview', [$this, 'handle_email_preview']);
        add_action('admin_post_' . self::RESET_EMAIL_ACTION, [$this, 'handle_reset_email']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_email_editor']);
        add_filter('anypress_tenant_ready_email_allowed', [$this, 'allow_tenant_ready_email']);
        add_filter('anypress_tenant_ready_email_subject', [$this, 'tenant_ready_email_subject'], 10, 1);
        add_filter('anypress_tenant_ready_email_body', [$this, 'tenant_ready_email_body'], 10, 1);
        add_filter('anypress_suspend_scheduled_email_allowed', [$this, 'allow_suspend_scheduled_email']);
        add_filter('anypress_notification_settings_suspend_scheduled_email_subject', [$this, 'suspend_scheduled_email_subject'], 10, 1);
        add_filter('anypress_notification_settings_suspend_scheduled_email_body', [$this, 'suspend_scheduled_email_body'], 10, 1);
        add_filter('anypress_suspended_email_allowed', [$this, 'allow_suspended_email']);
        add_filter('anypress_suspended_email_subject', [$this, 'suspended_email_subject'], 10, 1);
        add_filter('anypress_suspended_email_body', [$this, 'suspended_email_body'], 10, 1);
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
                'title' => __('Website ready', 'anypress-host'),
                'when' => __('Sent when a new website is up.', 'anypress-host'),
                'send' => AdminSettings::SHOULD_SEND_TENANT_READY_EMAIL_OPTION,
                'subject' => AdminSettings::TENANT_READY_EMAIL_SUBJECT_OPTION,
                'body' => AdminSettings::TENANT_READY_EMAIL_BODY_OPTION,
                'default_subject' => [Notifications::class, 'get_default_tenant_ready_subject'],
                'default_body' => [Notifications::class, 'get_default_tenant_ready_body'],
                'placeholders' => $common,
            ],
            'cancelled' => [
                'title' => __('Subscription cancelled', 'anypress-host'),
                'when' => __('Sent when a customer cancels: their website stays online until the end of the period they paid for.', 'anypress-host'),
                'send' => AdminSettings::SHOULD_SEND_SUSPEND_SCHEDULED_EMAIL_OPTION,
                'subject' => AdminSettings::SUSPEND_SCHEDULED_EMAIL_SUBJECT_OPTION,
                'body' => AdminSettings::SUSPEND_SCHEDULED_EMAIL_BODY_OPTION,
                'default_subject' => [Notifications::class, 'get_default_suspend_scheduled_subject'],
                'default_body' => [Notifications::class, 'get_default_suspend_scheduled_body'],
                'placeholders' => array_merge($common, ['suspend_date', 'deletion_grace_days']),
            ],
            'offline' => [
                'title' => __('Website taken offline', 'anypress-host'),
                'when' => __('Sent when a subscription ends (a refund, the end of a cancelled plan\'s paid period, or expiry) and the website goes offline.', 'anypress-host'),
                'send' => AdminSettings::SHOULD_SEND_SUSPENDED_EMAIL_OPTION,
                'subject' => AdminSettings::SUSPENDED_EMAIL_SUBJECT_OPTION,
                'body' => AdminSettings::SUSPENDED_EMAIL_BODY_OPTION,
                'default_subject' => [Notifications::class, 'get_default_suspended_subject'],
                'default_body' => [Notifications::class, 'get_default_suspended_body'],
                'placeholders' => array_merge($common, ['delete_date', 'deletion_grace_days']),
            ],
        ];
    }

    private static function email_group(string $key): string
    {
        return 'anypress-admin-email-' . $key;
    }

    public function register_settings(): void
    {
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

    /** The Emails section: the list, or one email's editor with a live preview. */
    public static function render(): void
    {
        $emails = self::emails();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which email to edit.
        $key = isset($_GET['email']) ? sanitize_key(wp_unslash($_GET['email'])) : '';

        if (!isset($emails[$key])) {
            echo '<h2>' . esc_html__('Customer emails', 'anypress-host') . '</h2><p class="ap-muted">'
                . esc_html__('What customers get when their website is ready, when they cancel, and when it goes offline.', 'anypress-host') . '</p><div class="ap-emails">';
            foreach ($emails as $slug => $email) {
                $on = get_option($email['send'], 'on') === 'on';
                $subject = (string) get_option($email['subject']) ?: call_user_func($email['default_subject']);
                echo '<div class="ap-email"><span class="ap-pill ' . ($on ? 'is-ok' : '') . '">' . esc_html($on ? __('On', 'anypress-host') : __('Off', 'anypress-host')) . '</span>'
                    . '<div><strong>' . esc_html($email['title']) . '</strong><p>' . esc_html($email['when']) . '</p>'
                    /* translators: %s: the email's subject line */
                    . '<p class="ap-subject">' . esc_html(sprintf(__('Subject: %s', 'anypress-host'), $subject)) . '</p></div>'
                    . '<a class="ap-btn is-sm" href="' . esc_url(AdminSettings::url('emails', ['email' => $slug])) . '">' . esc_html__('Edit', 'anypress-host') . '</a></div>';
            }
            echo '</div>';
            return;
        }

        $email = $emails[$key];
        $subject = (string) get_option($email['subject']) ?: call_user_func($email['default_subject']);
        $body = (string) get_option($email['body']) ?: call_user_func($email['default_body']);
        $reset_url = wp_nonce_url(admin_url('admin-post.php?action=' . self::RESET_EMAIL_ACTION . '&email=' . $key), self::RESET_EMAIL_ACTION . '_' . $key);
        ?>
        <p><a class="ap-btn is-sm" href="<?php echo esc_url(AdminSettings::url('emails')); ?>">← <?php esc_html_e('All emails', 'anypress-host'); ?></a></p>
        <h2><?php echo esc_html($email['title']); ?></h2>
        <p class="ap-muted"><?php echo esc_html($email['when']); ?></p>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows a message after the reset redirect.
        if (!empty($_GET['reset'])) : ?>
            <div class="ap-banner is-ok" role="status"><span><?php esc_html_e('Back to the default subject and message.', 'anypress-host'); ?></span></div>
        <?php endif; ?>
        <form method="post" action="options.php" class="ap-form ap-email-editor" data-email="<?php echo esc_attr($key); ?>">
            <?php settings_fields(self::email_group($key)); ?>
            <div class="ap-field">
                <label class="ap-switch" for="<?php echo esc_attr($email['send']); ?>">
                    <input type="checkbox" id="<?php echo esc_attr($email['send']); ?>" name="<?php echo esc_attr($email['send']); ?>" <?php checked(get_option($email['send'], 'on'), 'on'); ?>>
                    <span><?php esc_html_e('Send this email', 'anypress-host'); ?></span>
                </label>
            </div>
            <div class="ap-field">
                <label for="ap-email-subject"><?php esc_html_e('Subject', 'anypress-host'); ?></label>
                <input type="text" class="large-text" id="ap-email-subject" name="<?php echo esc_attr($email['subject']); ?>" value="<?php echo esc_attr($subject); ?>">
            </div>
            <div class="ap-editor">
                <div class="ap-field">
                    <label for="ap-email-body"><?php esc_html_e('Message (HTML)', 'anypress-host'); ?></label>
                    <textarea id="ap-email-body" name="<?php echo esc_attr($email['body']); ?>" rows="18" spellcheck="false"><?php echo esc_textarea($body); ?></textarea>
                    <div class="ap-tokens" aria-label="<?php esc_attr_e('Insert a placeholder', 'anypress-host'); ?>">
                        <?php foreach ($email['placeholders'] as $placeholder) : ?>
                            <button type="button" class="ap-token" data-token="<?php echo esc_attr('{{ ' . $placeholder . ' }}'); ?>"><?php echo esc_html('{{ ' . $placeholder . ' }}'); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="ap-field">
                    <span class="ap-label"><?php esc_html_e('Preview with sample values', 'anypress-host'); ?></span>
                    <div class="ap-preview"><p class="ap-preview-subject" id="ap-preview-subject"></p><iframe id="ap-preview" title="<?php esc_attr_e('Email preview', 'anypress-host'); ?>" sandbox=""></iframe></div>
                </div>
            </div>
            <div class="ap-save">
                <span class="ap-muted" id="ap-test-result" role="status"></span>
                <button type="button" class="ap-btn" id="ap-send-test"><?php
                    /* translators: %s: the signed-in admin's email address */
                    echo esc_html(sprintf(__('Send a test to %s', 'anypress-host'), wp_get_current_user()->user_email));
                ?></button>
                <a class="ap-btn" href="<?php echo esc_url($reset_url); ?>"><?php esc_html_e('Reset to default', 'anypress-host'); ?></a>
                <?php submit_button(__('Save', 'anypress-host'), 'primary ap-btn is-ink', 'submit', false); ?>
            </div>
        </form>
        <?php
    }

    /** Preview and test send for the email editor: the text as typed, rendered the way it is sent. */
    public function handle_email_preview(): void
    {
        check_ajax_referer(self::EMAIL_AJAX_NONCE);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do this.', 'anypress-host')], 403);
        }

        $key = isset($_POST['email']) ? sanitize_key(wp_unslash($_POST['email'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the admin's own email HTML, only rendered back to them or sent to them.
        $subject = isset($_POST['subject']) ? (string) wp_unslash($_POST['subject']) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- as above.
        $body = isset($_POST['body']) ? (string) wp_unslash($_POST['body']) : '';
        if (!isset(self::emails()[$key])) {
            wp_send_json_error(['message' => __('Unknown email.', 'anypress-host')], 400);
        }

        $rendered = Notifications::render_email($subject, $body, Notifications::sample_replaceables());

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by check_ajax_referer() above.
        if (!empty($_POST['send'])) {
            $to = wp_get_current_user()->user_email;
            /* translators: %s: the email's subject */
            $sent = wp_mail($to, sprintf(__('[Test] %s', 'anypress-host'), $rendered['subject']), $rendered['body'], ['Content-Type: text/html; charset=UTF-8']);
            if (!$sent) {
                wp_send_json_error(['message' => __('WordPress couldn\'t send the test. Check this site\'s email setup.', 'anypress-host')], 500);
            }
            /* translators: %s: email address */
            wp_send_json_success(['message' => sprintf(__('Test sent to %s.', 'anypress-host'), $to)]);
        }

        wp_send_json_success($rendered);
    }

    public function handle_reset_email(): void
    {
        $key = isset($_GET['email']) ? sanitize_key(wp_unslash($_GET['email'])) : '';
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'anypress-host'), 403);
        }
        check_admin_referer(self::RESET_EMAIL_ACTION . '_' . $key);

        $emails = self::emails();
        if (isset($emails[$key])) {
            delete_option($emails[$key]['subject']);
            delete_option($emails[$key]['body']);
        }

        wp_safe_redirect(AdminSettings::url('emails', ['email' => $key, 'reset' => 1]));
        exit;
    }

    public function enqueue_email_editor($hook): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which admin page is open.
        if (!AdminHome::is_plugin_page() || AdminSettings::current_tab() !== 'emails' || empty($_GET['email'])) {
            return;
        }

        $path = dirname(__DIR__) . '/Assets/js/anypress-email-editor.js';
        wp_enqueue_script('anypress-email-editor', plugins_url('Assets/js/anypress-email-editor.js', __DIR__), [], (string) filemtime($path), true);
        wp_localize_script('anypress-email-editor', 'anypressEmailEditor', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::EMAIL_AJAX_NONCE),
            'sending' => __('Sending…', 'anypress-host'),
        ]);
    }

    public function allow_tenant_ready_email($allowed)
    {
        // If somebody else disallowed, respect their decision.
        if (!$allowed) {
            return $allowed;
        }

        return get_option(AdminSettings::SHOULD_SEND_TENANT_READY_EMAIL_OPTION) === 'on';
    }

    public function allow_suspend_scheduled_email($allowed)
    {
        if (!$allowed) {
            return $allowed;
        }

        return get_option(AdminSettings::SHOULD_SEND_SUSPEND_SCHEDULED_EMAIL_OPTION, 'on') === 'on';
    }

    public function suspend_scheduled_email_subject($text)
    {
        $option_val = get_option(AdminSettings::SUSPEND_SCHEDULED_EMAIL_SUBJECT_OPTION);

        return !empty($option_val) ? $option_val : $text;
    }

    public function suspend_scheduled_email_body($text)
    {
        $option_val = get_option(AdminSettings::SUSPEND_SCHEDULED_EMAIL_BODY_OPTION);

        return !empty($option_val) && !Notifications::is_old_default_body((string) $option_val) ? $option_val : $text;
    }

    public function allow_suspended_email($allowed)
    {
        if (!$allowed) {
            return $allowed;
        }

        return get_option(AdminSettings::SHOULD_SEND_SUSPENDED_EMAIL_OPTION, 'on') === 'on';
    }

    public function suspended_email_subject($text)
    {
        $option_val = get_option(AdminSettings::SUSPENDED_EMAIL_SUBJECT_OPTION);

        return !empty($option_val) ? $option_val : $text;
    }

    public function suspended_email_body($text)
    {
        $option_val = get_option(AdminSettings::SUSPENDED_EMAIL_BODY_OPTION);

        return !empty($option_val) && !Notifications::is_old_default_body((string) $option_val) ? $option_val : $text;
    }

    public function tenant_ready_email_subject($text)
    {
        $option_val = get_option(AdminSettings::TENANT_READY_EMAIL_SUBJECT_OPTION);

        return !empty($option_val) ? $option_val : $text;
    }

    public function tenant_ready_email_body($text)
    {
        $option_val = get_option(AdminSettings::TENANT_READY_EMAIL_BODY_OPTION);

        return !empty($option_val) && !Notifications::is_old_default_body((string) $option_val) ? $option_val : $text;
    }
}

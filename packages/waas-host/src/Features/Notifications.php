<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\Tenant;

class Notifications
{
    public static function init()
    {
        add_action('waas_tenant_ready', [__CLASS__, 'send_email_on_tenant_ready'], 10, 1);
        add_action('waas_tenant_suspend_scheduled', [__CLASS__, 'send_email_on_suspend_scheduled'], 10, 2);
        add_action('waas_tenant_suspended', [__CLASS__, 'send_email_on_suspended'], 10, 2);
        // FluentCart's receipts open with its store logo: keep it the storefront's own logo.
        foreach (['update_option_site_logo', 'add_option_site_logo', 'delete_option_site_logo', 'customize_save_after'] as $hook) {
            add_action($hook, [__CLASS__, 'sync_cart_logo']);
        }
    }

    /** Sets FluentCart's store logo (shown at the top of its emails) to the site logo, or clears it. */
    public static function sync_cart_logo(): void
    {
        if (!class_exists('\FluentCart\Api\StoreSettings')) {
            return;
        }
        $id = (int) (get_option('site_logo') ?: get_theme_mod('custom_logo'));
        $url = $id ? (string) wp_get_attachment_image_url($id, 'medium') : '';
        (new \FluentCart\Api\StoreSettings())->save(['store_logo' => ['id' => $id ?: '', 'url' => $url]]);
    }

    /**
     * The subscription ended (refund, end of the paid period, expiry) and the website is offline, deleted at $delete_at.
     * A store put on hold (paused, a failed payment) has no deletion date and gets no email here.
     */
    public static function send_email_on_suspended($subscription_id, $delete_at)
    {
        if ((int) $delete_at <= 0 || !apply_filters('waas_suspended_email_allowed', true, $subscription_id)) {
            return;
        }

        $recipient = apply_filters('waas_suspended_email_recipient', Commerce::require_adapter()->get_customer_email($subscription_id), $subscription_id);
        $replaceables = self::get_suspended_replaceables($subscription_id, $recipient, (int) $delete_at);
        $subject = self::do_replacements(apply_filters('waas_suspended_email_subject', self::get_default_suspended_subject(), $subscription_id), $replaceables);
        $body = self::do_replacements(apply_filters('waas_suspended_email_body', self::get_default_suspended_body(), $subscription_id, $recipient), $replaceables);
        $headers = apply_filters('waas_suspended_email_headers', self::headers(), $subscription_id);

        wp_mail($recipient, $subject, self::wrap_html($body, $subject), $headers);
    }

    public static function get_default_suspended_subject()
    {
        return __('Your website is offline', 'waas-host');
    }

    public static function get_default_suspended_body()
    {
        return '<p>Your subscription has ended, so your website {{ tenant_domain }} is now offline.</p>
<p>We keep a backup of it until <strong>{{ delete_date }}</strong>. After that it is deleted. Renew before then and your website comes back just as it was.</p>
<p><a class="waas-button" href="{{ storefront_subscription_details_url }}">Renew your plan</a></p>
<p class="waas-note">Your plan and billing are in your subscription details at <a href="{{ storefront_url }}">{{ storefront_name }}</a>.</p>';
    }

    public static function get_suspended_replaceables($subscription_id, $recipient, int $delete_at): array
    {
        return apply_filters('waas_suspended_email_replaceables', self::get_replaceables($subscription_id, $recipient) + [
            'delete_date' => self::format_date($delete_at),
            'deletion_grace_days' => (string) TenantsSubscriptionManager::get_deletion_grace_days(),
        ], $subscription_id, $recipient);
    }

    /** The subscription was cancelled but is paid until $suspend_at: tell the customer the website stays until then. */
    public static function send_email_on_suspend_scheduled($subscription_id, $suspend_at)
    {
        if (!apply_filters('waas_suspend_scheduled_email_allowed', true, $subscription_id)) {
            return;
        }

        $recipient = apply_filters('waas_suspend_scheduled_email_recipient', Commerce::require_adapter()->get_customer_email($subscription_id), $subscription_id);
        $replaceables = self::get_suspend_scheduled_replaceables($subscription_id, $recipient, (int) $suspend_at);
        $subject = self::do_replacements(apply_filters('waas_notification_settings_suspend_scheduled_email_subject', self::get_default_suspend_scheduled_subject(), $subscription_id), $replaceables);
        $body = self::do_replacements(apply_filters('waas_notification_settings_suspend_scheduled_email_body', self::get_default_suspend_scheduled_body(), $subscription_id, $recipient), $replaceables);
        $headers = apply_filters('waas_suspend_scheduled_email_headers', self::headers(), $subscription_id);

        wp_mail($recipient, $subject, self::wrap_html($body, $subject), $headers);
    }

    public static function get_default_suspend_scheduled_subject()
    {
        return __('Your website stays online until {{ suspend_date }}', 'waas-host');
    }

    public static function get_default_suspend_scheduled_body()
    {
        return '<p>Your subscription is cancelled. Your website <a href="{{ tenant_domain }}">{{ tenant_domain }}</a> stays online until <strong>{{ suspend_date }}</strong>, the end of the period you paid for.</p>
<p>After that it goes offline, and it is deleted {{ deletion_grace_days }} days later. Change your mind before then and your website comes back just as it was.</p>
<p><a class="waas-button" href="{{ tenant_one_click_login_url }}">Manage your website</a></p>
<p class="waas-note">Your plan and billing are in your <a href="{{ storefront_subscription_details_url }}">subscription details</a> at <a href="{{ storefront_url }}">{{ storefront_name }}</a>.</p>';
    }

    public static function get_suspend_scheduled_replaceables($subscription_id, $recipient, int $suspend_at): array
    {
        return apply_filters('waas_suspend_scheduled_email_replaceables', self::get_replaceables($subscription_id, $recipient) + [
            'suspend_date' => self::format_date($suspend_at),
            'deletion_grace_days' => (string) TenantsSubscriptionManager::get_deletion_grace_days(),
        ], $subscription_id, $recipient);
    }

    /** A date in the site's date format, for customer-facing text. */
    public static function format_date(int $timestamp): string
    {
        $format = (string) get_option('date_format') ?: 'F j, Y';

        return function_exists('wp_date') ? (string) wp_date($format, $timestamp) : gmdate($format, $timestamp);
    }

    public static function send_email_on_tenant_ready($subscription_id)
    {
        $should_send_email = apply_filters('waas_tenant_ready_email_allowed', true, $subscription_id);
        if (!$should_send_email) {
            return;
        }

        $recipient = apply_filters('waas_tenant_ready_email_recipient', Commerce::require_adapter()->get_customer_email($subscription_id), $subscription_id);
        $subject = self::get_ready_email_subject($subscription_id, $recipient);
        $headers = apply_filters('waas_tenant_ready_email_headers', self::headers(), $subscription_id);
        $body = self::get_ready_email_body($subscription_id, $recipient);

        wp_mail($recipient, $subject, self::wrap_html($body, $subject), $headers);
    }

    private static function get_ready_email_subject($subscription_id, $recipient)
    {
        $unfiltered_subject = apply_filters('waas_tenant_ready_email_subject', self::get_default_tenant_ready_subject(), $subscription_id);
        $replaceables = self::get_replaceables($subscription_id, $recipient);
        return self::do_replacements($unfiltered_subject, $replaceables);
    }

    public static function get_default_tenant_ready_subject()
    {
        return __('Your website is ready', 'waas-host');
    }

    private static function get_ready_email_body($subscription_id, $recipient)
    {
        $unfiltered_body = apply_filters('waas_tenant_ready_email_body', self::get_default_tenant_ready_body(), $subscription_id, $recipient);
        $replaceables = self::get_replaceables($subscription_id, $recipient);
        return self::do_replacements($unfiltered_body, $replaceables);
    }

    public static function get_default_tenant_ready_body()
    {
        return '<p>Good news: your website is ready. You can visit it at <a href="{{ tenant_domain }}">{{ tenant_domain }}</a>.</p>
<p>Sign in to start adding your pages, photos and products.</p>
<p><a class="waas-button" href="{{ tenant_one_click_login_url }}">Sign in to your website</a></p>
<p class="waas-note">The button opens your website page at <a href="{{ storefront_url }}">{{ storefront_name }}</a>, where one click signs you in. Your plan and billing are in your <a href="{{ storefront_subscription_details_url }}">subscription details</a>.</p>';
    }

    /**
     * True for a saved body that is just a default from before 2.6.0 (saving the Emails tab stores the default),
     * so it gives way to the current default instead of keeping the old look.
     */
    public static function is_old_default_body(string $body): bool
    {
        return in_array(md5((string) preg_replace('/\s+/', ' ', trim($body))), [
            'adfd916077426b8f69526bebab5fd528', // website ready
            '2dbbf32431233a3b6a552fa8cf0e4bcb', // stays online until
            'd44c763f8db37be108ad2ebdc9f6ec4a', // offline
        ], true);
    }

    /** HTML mail, replying to the storefront's support address. */
    public static function headers(): array
    {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $support = self::support_email();
        if ($support !== '') {
            $headers[] = 'Reply-To: ' . $support;
        }

        return $headers;
    }

    /** Where customers write with questions: WAAS_SUPPORT_EMAIL, else the site's admin email. */
    public static function support_email(): string
    {
        $email = (string) (ConfigService::constant('SUPPORT_EMAIL') ?: get_option('admin_email', ''));

        return (string) apply_filters('waas_email_support_address', is_email($email) ? $email : '');
    }

    /** The storefront's logo (site logo, else site icon), or '' to show its name alone. */
    public static function logo_url(): string
    {
        $url = '';
        // Block themes keep the logo in the site_logo option; classic themes in the custom_logo theme mod.
        $logo = (int) (get_option('site_logo') ?: get_theme_mod('custom_logo'));
        if ($logo > 0) {
            $url = (string) wp_get_attachment_image_url($logo, 'medium');
        }
        if ($url === '' && function_exists('get_site_icon_url')) {
            $url = (string) get_site_icon_url(96);
        }

        return (string) apply_filters('waas_email_logo_url', $url);
    }

    /**
     * Puts an email body (paragraphs, a `waas-button` link, a `waas-note` paragraph) in the storefront's layout:
     * logo and name, a card with the subject as its heading, and a footer with the support address.
     * A body that is already a whole HTML document (custom templates made before 2.6.0) is sent as it is.
     */
    public static function wrap_html(string $body, string $title): string
    {
        if (preg_match('/^\s*(<!DOCTYPE|<html)/i', $body)) {
            return $body;
        }

        // The same layout as the AnyPress API's account emails, so every email a customer gets looks alike.
        $ink = '#141414';
        $soft = '#5f5a55';
        $line = '#e9e4de';
        $paper = '#faf8f5';
        $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
        // A coloured strip along the card's top: none by default, so a seller's emails carry only their own brand.
        // A CSS colour or gradient, e.g. the AnyPress shop's 'linear-gradient(90deg,#ffc75a,#ff7a2a,#ee2f5e,#d21fa4)'.
        $accent = (string) apply_filters('waas_email_accent', '');
        $logo = self::logo_url();
        $support = self::support_email();

        // A paragraph holding only a waas-button link becomes the button, with the link written out under it.
        $content = (string) preg_replace_callback(
            '#<p>\s*<a class="waas-button" href="([^"]*)">(.*?)</a>\s*</p>#s',
            fn ($m) => '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 12px"><tr><td style="background:' . $ink . ';border-radius:8px">'
                . '<a style="display:inline-block;padding:14px 24px;font-size:16px;font-weight:600;line-height:20px;color:#ffffff;text-decoration:none" href="' . $m[1] . '">' . $m[2] . '</a></td></tr></table>'
                . '<p style="margin:0 0 24px;font-size:13px;line-height:19px;color:' . $soft . '">' . esc_html__('Or paste this link into your browser:', 'waas-host')
                . '<br><span style="color:' . $ink . ';word-break:break-all">' . $m[1] . '</span></p>',
            $body
        );
        $content = strtr($content, [
            '<p class="waas-note">' => '<p style="margin:0 0 16px;border-top:1px solid ' . $line . ';padding-top:16px;font-size:14px;line-height:21px;color:' . $soft . '">',
            '<p>' => '<p style="margin:0 0 16px;font-size:16px;line-height:25px;color:' . $ink . '">',
            '<a href=' => '<a style="color:' . $ink . '" href=',
        ]);

        $name = '<span style="font-size:20px;font-weight:700;letter-spacing:-0.01em;color:' . $ink . '">' . esc_html(get_bloginfo('name')) . '</span>';
        $head = $logo !== ''
            ? '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="padding-right:10px;vertical-align:middle"><img src="' . esc_url($logo) . '" width="36" height="36" alt="" style="display:block;border:0"></td><td style="vertical-align:middle">' . $name . '</td></tr></table>'
            : $name;
        $footer = $support !== ''
            ? sprintf(
                /* translators: %s: the support email address, as a link */
                esc_html__('Questions? Write to %s or just reply to this email.', 'waas-host'),
                '<a href="mailto:' . esc_attr($support) . '" style="color:' . $soft . ';text-decoration:underline">' . esc_html($support) . '</a>'
            )
            : esc_html__('Questions? Just reply to this email.', 'waas-host');

        return '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . esc_html($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . $paper . '">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $paper . '"><tr><td align="center" style="padding:32px 12px;font-family:' . $font . '">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">'
            . '<tr><td style="padding:0 4px 20px">' . $head . '</td></tr>'
            . '<tr><td style="background:#ffffff;border:1px solid ' . $line . ';border-radius:12px;overflow:hidden">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . ($accent !== '' ? '<tr><td height="4" style="height:4px;line-height:4px;font-size:0;background:' . esc_attr($accent) . '">&nbsp;</td></tr>' : '')
            . '<tr><td style="padding:32px 32px 16px">'
            . '<h1 style="margin:0 0 16px;font-size:24px;line-height:30px;font-weight:700;letter-spacing:-0.01em;color:' . $ink . '">' . esc_html($title) . '</h1>'
            . $content
            . '</td></tr></table></td></tr>'
            . '<tr><td style="padding:20px 4px 0;font-size:13px;line-height:20px;color:' . $soft . '">' . $footer . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    public static function get_replaceables($subscription_id, $recipient)
    {
        // No login token goes into the email: mail scanners prefetch links and mailboxes keep them. The link goes
        // to the website page on the storefront, which mints a short-lived token when the customer clicks login.
        $subscription_details_url = Commerce::require_adapter()->get_subscription_url($subscription_id);
        // The customer's website page where the cart has one (FluentCart's "My website" tab); else the subscription.
        $website_page_url = (string) apply_filters('waas_website_page_url', $subscription_details_url, $subscription_id) ?: $subscription_details_url;
        return apply_filters('waas_tenant_email_replaceables', [
            "tenant_domain" => "https://" . Commerce::require_adapter()->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META),
            "tenant_one_click_login_url" => $website_page_url,
            // Kept so custom templates that still use it don't show a raw placeholder.
            "tenant_one_click_login_url_expires_after" => '',
            "storefront_name" => get_bloginfo('name'), 
            "storefront_url" => get_site_url(),
            "storefront_subscription_details_url" => $subscription_details_url,
        ], $subscription_id, $recipient);
    }

    public static function do_replacements($text, $replaceables)
    {
        $filtered_body = $text;
        foreach ($replaceables as $key => $value)
        {
            $filtered_body = str_replace([sprintf('{{ %s }}', $key), sprintf('{{%s}}', $key)], $value, $filtered_body);
        }

        return $filtered_body;
    }
}

<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\Tenant;

class Notifications
{
    public static function init()
    {
        add_action('waas_tenant_ready', [__CLASS__, 'send_email_on_tenant_ready'], 10, 1);
        add_action('waas_tenant_suspend_scheduled', [__CLASS__, 'send_email_on_suspend_scheduled'], 10, 2);
        add_action('waas_tenant_suspended', [__CLASS__, 'send_email_on_suspended'], 10, 2);
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
        $headers = apply_filters('waas_suspended_email_headers', ['Content-Type: text/html; charset=UTF-8'], $subscription_id);

        wp_mail($recipient, $subject, $body, $headers);
    }

    public static function get_default_suspended_subject()
    {
        return __('Your website is offline', 'waas-host');
    }

    public static function get_default_suspended_body()
    {
        return '<!DOCTYPE HTML PUBLIC "-//W3C//DTD XHTML 1.0 Transitional //EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
        <html lang="en">
            <body>
                <p>Hello,</p>

                &nbsp;

                <p>Your subscription has ended, so your website {{ tenant_domain }} has been taken offline.</p>
                <p>We keep a backup of it until <strong>{{ delete_date }}</strong>; after that it is deleted. If you renew your subscription before then, your website comes back as it was.</p>

                &nbsp;

                <p><small>Your plan and billing are in your <a href="{{ storefront_subscription_details_url }}" target="_blank" rel="noopener">subscription details</a> at <a href="{{ storefront_url }}" target="_blank" rel="noopener">{{ storefront_name }}</a>.</small></p>

                &nbsp;

                <p>Regards,</p>
                <p>The <a href="{{ storefront_url }}" target="_blank" rel="noopener">{{ storefront_name }}</a> team</p>
            </body>
        </html>';
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
        $headers = apply_filters('waas_suspend_scheduled_email_headers', ['Content-Type: text/html; charset=UTF-8'], $subscription_id);

        wp_mail($recipient, $subject, $body, $headers);
    }

    public static function get_default_suspend_scheduled_subject()
    {
        return __('Your website stays online until {{ suspend_date }}', 'waas-host');
    }

    public static function get_default_suspend_scheduled_body()
    {
        return '<!DOCTYPE HTML PUBLIC "-//W3C//DTD XHTML 1.0 Transitional //EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
        <html lang="en">
            <body>
                <p>Hello,</p>

                &nbsp;

                <p>Your subscription is cancelled. Your website <a href="{{ tenant_domain }}" target="_blank" rel="noopener">{{ tenant_domain }}</a> stays online until <strong>{{ suspend_date }}</strong>, the end of the period you paid for.</p>
                <p>After that it is taken offline, and deleted {{ deletion_grace_days }} days later. If you change your mind before then, your website comes back as it was.</p>

                &nbsp;

                <p><a href="{{ tenant_one_click_login_url }}" target="_blank" rel="noopener"><strong>Manage your website</strong></a></p>
                <p><small>Your plan and billing are in your <a href="{{ storefront_subscription_details_url }}" target="_blank" rel="noopener">subscription details</a> at <a href="{{ storefront_url }}" target="_blank" rel="noopener">{{ storefront_name }}</a>.</small></p>

                &nbsp;

                <p>Regards,</p>
                <p>The <a href="{{ storefront_url }}" target="_blank" rel="noopener">{{ storefront_name }}</a> team</p>
            </body>
        </html>';
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
        $headers = apply_filters('waas_tenant_ready_email_headers', ['Content-Type: text/html; charset=UTF-8'], $subscription_id);
        $body = self::get_ready_email_body($subscription_id, $recipient);

        wp_mail($recipient, $subject, $body, $headers);
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
        return '<!DOCTYPE HTML PUBLIC "-//W3C//DTD XHTML 1.0 Transitional //EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
        <html lang="en">
            <body>
                <p>Hello,</p>

                &nbsp;

                <p>Good news! Your website is ready for you!</p>
                <p>Use the link below to login to your website or visit it at <a href="{{ tenant_domain }}" target="_blank" rel="noopener">{{ tenant_domain }}</a>.</p>

                &nbsp;

                <p><a href="{{ tenant_one_click_login_url }}" target="_blank" rel="noopener"><strong>Login now</strong></a></p>
                <p><small>This takes you to your website page at <a href="{{ storefront_url }}" target="_blank" rel="noopener">{{ storefront_name }}</a>, where the login button signs you in to your website. Your plan and billing are in your <a href="{{ storefront_subscription_details_url }}" target="_blank" rel="noopener">subscription details</a>.</small></p>

                &nbsp;

                <p>Regards,</p>
                <p>The <a href="{{ storefront_url }}" target="_blank" rel="noopener">{{ storefront_name }}</a> team</p>
            </body>
        </html>';
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

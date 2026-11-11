<?php

namespace AnyPressHost\Commerce\FluentCart;

use AnyPressHost\Features\Notifications;

/**
 * The care emails, edited on AnyPress > Settings > Emails like the website emails: "Care is on" when a package is
 * added to a website, and "Time running low" once a month when a website's care time drops to a quarter or less.
 */
class FluentCartCareEmails
{
    public const ON_SEND = 'anypress_care_on_email_send';
    public const ON_SUBJECT = 'anypress_care_on_email_subject';
    public const ON_BODY = 'anypress_care_on_email_body';
    public const LOW_SEND = 'anypress_care_low_email_send';
    public const LOW_SUBJECT = 'anypress_care_low_email_subject';
    public const LOW_BODY = 'anypress_care_low_email_body';
    /** Website subscription meta: the month ('Y-m') the "time running low" email went out. */
    public const LOW_SENT_META = '_anypress_care_low_sent';
    /** The share of the month's time left at which "time running low" goes out. */
    public const LOW_SHARE = 0.25;

    private FluentCartAdapter $adapter;
    private FluentCartStore $store;
    private FluentCartCare $care;

    public function __construct(FluentCartAdapter $adapter, FluentCartStore $store, FluentCartCare $care)
    {
        $this->adapter = $adapter;
        $this->store = $store;
        $this->care = $care;
    }

    public function register(): void
    {
        add_filter('anypress_customer_emails', [$this, 'add_emails']);
        add_action('anypress_care_added', [$this, 'send_on'], 10, 2);
        add_action('anypress_care_time_logged', [$this, 'maybe_send_low'], 10, 1);
    }

    /** The two emails in the Emails settings list. */
    public function add_emails(array $emails): array
    {
        $common = ['tenant_domain', 'tenant_one_click_login_url', 'storefront_name', 'storefront_url', 'storefront_subscription_details_url', 'care_package', 'care_time', 'care_answer'];
        $emails['care_on'] = [
            'title' => __('Care is on', 'anypress-host'),
            'when' => __('Sent when a care package is added to a website.', 'anypress-host'),
            'send' => self::ON_SEND,
            'subject' => self::ON_SUBJECT,
            'body' => self::ON_BODY,
            'default_subject' => [self::class, 'default_on_subject'],
            'default_body' => [self::class, 'default_on_body'],
            'placeholders' => $common,
        ];
        $emails['care_low'] = [
            'title' => __('Care time running low', 'anypress-host'),
            'when' => __('Sent once a month, when a website has a quarter or less of its care time left.', 'anypress-host'),
            'send' => self::LOW_SEND,
            'subject' => self::LOW_SUBJECT,
            'body' => self::LOW_BODY,
            'default_subject' => [self::class, 'default_low_subject'],
            'default_body' => [self::class, 'default_low_body'],
            'placeholders' => array_merge($common, ['care_left']),
        ];

        return $emails;
    }

    public static function default_on_subject(): string
    {
        return __('{{ care_package }} is on for your website', 'anypress-host');
    }

    public static function default_on_body(): string
    {
        return '<p>{{ care_package }} now looks after <a href="{{ tenant_domain }}">{{ tenant_domain }}</a>: {{ care_time }} of changes each month, and we answer within {{ care_answer }}.</p>
<p>To ask for a change, open your website page and use "Ask for a change", or reply to this email.</p>
<p><a class="anypress-button" href="{{ tenant_one_click_login_url }}">Ask for a change</a></p>
<p class="anypress-note">Unused time doesn\'t carry over to the next month. Your package and billing are in your <a href="{{ storefront_subscription_details_url }}">subscription details</a> at <a href="{{ storefront_url }}">{{ storefront_name }}</a>.</p>';
    }

    public static function default_low_subject(): string
    {
        return __('{{ care_left }} of care time left this month', 'anypress-host');
    }

    public static function default_low_body(): string
    {
        return '<p>Your website <a href="{{ tenant_domain }}">{{ tenant_domain }}</a> has {{ care_left }} of its {{ care_time }} care time left this month.</p>
<p>Your time starts again on the 1st. If you need more before then, you can add an hour of changes or move to a bigger package from your website page.</p>
<p><a class="anypress-button" href="{{ tenant_one_click_login_url }}">See your care time</a></p>
<p class="anypress-note">Your package and billing are in your <a href="{{ storefront_subscription_details_url }}">subscription details</a> at <a href="{{ storefront_url }}">{{ storefront_name }}</a>.</p>';
    }

    /** anypress_care_added: the care package $care_id now looks after the website $website_id. */
    public function send_on($care_id, $website_id): void
    {
        $this->send('on', (int) $website_id);
    }

    /** anypress_care_time_logged: tells the customer once a month when a quarter or less of the time is left. */
    public function maybe_send_low($website_id): void
    {
        $summary = $this->care->summary($website_id);
        if ($summary === null || $summary['package'] === '' || $summary['minutes'] <= 0 || $summary['left'] > $summary['minutes'] * self::LOW_SHARE) {
            return;
        }
        if ($this->adapter->get_subscription_meta($website_id, self::LOW_SENT_META) === FluentCartCare::month()) {
            return;
        }
        if ($this->send('low', (int) $website_id)) {
            $this->store->update_subscription_meta($website_id, self::LOW_SENT_META, FluentCartCare::month());
        }
    }

    private function send(string $which, int $website_id): bool
    {
        $on = $which === 'on';
        if (get_option($on ? self::ON_SEND : self::LOW_SEND, 'on') !== 'on') {
            return false;
        }
        $summary = $this->care->summary($website_id);
        $recipient = $this->adapter->get_customer_email($website_id);
        if ($summary === null || $summary['package'] === '' || !is_email($recipient)) {
            return false;
        }

        $email = Notifications::render_email(
            (string) get_option($on ? self::ON_SUBJECT : self::LOW_SUBJECT) ?: ($on ? self::default_on_subject() : self::default_low_subject()),
            (string) get_option($on ? self::ON_BODY : self::LOW_BODY) ?: ($on ? self::default_on_body() : self::default_low_body()),
            Notifications::get_replaceables($website_id, $recipient) + self::replaceables($summary)
        );

        return (bool) wp_mail($recipient, $email['subject'], $email['body'], Notifications::headers());
    }

    /** @param array{package: string, answer: string, minutes: int, left: int} $summary */
    public static function replaceables(array $summary): array
    {
        return [
            'care_package' => $summary['package'],
            'care_time' => FluentCartCare::format_minutes($summary['minutes']),
            'care_left' => FluentCartCare::format_minutes($summary['left']),
            'care_answer' => $summary['answer'],
        ];
    }
}

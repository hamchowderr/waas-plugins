<?php

namespace AnyPressHost\Commerce\FluentCart;

use AnyPressHost\Features\Notifications;

/**
 * The care emails, edited on AnyPress > Settings > Emails like the website emails: "Care is on" when a package is
 * added to a website, "Time running low" once a month when a website's care time drops to a quarter or less, and the
 * monthly check-in report early each month: last month's work and time, this month's time, and the call link.
 */
class FluentCartCareEmails
{
    public const ON_SEND = 'anypress_care_on_email_send';
    public const ON_SUBJECT = 'anypress_care_on_email_subject';
    public const ON_BODY = 'anypress_care_on_email_body';
    public const LOW_SEND = 'anypress_care_low_email_send';
    public const LOW_SUBJECT = 'anypress_care_low_email_subject';
    public const LOW_BODY = 'anypress_care_low_email_body';
    public const REPORT_SEND = 'anypress_care_report_email_send';
    public const REPORT_SUBJECT = 'anypress_care_report_email_subject';
    public const REPORT_BODY = 'anypress_care_report_email_body';
    /** Website subscription meta: the month ('Y-m') the last monthly report covered. */
    public const REPORT_SENT_META = '_anypress_care_report_sent';
    /** Daily: sends the reports that are due. */
    public const REPORT_HOOK = 'anypress_care_monthly_reports';
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
        add_action(self::REPORT_HOOK, [$this, 'run_reports']);
        add_action('init', [$this, 'schedule']);
    }

    /** Checks daily for reports to send; one wp-cron event, made once. */
    public function schedule(): void
    {
        if (!wp_next_scheduled(self::REPORT_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::REPORT_HOOK);
        }
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
        $emails['care_report'] = [
            'title' => __('Care monthly report', 'anypress-host'),
            'when' => __('Sent early each month to each website with care: what was done last month, the time used, and this month\'s time.', 'anypress-host'),
            'send' => self::REPORT_SEND,
            'subject' => self::REPORT_SUBJECT,
            'body' => self::REPORT_BODY,
            'default_subject' => [self::class, 'default_report_subject'],
            'default_body' => [self::class, 'default_report_body'],
            'placeholders' => array_merge($common, ['care_month', 'care_used', 'care_report', 'care_call']),
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

    public static function default_report_subject(): string
    {
        return __('Your {{ care_month }} care report', 'anypress-host');
    }

    public static function default_report_body(): string
    {
        return '<p>Here is what we did on <a href="{{ tenant_domain }}">{{ tenant_domain }}</a> in {{ care_month }}: {{ care_used }} of your {{ care_time }}.</p>
{{ care_report }}
<p>Your {{ care_time }} for this month is ready. Tell us what you\'d like next, or reply to this email.</p>
<p><a class="anypress-button" href="{{ tenant_one_click_login_url }}">Ask for a change</a></p>
{{ care_call }}
<p class="anypress-note">Your {{ care_package }} package and billing are in your <a href="{{ storefront_subscription_details_url }}">subscription details</a> at <a href="{{ storefront_url }}">{{ storefront_name }}</a>.</p>';
    }

    /** Last month in the site's time zone, e.g. 2026-10. */
    public static function last_month(): string
    {
        return (new \DateTimeImmutable('first day of this month', wp_timezone()))->modify('-1 month')->format('Y-m');
    }

    /** REPORT_HOOK. */
    public function run_reports(): void
    {
        $this->send_reports();
    }

    /** Last month's report for each website that had care all of last month and hasn't had it yet. Returns how many went out. */
    public function send_reports(): int
    {
        $month = self::last_month();
        $sent = 0;
        foreach ($this->care->websites_with_care() as $website_id) {
            $since = (string) $this->adapter->get_subscription_meta($website_id, FluentCartCare::SINCE_META);
            if ($since === '' || $since > $month || $this->adapter->get_subscription_meta($website_id, self::REPORT_SENT_META) === $month) {
                continue;
            }
            // Marked first: a failed send is not retried every day.
            $this->store->update_subscription_meta($website_id, self::REPORT_SENT_META, $month);
            $sent += $this->send('report', $website_id) ? 1 : 0;
        }

        return $sent;
    }

    /** The report's own placeholders: the month, its time used and a list of what was done. */
    private function report_replaceables(int $website_id, array $summary): array
    {
        $month = self::last_month();
        $entries = $this->care->month_log($website_id, $month);
        $used = array_sum(array_column($entries, 'minutes'));
        $items = '';
        foreach (array_reverse($entries) as $entry) {
            $items .= '<li>' . esc_html(($entry['note'] !== '' ? $entry['note'] : __('Changes', 'anypress-host')) . ' (' . FluentCartCare::format_minutes((int) $entry['minutes']) . ')') . '</li>';
        }

        return [
            'care_month' => wp_date('F', (int) strtotime($month . '-01 12:00')),
            'care_used' => FluentCartCare::format_minutes($used),
            'care_report' => $items !== '' ? '<ul>' . $items . '</ul>' : '<p>' . esc_html__('Nothing was asked for last month.', 'anypress-host') . '</p>',
            'care_call' => $summary['call_url'] !== '' ? '<p><a class="anypress-button" href="' . esc_url($summary['call_url']) . '">' . esc_html__('Book this month\'s call', 'anypress-host') . '</a></p>' : '',
        ];
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
        $options = [
            'on' => [self::ON_SEND, self::ON_SUBJECT, self::ON_BODY, [self::class, 'default_on_subject'], [self::class, 'default_on_body']],
            'low' => [self::LOW_SEND, self::LOW_SUBJECT, self::LOW_BODY, [self::class, 'default_low_subject'], [self::class, 'default_low_body']],
            'report' => [self::REPORT_SEND, self::REPORT_SUBJECT, self::REPORT_BODY, [self::class, 'default_report_subject'], [self::class, 'default_report_body']],
        ][$which];
        if (get_option($options[0], 'on') !== 'on') {
            return false;
        }
        $summary = $this->care->summary($website_id);
        $recipient = $this->adapter->get_customer_email($website_id);
        if ($summary === null || $summary['package'] === '' || !is_email($recipient)) {
            return false;
        }

        $replaceables = Notifications::get_replaceables($website_id, $recipient) + self::replaceables($summary);
        if ($which === 'report') {
            $replaceables += $this->report_replaceables($website_id, $summary);
        }
        $email = Notifications::render_email(
            (string) get_option($options[1]) ?: call_user_func($options[3]),
            (string) get_option($options[2]) ?: call_user_func($options[4]),
            $replaceables
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

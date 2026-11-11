<?php

namespace AnyPressHost\Commerce\FluentCart;

use AnyPressHost\Api\SupportDesk;
use AnyPressHost\Core\Tenant;

/**
 * Care packages and one-time services the shop sells for its customers' websites.
 *
 * A care package is a FluentCart subscription product (monthly or yearly) whose AnyPress feed has "Product type: Care
 * package", with the minutes of changes it includes each month and how soon requests are answered. A one-time service
 * ("Product type: One-time service") is a single job for one website, such as setting it up, and can add extra time.
 *
 * Each care subscription belongs to one website: the website's subscription id is kept in the care subscription's meta.
 * The account's website card links to FluentCart's instant checkout with ?anypress_website=<id>, which
 * FluentCartEmailPacks keeps on the order, as for an email pack. A package bought without a website (from a plan's
 * product page, before the website exists) goes to the customer's only website without care, or waits until they pick
 * the website on their account. When a website's plan ends, its care subscription is cancelled with it.
 *
 * Time: the shop's team logs the minutes each request took (AnyPress > Care). The monthly time starts again on the 1st
 * (the site's time zone) and doesn't carry over; extra time from a service never expires and is used after it.
 * Requests are tickets in the shop's Fluent Support (SupportDesk), with the package and time left on each one.
 */
class FluentCartCare
{
    /** Care subscription meta: the website's subscription id. */
    public const WEBSITE_META = '_anypress_care_website';
    /**
     * Website subscription meta: ['month' => 'Y-m', 'minutes' => logged that month]. Kept on the website, not the care
     * subscription, so cancelling and buying care again in the same month doesn't give the month's time again.
     */
    public const USED_META = '_anypress_care_used';
    /** Website subscription meta: the month ('Y-m') its current care package was added. */
    public const SINCE_META = '_anypress_care_since';
    /** Website subscription meta: the time log, newest first: [['at' => ISO 8601, 'minutes', 'note', 'by'], ...]. */
    public const LOG_META = '_anypress_care_log';
    /** Log entries kept per website. */
    public const LOG_KEEP = 50;
    /** Website subscription meta: extra minutes left, from one-time services. */
    public const EXTRA_META = '_anypress_care_extra_minutes';
    /** Order meta: set once the order's care and services were handled. */
    public const ORDER_DONE_META = '_anypress_care_done';
    /** The most minutes one log entry may add. */
    public const MAX_LOG_MINUTES = 600;

    private FluentCartAdapter $adapter;
    private FluentCartStore $store;
    private FluentCartEmailPacks $orders;

    public function __construct(FluentCartAdapter $adapter, FluentCartStore $store, FluentCartEmailPacks $orders)
    {
        $this->adapter = $adapter;
        $this->store = $store;
        $this->orders = $orders;
    }

    public function register(): void
    {
        // After FluentCartEmailPacks (20), which shares the order's chosen website.
        add_action('fluent_cart/order_paid_done', [$this, 'order_paid'], 25, 1);
        add_action('anypress_subscription_expired', [$this, 'website_ended'], 20, 1);
    }

    // ---- What the shop sells ----

    /** @return array<int, array> the published care packages (FluentCartFeedQueries::get_typed_products), in menu order */
    public function packages(): array
    {
        return $this->store->get_typed_products(AnyPressProvisioningIntegration::PRODUCT_TYPE_CARE);
    }

    /** @return array<int, array> the published one-time services, in menu order */
    public function services(): array
    {
        return $this->store->get_typed_products(AnyPressProvisioningIntegration::PRODUCT_TYPE_SERVICE);
    }

    /** The care feed of a subscription's product, or null when the subscription is not a care package. */
    public function package_feed($subscription_id): ?array
    {
        $product_id = $this->store->get_subscription_product_id($subscription_id);

        return $product_id > 0 ? $this->store->get_feed_of_type($product_id, AnyPressProvisioningIntegration::PRODUCT_TYPE_CARE) : null;
    }

    // ---- A website's care ----

    /** The website's active care subscription, 0 when it has none. */
    public function care_of($website_id): int
    {
        foreach ($this->store->find_subscription_ids_with_meta(self::WEBSITE_META, (string) (int) $website_id) as $care_id) {
            if ($this->adapter->is_subscription_active($care_id)) {
                return (int) $care_id;
            }
        }

        return 0;
    }

    /**
     * The website's care now: its package, this month's time and extra time. Null when it has neither a package nor
     * extra time.
     *
     * @return array{subscription_id: int, package: string, answer: string, priority: string, call_url: string, minutes: int, used: int, left: int, extra: int}|null
     */
    public function summary($website_id): ?array
    {
        $care_id = $this->care_of($website_id);
        $feed = $care_id ? $this->package_feed($care_id) : null;
        $extra = $this->extra_minutes($website_id);
        if ($feed === null && $extra <= 0) {
            return null;
        }

        $minutes = $feed !== null ? AnyPressProvisioningIntegration::minutes($feed, 'care_minutes') : 0;
        $used = $feed !== null ? $this->used_this_month((int) $website_id) : 0;

        return [
            'subscription_id' => $feed !== null ? $care_id : 0,
            'package' => $feed !== null ? (string) get_the_title($this->store->get_subscription_product_id($care_id)) : '',
            'answer' => $feed !== null ? sanitize_text_field((string) ($feed['care_answer'] ?? '')) : '',
            'priority' => $feed !== null ? AnyPressProvisioningIntegration::priority($feed) : AnyPressProvisioningIntegration::PRIORITY_NORMAL,
            'call_url' => $feed !== null && !empty($feed['care_call_url']) ? esc_url_raw((string) $feed['care_call_url']) : '',
            'minutes' => $minutes,
            'used' => $used,
            'left' => max(0, $minutes - $used),
            'extra' => $extra,
        ];
    }

    public function extra_minutes($website_id): int
    {
        return max(0, (int) $this->adapter->get_subscription_meta($website_id, self::EXTRA_META));
    }

    private function used_this_month(int $website_id): int
    {
        $used = $this->adapter->get_subscription_meta($website_id, self::USED_META);

        return is_array($used) && ($used['month'] ?? '') === self::month() ? max(0, (int) ($used['minutes'] ?? 0)) : 0;
    }

    /**
     * The website's logged time, newest first.
     *
     * @return array<int, array{at: string, minutes: int, note: string, by: string}>
     */
    public function history($website_id, int $limit = self::LOG_KEEP): array
    {
        $log = $this->adapter->get_subscription_meta($website_id, self::LOG_META);
        $entries = [];
        foreach (is_array($log) ? $log : [] as $entry) {
            if (is_array($entry) && (int) ($entry['minutes'] ?? 0) > 0) {
                $entries[] = [
                    'at' => (string) ($entry['at'] ?? ''),
                    'minutes' => (int) $entry['minutes'],
                    'note' => (string) ($entry['note'] ?? ''),
                    'by' => (string) ($entry['by'] ?? ''),
                ];
            }
        }

        return array_slice($entries, 0, max(0, $limit));
    }

    /** @return int[] websites with an active care package */
    public function websites_with_care(): array
    {
        $websites = [];
        foreach ($this->store->find_subscription_meta(self::WEBSITE_META, []) as $care_id => $website_id) {
            if ($this->adapter->is_subscription_active($care_id)) {
                $websites[] = (int) $website_id;
            }
        }

        return array_values(array_unique($websites));
    }

    /**
     * The log entries of one month ('Y-m', the site's time zone), newest first.
     *
     * @return array<int, array{at: string, minutes: int, note: string, by: string}>
     */
    public function month_log($website_id, string $month): array
    {
        return array_values(array_filter(
            $this->history($website_id),
            fn ($entry) => $entry['at'] !== '' && wp_date('Y-m', (int) strtotime($entry['at'])) === $month
        ));
    }

    /** This month in the site's time zone, e.g. 2026-11. */
    public static function month(): string
    {
        return wp_date('Y-m');
    }

    /**
     * Logs time spent on the website's requests: this month's time first, then extra time; anything beyond both is
     * logged as over. Returns the minutes taken from each.
     *
     * @return array{monthly: int, extra: int, over: int}
     */
    public function log_time($website_id, int $minutes, string $note): array
    {
        $minutes = max(0, min($minutes, self::MAX_LOG_MINUTES));
        $summary = $this->summary($website_id);
        $care_id = $summary['subscription_id'] ?? 0;
        $monthly = $summary ? min($minutes, $summary['left']) : 0;
        $extra = $summary ? min($minutes - $monthly, $summary['extra']) : 0;
        $over = $minutes - $monthly - $extra;

        if ($minutes <= 0) {
            return ['monthly' => 0, 'extra' => 0, 'over' => 0];
        }
        $this->store->update_subscription_meta($website_id, self::USED_META, ['month' => self::month(), 'minutes' => $this->used_this_month((int) $website_id) + $minutes]);
        $user = wp_get_current_user();
        $entry = ['at' => gmdate('c'), 'minutes' => $minutes, 'note' => $note, 'by' => $user ? (string) $user->display_name : ''];
        $this->store->update_subscription_meta($website_id, self::LOG_META, array_slice(array_merge([$entry], $this->history($website_id)), 0, self::LOG_KEEP));
        if ($extra > 0) {
            $this->store->update_subscription_meta($website_id, self::EXTRA_META, $summary['extra'] - $extra);
        }

        $detail = sprintf('%s logged%s.', self::format_minutes($minutes), $note !== '' ? ': ' . $note : '');
        if ($over > 0) {
            $detail .= sprintf(' %s over the time left; bill it as extra time.', self::format_minutes($over));
        }
        $this->store->add_subscription_note($care_id > 0 ? $care_id : $website_id, __('Care time logged', 'anypress-host'), $detail);

        do_action('anypress_care_time_logged', (int) $website_id);

        return ['monthly' => $monthly, 'extra' => $extra, 'over' => $over];
    }

    /** "30 min", "2 h", "1 h 20 min". */
    public static function format_minutes(int $minutes): string
    {
        $hours = intdiv(max(0, $minutes), 60);
        $rest = max(0, $minutes) % 60;
        if ($hours === 0) {
            /* translators: %d: minutes */
            return sprintf(__('%d min', 'anypress-host'), $rest);
        }

        return $rest === 0
            /* translators: %d: hours */
            ? sprintf(__('%d h', 'anypress-host'), $hours)
            /* translators: 1: hours, 2: minutes */
            : sprintf(__('%1$d h %2$d min', 'anypress-host'), $hours, $rest);
    }

    // ---- Whose care is whose ----

    /** A website that can have care: it has a store that isn't deleted, and its plan is active. */
    public function is_live_website($subscription_id): bool
    {
        return $this->orders->can_buy_emails($subscription_id) && $this->adapter->is_subscription_active($subscription_id);
    }

    /** True when one of the user's live websites has an active care package. */
    public function has_care_package(int $user_id): bool
    {
        foreach ($this->adapter->get_customer_subscription_ids($user_id) as $id) {
            if ($this->is_live_website((int) $id) && $this->care_of((int) $id) > 0) {
                return true;
            }
        }

        return false;
    }

    /** @return int[] the user's active care subscriptions that have no website yet */
    public function waiting_for_user(int $user_id): array
    {
        return array_values(array_filter(
            $this->adapter->get_customer_subscription_ids($user_id),
            fn ($id) => $this->is_waiting((int) $id)
        ));
    }

    private function is_waiting(int $care_id): bool
    {
        return !$this->adapter->get_subscription_meta($care_id, self::WEBSITE_META)
            && $this->adapter->is_subscription_active($care_id)
            && $this->package_feed($care_id) !== null;
    }

    /** Gives a waiting care package of the user's to one of the user's websites without care. */
    public function attach_for_user(int $care_id, int $website_id, int $user_id): bool
    {
        $own = array_map('intval', $this->adapter->get_customer_subscription_ids($user_id));
        if (
            !in_array($care_id, $own, true) || !in_array($website_id, $own, true) || !$this->is_waiting($care_id)
            || !$this->is_live_website($website_id) || $this->care_of($website_id) > 0
        ) {
            return false;
        }

        $this->attach($care_id, $website_id);

        return true;
    }

    private function attach(int $care_id, int $website_id): void
    {
        $this->store->update_subscription_meta($care_id, self::WEBSITE_META, $website_id);
        $this->store->add_subscription_note($care_id, __('Care added to a website', 'anypress-host'), sprintf('For %s (subscription #%d).', $this->domain($website_id), $website_id));
        $this->store->update_subscription_meta($website_id, self::SINCE_META, self::month());
        do_action('anypress_care_added', $care_id, $website_id);
    }

    public function domain(int $website_id): string
    {
        return (string) ($this->adapter->get_subscription_meta($website_id, Tenant::DOMAIN_NAME_META)
            ?: $this->adapter->get_subscription_meta($website_id, Tenant::BASE_DOMAIN_NAME_META)
            ?: __('your website', 'anypress-host'));
    }

    // ---- Orders and the website's plan ----

    /** @param array $data order, transaction, customer[, subscription] */
    public function order_paid($data): void
    {
        $order = is_array($data) ? ($data['order'] ?? null) : null;
        if (!$order || $this->store->get_order_meta_value($order, self::ORDER_DONE_META)) {
            return;
        }

        $handled = false;
        foreach ($this->store->get_order_lines($order) as $line) {
            if ($this->store->get_feed_of_type($line['product_id'], AnyPressProvisioningIntegration::PRODUCT_TYPE_CARE) !== null) {
                $handled = $this->package_paid($order) || $handled;
                continue;
            }
            $service = $this->store->get_feed_of_type($line['product_id'], AnyPressProvisioningIntegration::PRODUCT_TYPE_SERVICE);
            if ($service !== null) {
                $this->service_paid($order, $line, $service);
                $handled = true;
            }
        }

        if ($handled) {
            $this->store->update_order_meta($order, self::ORDER_DONE_META, time());
        }
    }

    /** A paid care package (first order or renewal): gives it its website, unless it has one. */
    private function package_paid($order): bool
    {
        $subscription = $this->store->find_subscription_for_order($order);
        if (!$subscription) {
            return false;
        }
        $care_id = (int) $subscription->id;
        if ($this->adapter->get_subscription_meta($care_id, self::WEBSITE_META)) {
            return true;
        }

        $website_id = $this->website_for($order, true);
        if ($website_id > 0) {
            $this->attach($care_id, $website_id);
        } else {
            $order->addLog(__('Care waiting for a website', 'anypress-host'), 'The customer picks the website on their account\'s Website page.', 'info', 'AnyPress');
        }

        return true;
    }

    /** A paid one-time service: adds its extra time to the website and files the job in Fluent Support. */
    private function service_paid($order, array $line, array $service): void
    {
        $website_id = $this->website_for($order, false);
        $minutes = AnyPressProvisioningIntegration::minutes($service, 'service_minutes') * max(1, (int) $line['quantity']);
        if ($website_id > 0 && $minutes > 0) {
            $this->store->update_subscription_meta($website_id, self::EXTRA_META, $this->extra_minutes($website_id) + $minutes);
            $this->store->add_subscription_note($website_id, __('Extra care time added', 'anypress-host'), sprintf('%s from order #%d.', self::format_minutes($minutes), (int) $order->id));
            do_action('anypress_care_extra_added', $website_id);
        }

        $customer = $this->store->get_order_customer($order);
        $title = (string) get_the_title($line['product_id']);
        $domain = $website_id > 0 ? $this->domain($website_id) : __('my website', 'anypress-host');
        $ticket = $customer ? SupportDesk::file_ticket(
            $customer['email'],
            (string) ($customer['name'] ?? ''),
            /* translators: 1: service, 2: website address */
            sprintf(__('%1$s: %2$s', 'anypress-host'), $title, $domain),
            SupportDesk::message(sprintf(
                /* translators: 1: service, 2: website address, 3: order number */
                __('I ordered %1$s for %2$s (order #%3$d).', 'anypress-host'),
                $title,
                $domain,
                (int) $order->id
            )),
            ['tag' => $title]
        ) : null;

        $order->addLog(
            $ticket ? __('Service request filed', 'anypress-host') : __('Service request not filed', 'anypress-host'),
            $ticket
                ? sprintf('%s for %s is ticket #%d in Fluent Support.', $title, $domain, (int) $ticket->id)
                : sprintf('Fluent Support did not take a ticket for %s; contact the customer about %s.', $domain, $title),
            $ticket ? 'info' : 'error',
            'AnyPress'
        );
    }

    /**
     * The website an order is for: the one chosen on the account page (checked to be the customer's), else the
     * customer's only live website (for a package: the only one without care). 0 when there is none to pick.
     */
    private function website_for($order, bool $package): int
    {
        if ((int) $this->store->get_order_meta($order, FluentCartEmailPacks::ORDER_WEBSITE_META) > 0) {
            $chosen = $this->orders->resolve_website($order);
            if (is_string($chosen)) {
                $order->addLog(__('Website not used', 'anypress-host'), $chosen, 'error', 'AnyPress');
                return 0;
            }
            return ($package && $this->care_of($chosen[0]) > 0) ? 0 : $chosen[0];
        }

        $candidates = array_values(array_filter(
            $this->orders->customer_subscription_ids($order),
            fn ($id) => $this->is_live_website($id) && (!$package || $this->care_of($id) === 0)
        ));

        return count($candidates) === 1 ? (int) $candidates[0] : 0;
    }

    /** anypress_subscription_expired: a website's plan ended, so its care stops too. */
    public function website_ended($website_id): void
    {
        $care_id = $this->care_of($website_id);
        if ($care_id > 0 && $this->store->cancel_subscription($care_id, 'The website\'s plan ended.')) {
            $this->store->add_subscription_note($care_id, __('Care cancelled', 'anypress-host'), sprintf('The plan of %s (subscription #%d) ended.', $this->domain((int) $website_id), (int) $website_id));
        }
    }
}

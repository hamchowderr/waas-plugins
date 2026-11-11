<?php

namespace AnyPressClient\Crm;

/**
 * MailPoet subscribers and lists, read through MailPoet's own API (\MailPoet\API\API::MP('v1'), so MailPoet has
 * to be active); nothing in MailPoet is changed.
 *
 * Statuses come across as they are: subscribed stays subscribed, unconfirmed becomes pending (no confirmation
 * email is sent), unsubscribed and bounced stay that way, and inactive (MailPoet stopped sending to them for lack of
 * engagement) becomes unsubscribed, since FluentCRM has no inactive status and they must not start getting email
 * again. Trashed subscribers are left out.
 *
 * MailPoet's own lists (type "default") become FluentCRM lists of the same name, with each subscriber who is on
 * the list (not unsubscribed from it). Its automatic lists (WordPress Users, WooCommerce Customers) and segments
 * are worked out from other data, so they are reported and not copied.
 */
class MailPoetSubscribers
{
    public const SOURCE = 'MailPoet';
    private const PAGE = 500;
    private const STATUSES = [
        'subscribed' => 'subscribed',
        'unconfirmed' => 'pending',
        'unsubscribed' => 'unsubscribed',
        'bounced' => 'bounced',
        'inactive' => 'unsubscribed',
    ];

    /** MailPoet's subscriber status as a FluentCRM status. */
    public static function status(string $status): string
    {
        return self::STATUSES[strtolower(trim($status))] ?? 'pending';
    }

    /** Whether MailPoet is installed on this site (it may be switched off now). */
    public static function present(): bool
    {
        if (defined('MAILPOET_VERSION')) {
            return true;
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return array_key_exists('mailpoet/mailpoet.php', get_plugins());
    }

    /** Whether MailPoet's API can be used now. */
    public static function active(): bool
    {
        return class_exists('MailPoet\API\API');
    }

    /** @return array{records: array, stats: array} */
    public static function collect(): array
    {
        $api = \MailPoet\API\API::MP('v1');
        $subscribers = [];
        $memberships = [];
        $trashed = false;
        for ($offset = 0;; $offset += self::PAGE) {
            $page = (array) $api->getSubscribers([], self::PAGE, $offset);
            foreach ($page as $subscriber) {
                $trashed = $trashed || !empty($subscriber['deleted_at']);
                $subscribers[] = $subscriber;
                foreach ((array) ($subscriber['subscriptions'] ?? []) as $subscription) {
                    $memberships[] = ['subscriber_id' => $subscriber['id'], 'segment_id' => $subscription['segment_id'], 'status' => $subscription['status']];
                }
            }
            if (count($page) < self::PAGE) {
                break;
            }
        }
        // MailPoet's API lists subscribers without their IP addresses, and has no other way to give them, so those two
        // columns alone are read from its table (and never changed).
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ips = (array) $wpdb->get_results($wpdb->prepare('SELECT id, subscribed_ip, confirmed_ip FROM %i', $wpdb->prefix . 'mailpoet_subscribers'), OBJECT_K);
        foreach ($subscribers as &$subscriber) {
            $row = $ips[(int) $subscriber['id']] ?? null;
            $subscriber['subscribed_ip'] = $row ? (string) $row->subscribed_ip : '';
            $subscriber['confirmed_ip'] = $row ? (string) $row->confirmed_ip : '';
        }
        unset($subscriber);
        // The default listing leaves trashed subscribers out: count them for the report.
        $in_trash = $trashed ? 0 : (int) $api->getSubscribersCount(['status' => 'trash']);
        $subscribers = array_merge($subscribers, array_fill(0, $in_trash, ['id' => 0, 'deleted_at' => 'trash']));
        $segments = array_map(fn ($list) => ['id' => $list['id'], 'name' => $list['name'], 'type' => 'default', 'deleted_at' => $list['deleted_at'] ?? null], (array) $api->getLists());
        // MailPoet's automatic lists aren't in its API; they are worked out from other data and never copied.
        $segments[] = ['id' => 0, 'name' => 'WordPress Users', 'type' => 'wp_users', 'deleted_at' => null];
        if (class_exists('WooCommerce')) {
            $segments[] = ['id' => 0, 'name' => 'WooCommerce Customers', 'type' => 'woocommerce_users', 'deleted_at' => null];
        }

        return self::from_rows($subscribers, $segments, $memberships, defined('MAILPOET_VERSION') ? MAILPOET_VERSION : null);
    }

    /**
     * @param array $subscribers Rows of mailpoet_subscribers.
     * @param array $segments Rows of mailpoet_segments.
     * @param array $memberships Rows of mailpoet_subscriber_segment.
     */
    public static function from_rows(array $subscribers, array $segments, array $memberships, ?string $version = null): array
    {
        $lists = [];
        $not_copied = [];
        foreach ($segments as $segment) {
            if (!empty($segment['deleted_at'])) {
                continue;
            }
            if (($segment['type'] ?? 'default') === 'default') {
                $lists[(int) $segment['id']] = trim((string) $segment['name']);
            } else {
                $not_copied[] = sprintf('%s (%s)', $segment['name'], $segment['type']);
            }
        }
        $on = [];
        foreach ($memberships as $membership) {
            $list = (int) $membership['segment_id'];
            if (isset($lists[$list]) && ($membership['status'] ?? 'subscribed') === 'subscribed') {
                $on[(int) $membership['subscriber_id']][] = $lists[$list];
            }
        }

        $records = [];
        $stats = ['version' => $version, 'subscribers' => 0, 'by_status' => [], 'trashed' => 0, 'lists' => [], 'not_copied' => $not_copied];
        foreach ($subscribers as $row) {
            if (!empty($row['deleted_at'])) {
                $stats['trashed']++;
                continue;
            }
            $status = self::status((string) ($row['status'] ?? ''));
            $records[] = [
                'email' => (string) $row['email'],
                'source' => self::SOURCE,
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'ip' => (string) (($row['confirmed_ip'] ?? '') ?: ($row['subscribed_ip'] ?? '')),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'status' => $status,
                'tags' => [],
                'lists' => $on[(int) $row['id']] ?? [],
            ];
            $stats['subscribers']++;
            $from = strtolower((string) ($row['status'] ?? ''));
            $stats['by_status'][$from] = ($stats['by_status'][$from] ?? 0) + 1;
            foreach ($on[(int) $row['id']] ?? [] as $list) {
                $stats['lists'][$list] = ($stats['lists'][$list] ?? 0) + 1;
            }
        }
        foreach ($lists as $list) {
            $stats['lists'][$list] = $stats['lists'][$list] ?? 0;
        }
        ksort($stats['by_status']);

        return ['records' => $records, 'stats' => $stats];
    }
}

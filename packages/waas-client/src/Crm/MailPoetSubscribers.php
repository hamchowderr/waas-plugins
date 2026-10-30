<?php

namespace WaaSClient\Crm;

/**
 * MailPoet subscribers and lists, read straight from MailPoet's tables (MailPoet doesn't need to be active, and
 * nothing in them is changed).
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

    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . 'mailpoet_' . $name;
    }

    /** Whether this site has MailPoet's tables. */
    public static function present(): bool
    {
        global $wpdb;
        $table = self::table('subscribers');

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /** @return array{records: array, stats: array} */
    public static function collect(): array
    {
        global $wpdb;
        $subscribers = (array) $wpdb->get_results('SELECT id, email, first_name, last_name, status, subscribed_ip, confirmed_ip, created_at, deleted_at FROM ' . self::table('subscribers') . ' ORDER BY id', ARRAY_A);
        $segments = (array) $wpdb->get_results('SELECT id, name, type, deleted_at FROM ' . self::table('segments') . ' ORDER BY id', ARRAY_A);
        $memberships = (array) $wpdb->get_results('SELECT subscriber_id, segment_id, status FROM ' . self::table('subscriber_segment'), ARRAY_A);

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

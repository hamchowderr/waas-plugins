<?php

namespace AnyPressClient\Crm;

/**
 * Subscribers of the Newsletter plugin (thenewsletterplugin.com), read from its table (wp_newsletter) and its list
 * names (the newsletter_lists option). Nothing in them is changed, and the plugin doesn't need to be active.
 *
 * Statuses: confirmed becomes subscribed, not confirmed becomes pending (no confirmation email is sent),
 * unsubscribed, bounced and complained stay that way. Temporary rows (a sign-up that was never finished) are left
 * out. Each named list becomes a FluentCRM list of the same name, with the subscribers who are on it.
 */
class NewsletterSubscribers
{
    public const SOURCE = 'Newsletter';
    private const STATUSES = ['C' => 'subscribed', 'S' => 'pending', 'U' => 'unsubscribed', 'B' => 'bounced', 'P' => 'complained'];
    private const LIST_MAX = 40;

    public static function status(string $status): ?string
    {
        return self::STATUSES[strtoupper(trim($status))] ?? null;
    }

    public static function table(): string
    {
        global $wpdb;

        return defined('NEWSLETTER_USERS_TABLE') ? (string) NEWSLETTER_USERS_TABLE : $wpdb->prefix . 'newsletter';
    }

    /** Whether the Newsletter plugin was set up here: it records its version when it makes its table. */
    public static function present(): bool
    {
        return get_option('newsletter_version') !== false;
    }

    /** @return array{records: array, stats: array} */
    public static function collect(): array
    {
        global $wpdb;
        // The Newsletter plugin has no function that lists its subscribers (only one at a time by id or email), so
        // its own table is read, and never changed.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id', self::table()), ARRAY_A);
        $options = get_option('newsletter_lists');

        return self::from_rows($rows, is_array($options) ? $options : [], defined('NEWSLETTER_VERSION') ? NEWSLETTER_VERSION : null);
    }

    /**
     * @param array $rows Rows of wp_newsletter.
     * @param array $options The newsletter_lists option (list_N => name).
     */
    public static function from_rows(array $rows, array $options, ?string $version = null): array
    {
        $lists = [];
        for ($i = 1; $i <= self::LIST_MAX; $i++) {
            $name = trim((string) ($options['list_' . $i] ?? ''));
            if ($name !== '') {
                $lists[$i] = $name;
            }
        }

        $records = [];
        $stats = ['version' => $version, 'subscribers' => 0, 'by_status' => [], 'skipped' => 0, 'lists' => array_fill_keys(array_values($lists), 0)];
        foreach ($rows as $row) {
            $status = self::status((string) ($row['status'] ?? ''));
            if ($status === null) {
                $stats['skipped']++;
                continue;
            }
            $on = [];
            foreach ($lists as $i => $name) {
                if (!empty($row['list_' . $i])) {
                    $on[] = $name;
                    $stats['lists'][$name]++;
                }
            }
            $records[] = [
                'email' => (string) ($row['email'] ?? ''),
                'source' => self::SOURCE,
                'first_name' => (string) ($row['name'] ?? ''),
                'last_name' => (string) ($row['surname'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
                'ip' => (string) ($row['ip'] ?? ''),
                'created_at' => (string) ($row['created'] ?? ''),
                'status' => $status,
                'tags' => [],
                'lists' => $on,
            ];
            $stats['subscribers']++;
            $stats['by_status'][$status] = ($stats['by_status'][$status] ?? 0) + 1;
        }
        ksort($stats['by_status']);

        return ['records' => $records, 'stats' => $stats];
    }
}

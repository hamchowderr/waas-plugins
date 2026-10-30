<?php

namespace WaaSClient\Forms;

/**
 * Corrections for the known mistakes of the Fluent Forms migrator (Fluent Forms 6.2), kept as plain functions
 * so they can be tested without WordPress:
 * - Contact Form 7: the mail settings and the "sent" message aren't read, so the notifications and the
 *   confirmation are rebuilt here from the CF7 form's own mail, mail (2) and messages.
 * - WPForms: the last confirmation becomes the form's main one, so a conditional redirect ends up as the default.
 * - Ninja Forms: Reply-To is overwritten when the email goes to the admin, and the date format is copied as is
 *   (Ninja uses moment.js tokens, Fluent Forms uses PHP-style ones).
 * - Entries: kept in the order and with the dates they were sent.
 */
class FormFixes
{
    /** CF7's special mail tags and what Fluent Forms calls them. Empty: Fluent Forms has nothing like it. */
    private const CF7_SPECIAL_TAGS = [
        '_site_title' => '{wp.site_title}',
        '_site_url' => '{wp.site_url}',
        '_site_admin_email' => '{wp.admin_email}',
        '_site_description' => '',
        '_remote_ip' => '{ip}',
        '_user_agent' => '{browser.name}',
        '_url' => '{embed_post.permalink}',
        '_serial_number' => '{submission.serial_number}',
        '_post_id' => '{embed_post.ID}',
        '_post_name' => '',
        '_post_title' => '{embed_post.post_title}',
        '_post_url' => '{embed_post.permalink}',
        '_post_author' => '',
        '_post_author_email' => '',
        '_user_login' => '{user.user_login}',
        '_user_email' => '{user.user_email}',
        '_user_display_name' => '{user.display_name}',
        '_user_first_name' => '{user.first_name}',
        '_user_last_name' => '{user.last_name}',
        '_user_nickname' => '',
        '_user_url' => '',
        '_invalid_fields' => '',
    ];

    /**
     * A CF7 mail (or mail (2)) as a Fluent Forms email notification.
     *
     * @param array    $mail   CF7's mail property: active, subject, sender, recipient, body, additional_headers, use_html…
     * @param string[] $fields The form's field names (CF7 tag names; Fluent Forms keeps the same names).
     * @param array    $site   date_format and time_format, for [_date] and [_time].
     * @param string[] $unknown Filled with the mail tags Fluent Forms has no equivalent for.
     */
    public static function cf7_notification(array $mail, string $name, array $fields, array $site, array &$unknown = []): array
    {
        $tags = fn ($text) => self::cf7_tags((string) $text, $fields, $site, $unknown);
        [$from_name, $from_email] = self::split_address($tags($mail['sender'] ?? ''));

        $headers = self::headers((string) ($mail['additional_headers'] ?? ''));
        $recipient = trim((string) ($mail['recipient'] ?? ''));
        $send_to = ['type' => 'email', 'email' => $tags($recipient), 'field' => '', 'routing' => []];
        // One field as the recipient (an autoresponder to the visitor): Fluent Forms' "send to a field" setting.
        if (preg_match('/^\[(?:_raw_)?([\w-]+)\]$/', $recipient, $m) && in_array($m[1], $fields, true)) {
            $send_to = ['type' => 'field', 'email' => '', 'field' => $m[1], 'routing' => []];
        }

        $body = (string) ($mail['body'] ?? '');
        if (empty($mail['use_html'])) {
            // Plain text in CF7; Fluent Forms sends HTML, so keep the text as it was and its line breaks.
            $body = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        }

        return [
            'name' => $name,
            'sendTo' => $send_to,
            'fromName' => $from_name,
            'fromEmail' => $from_email,
            'replyTo' => $tags($headers['reply-to'] ?? ''),
            'cc' => $tags($headers['cc'] ?? ''),
            'bcc' => $tags($headers['bcc'] ?? ''),
            'subject' => $tags($mail['subject'] ?? ''),
            'message' => $tags($body),
            'conditionals' => ['status' => false, 'type' => 'all', 'conditions' => [['field' => '', 'operator' => '=', 'value' => '']]],
            'enabled' => !empty($mail['active']),
            'asPlainText' => 'no',
        ];
    }

    /** CF7 mail tags ([your-name], [_raw_topic], [_site_title]) as Fluent Forms smart codes ({inputs.your-name}…). */
    public static function cf7_tags(string $text, array $fields, array $site = [], array &$unknown = []): string
    {
        return (string) preg_replace_callback('/\[(_raw_|_format_)?([\w-]+)(?:\s+"[^"]*")?\]/', function ($m) use ($fields, $site, &$unknown) {
            $name = $m[2];
            if ($m[1] === '' && $name === '_date') {
                return '{date.' . ($site['date_format'] ?? 'F j, Y') . '}';
            }
            if ($m[1] === '' && $name === '_time') {
                return '{date.' . ($site['time_format'] ?? 'g:i a') . '}';
            }
            if (in_array($name, $fields, true)) {
                return '{inputs.' . $name . '}';
            }
            if ($m[1] === '' && array_key_exists($name, self::CF7_SPECIAL_TAGS)) {
                if (self::CF7_SPECIAL_TAGS[$name] === '') {
                    $unknown[] = $m[0];
                }
                return self::CF7_SPECIAL_TAGS[$name];
            }
            if (strpos($name, '_') === 0 || $m[1] !== '') {
                $unknown[] = $m[0];
                return '';
            }
            // Not a field of this form: CF7 leaves such text as it is, and so does this.
            return $m[0];
        }, $text);
    }

    /** "Name <email>" as [name, email]; a bare address as ['', email]. */
    public static function split_address(string $address): array
    {
        $address = trim($address);
        if (preg_match('/^(.*?)\s*<([^>]+)>$/', $address, $m)) {
            return [trim($m[1], " \t\"'"), trim($m[2])];
        }

        return ['', $address];
    }

    /** CF7's "additional headers" box (one "Name: value" per line), keyed by the lower-case header name. */
    public static function headers(string $text): array
    {
        $headers = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (preg_match('/^\s*([\w-]+)\s*:\s*(.+)$/', $line, $m)) {
                $headers[strtolower($m[1])] = trim($m[2]);
            }
        }

        return $headers;
    }

    /**
     * WPForms' confirmations, put right: the migrator makes the last one the form's main confirmation and keeps the
     * others as extra (conditional) ones. The main one should be the first one that applies to every submission,
     * and the conditional ones stay conditional.
     *
     * @return array [main confirmation, the other confirmations]
     */
    public static function wpforms_confirmations(?array $main, array $others): array
    {
        $all = array_values(array_filter(array_merge($others, $main ? [$main] : []), 'is_array'));
        if (!$all) {
            return [$main, $others];
        }
        $default = null;
        foreach ($all as $i => $confirmation) {
            if (empty($confirmation['conditionals']['status'])) {
                $default = $i;
                break;
            }
        }
        // Every confirmation has a condition: WPForms shows the first one when none match.
        $default = $default ?? 0;
        $chosen = $all[$default];
        unset($all[$default]);
        $chosen['conditionals'] = ['status' => false, 'type' => 'all', 'conditions' => []];

        return [$chosen, array_values($all)];
    }

    /** A Ninja Forms (moment.js) date format such as MM/DD/YYYY as the PHP-style one Fluent Forms uses (m/d/Y). */
    public static function ninja_date_format(string $format): string
    {
        $tokens = [
            'YYYY' => 'Y', 'YY' => 'y',
            'MMMM' => 'F', 'MMM' => 'M', 'MM' => 'm', 'M' => 'n',
            'dddd' => 'l', 'ddd' => 'D',
            'Do' => 'jS', 'DD' => 'd', 'D' => 'j',
        ];

        return (string) preg_replace_callback('/YYYY|YY|MMMM|MMM|MM|M|dddd|ddd|Do|DD|D/', fn ($m) => $tokens[$m[0]], $format);
    }

    /** Entries oldest first (by created_at), keeping the given order between entries sent at the same time. */
    public static function oldest_first(array $entries): array
    {
        $keyed = [];
        foreach (array_values($entries) as $i => $entry) {
            $keyed[] = [is_array($entry) ? (string) ($entry['created_at'] ?? '') : '', $i, $entry];
        }
        usort($keyed, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($keyed, 2);
    }
}

<?php

namespace WaaSClient\Crm;

/**
 * The pure part of the CRM switch: merges what each source knows about a person into one contact, decides the
 * contact's FluentCRM status, and works out what a write would change on a contact that is already in FluentCRM.
 * Nothing here touches WordPress, so it is unit-tested on its own.
 *
 * A source record is an array with: email, source, and any of first_name, last_name, phone, address_line_1,
 * address_line_2, city, state, postal_code, country, ip, created_at, contact_type, status (a FluentCRM status, or
 * null when the source holds no consent record), tags and lists (titles), custom (custom field slug => value).
 */
class ContactPlan
{
    /** Contact columns the switch fills, in FluentCRM's names. */
    public const FIELDS = ['first_name', 'last_name', 'phone', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'ip'];
    /** Statuses that stop all email. One of these in any source wins over a subscription elsewhere. */
    public const SUPPRESSED = ['complained', 'spammed', 'bounced', 'unsubscribed'];
    /** Which status wins when sources disagree: the first one found in this order. */
    private const PRIORITY = ['complained', 'spammed', 'bounced', 'unsubscribed', 'subscribed', 'pending', 'transactional'];

    /**
     * Merges source records by email (case-insensitive). The first source with a value for a field gives it, so
     * the order of $records sets the precedence (WooCommerce billing details first). Tags and lists are joined.
     *
     * @param array $records Source records, in precedence order.
     * @param string $without_consent The status for a person no source holds a consent record for.
     * @return array<string, array> email => merged contact.
     */
    public static function merge(array $records, string $without_consent = 'transactional'): array
    {
        $contacts = [];
        foreach ($records as $record) {
            $email = self::email($record['email'] ?? '');
            if ($email === '') {
                continue;
            }
            if (!isset($contacts[$email])) {
                $contacts[$email] = ['email' => $email, 'sources' => [], 'statuses' => [], 'tags' => [], 'lists' => [], 'custom' => [], 'contact_type' => 'lead'];
            }
            $contact = &$contacts[$email];
            $source = (string) ($record['source'] ?? '');
            if ($source !== '' && !in_array($source, $contact['sources'], true)) {
                $contact['sources'][] = $source;
            }
            foreach (array_merge(self::FIELDS, ['created_at']) as $field) {
                $value = trim((string) ($record[$field] ?? ''));
                if ($value !== '' && !isset($contact[$field])) {
                    $contact[$field] = $value;
                }
            }
            if (($record['contact_type'] ?? '') === 'customer') {
                $contact['contact_type'] = 'customer';
            }
            if (!empty($record['status'])) {
                $contact['statuses'][$source] = (string) $record['status'];
            }
            foreach (['tags', 'lists'] as $key) {
                foreach ((array) ($record[$key] ?? []) as $title) {
                    $title = trim((string) $title);
                    if ($title !== '' && !in_array($title, $contact[$key], true)) {
                        $contact[$key][] = $title;
                    }
                }
            }
            foreach ((array) ($record['custom'] ?? []) as $slug => $value) {
                $contact['custom'][$slug] = $value;
            }
            unset($contact);
        }
        foreach ($contacts as &$contact) {
            $contact['status'] = self::status($contact['statuses'], $without_consent);
            $contact['consent'] = $contact['statuses'] ? 'record' : 'none';
        }
        unset($contact);

        return $contacts;
    }

    /** The status that wins among the sources' statuses; $fallback when none has a consent record. */
    public static function status(array $statuses, string $fallback = 'transactional'): string
    {
        foreach (self::PRIORITY as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return $fallback;
    }

    /**
     * The status to write to a contact already in FluentCRM, or null to leave it. A suppression (unsubscribed,
     * bounced, complained, spammed) always wins and is never lifted; a subscription only lifts a pending or
     * transactional contact; pending and transactional never change an existing contact.
     */
    public static function status_for_existing(string $wanted, string $current): ?string
    {
        if ($wanted === $current || in_array($current, self::SUPPRESSED, true)) {
            return null;
        }
        if (in_array($wanted, self::SUPPRESSED, true)) {
            return $wanted;
        }
        if ($wanted === 'subscribed' && in_array($current, ['pending', 'transactional'], true)) {
            return 'subscribed';
        }

        return null;
    }

    /**
     * What writing $contact would change on an existing FluentCRM contact. Columns are only filled where empty,
     * so the owner's own data is never overwritten.
     *
     * @param array $contact The merged contact.
     * @param array $row The FluentCRM contact's columns.
     * @param string[] $tags Titles of the tags it has.
     * @param string[] $lists Titles of the lists it is on.
     * @param array $custom Its custom field values (slug => value).
     * @return array{fields: array, status: ?string, tags: string[], lists: string[], custom: array, remove_tags: string[]}
     */
    public static function changes(array $contact, array $row, array $tags, array $lists, array $custom): array
    {
        $fields = [];
        foreach (self::FIELDS as $field) {
            if (isset($contact[$field]) && trim((string) ($row[$field] ?? '')) === '') {
                $fields[$field] = $contact[$field];
            }
        }
        if (($contact['contact_type'] ?? '') === 'customer' && ($row['contact_type'] ?? '') !== 'customer') {
            $fields['contact_type'] = 'customer';
        }
        $custom_changes = [];
        foreach ($contact['custom'] as $slug => $value) {
            if (!array_key_exists($slug, $custom) || !self::same_value($custom[$slug], $value)) {
                $custom_changes[$slug] = $value;
            }
        }
        $new_tags = array_values(array_diff($contact['tags'], $tags));

        return [
            'fields' => $fields,
            // Without a consent record in any source, an existing contact's status is its own business.
            'status' => ($contact['consent'] ?? 'record') === 'none' ? null : self::status_for_existing($contact['status'], (string) ($row['status'] ?? '')),
            'tags' => $new_tags,
            'lists' => array_values(array_diff($contact['lists'], $lists)),
            'custom' => $custom_changes,
            'remove_tags' => self::interested_to_remove(array_merge($tags, $new_tags), $tags),
        ];
    }

    /** Whether a stored custom value equals the new one (FluentCRM stores number fields as numbers: 25.00 as 25). */
    public static function same_value($stored, $value): bool
    {
        if (is_numeric($stored) && is_numeric($value)) {
            return abs((float) $stored - (float) $value) < 0.0001;
        }

        return (string) $stored === (string) $value;
    }

    /** True when the changes do nothing. */
    public static function is_empty(array $changes): bool
    {
        return !$changes['fields'] && $changes['status'] === null && !$changes['tags'] && !$changes['lists'] && !$changes['custom'] && !$changes['remove_tags'];
    }

    /**
     * "X: Interested" tags a contact must lose because it also has "X: Past" or "X: Active" (the tagging model's
     * first rule: interest is exclusive).
     *
     * @param string[] $all Every tag the contact will have.
     * @param string[] $current The tags it has now (only these can be removed).
     */
    public static function interested_to_remove(array $all, array $current): array
    {
        $remove = [];
        foreach ($current as $title) {
            if (preg_match('/^(.+): Interested$/u', $title, $match)
                && (in_array($match[1] . ': Past', $all, true) || in_array($match[1] . ': Active', $all, true))) {
                $remove[] = $title;
            }
        }

        return $remove;
    }

    /** The tag for a product someone bought: "Product: Past" (one offering per product). */
    public static function past_tag(string $product): string
    {
        $product = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($product)));

        return $product === '' ? '' : $product . ': Past';
    }

    public static function email(string $email): string
    {
        return strtolower(trim($email));
    }
}

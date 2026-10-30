<?php

namespace WaaSClient\Crm;

/**
 * Writes the merged contacts into FluentCRM through FluentCRM's own PHP API (FluentCrmApi('contacts')->createOrUpdate
 * and its tag and list models), and takes them back out on undo.
 *
 * What it records in the switch's state, so undo touches only its own work:
 * - contacts: ids of the contacts it created (undo deletes them);
 * - existing: for each contact that was already in FluentCRM, its columns and custom values before the first change,
 *   and the tags and lists it added or removed (undo puts all of that back);
 * - tags, lists: ids of the tags and lists it created (undo deletes them);
 * - fields: slugs of the custom fields it created (undo removes them).
 */
class CrmWriter
{
    private const SUBSCRIBER = 'FluentCrm\App\Models\Subscriber';
    private const TAG = 'FluentCrm\App\Models\Tag';
    private const LISTS = 'FluentCrm\App\Models\Lists';
    private const FIELDS_CLASS = 'FluentCrm\App\Models\CustomContactField';
    private const HELPER = 'FluentCrm\App\Services\Helper';
    /** The custom fields WooCommerce buyers carry (free FluentCRM has custom contact fields). */
    public const CUSTOM_FIELDS = [
        WooBuyers::FIELD_ORDERS => 'WooCommerce orders',
        WooBuyers::FIELD_SPENT => 'WooCommerce total spent',
    ];
    /** Rows listed per contact in the report; the counts always cover everyone. */
    public const ROWS = 200;

    /**
     * @param array $contacts email => merged contact (ContactPlan::merge).
     * @param array $state The switch's state, updated in place unless $dry_run.
     */
    public static function apply(array $contacts, array &$state, bool $dry_run): array
    {
        $report = [
            'contacts' => ['total' => count($contacts), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'by_status' => []],
            'tags' => ['created' => [], 'existing' => []],
            'lists' => ['created' => [], 'existing' => []],
            'fields' => ['created' => [], 'existing' => []],
            'interested_removed' => 0,
            'rows' => [],
        ];

        $needs_fields = false;
        $tag_titles = [];
        $list_titles = [];
        foreach ($contacts as $contact) {
            $needs_fields = $needs_fields || (bool) $contact['custom'];
            $tag_titles = array_merge($tag_titles, $contact['tags']);
            $list_titles = array_merge($list_titles, $contact['lists']);
        }
        if ($needs_fields) {
            self::fields($state, $dry_run, $report['fields']);
        }
        $tags = self::terms(self::TAG, 'tags', array_values(array_unique($tag_titles)), $state, $dry_run, $report['tags']);
        $lists = self::terms(self::LISTS, 'lists', array_values(array_unique($list_titles)), $state, $dry_run, $report['lists']);
        $existing = self::existing_emails();

        foreach ($contacts as $email => $contact) {
            $id = $existing[$email] ?? 0;
            $ours = $id && in_array($id, $state['contacts'], true);
            if (!$id) {
                $action = 'created';
                $status = $contact['status'];
                if (!$dry_run) {
                    $created = self::create($contact, $tags, $lists);
                    if ($created) {
                        $state['contacts'][] = $created;
                    } else {
                        $action = 'failed';
                    }
                }
            } else {
                $row = self::row($id);
                $changes = ContactPlan::changes($contact, $row, self::titles($id, self::TAG, 'fc_tags'), self::titles($id, self::LISTS, 'fc_lists'), self::custom_values($id));
                $status = $changes['status'] ?? (string) $row['status'];
                $action = ContactPlan::is_empty($changes) ? 'unchanged' : 'updated';
                $report['interested_removed'] += count($changes['remove_tags']);
                if ($action === 'updated' && !$dry_run) {
                    if (!$ours) {
                        self::remember($state, $id, $row, $changes);
                    }
                    self::update($id, $row, $changes, $tags, $lists, $state, $ours);
                }
            }
            $report['contacts'][$action]++;
            $report['contacts']['by_status'][$status] = ($report['contacts']['by_status'][$status] ?? 0) + 1;
            if (count($report['rows']) < self::ROWS) {
                $report['rows'][] = [
                    'email' => $email,
                    'sources' => implode(', ', $contact['sources']),
                    'status' => $status,
                    'consent' => $contact['consent'] === 'record' ? 'yes' : 'no record',
                    'tags' => implode(', ', $contact['tags']),
                    'lists' => implode(', ', $contact['lists']),
                    'action' => $dry_run && $action !== 'unchanged' ? 'will be ' . $action : $action,
                ];
            }
        }
        ksort($report['contacts']['by_status']);

        return $report;
    }

    /** Creates a new contact with everything the sources know. Returns its id, or 0. */
    private static function create(array $contact, array $tags, array $lists): int
    {
        $data = ['email' => $contact['email'], 'status' => $contact['status'], 'contact_type' => $contact['contact_type'], 'source' => implode(', ', $contact['sources'])];
        foreach (array_merge(ContactPlan::FIELDS, ['created_at']) as $field) {
            if (isset($contact[$field])) {
                $data[$field] = $contact[$field];
            }
        }
        if (isset($data['created_at']) && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['created_at'])) {
            unset($data['created_at']);
        }
        $data['tags'] = self::ids($contact['tags'], $tags);
        $data['lists'] = self::ids($contact['lists'], $lists);
        if ($contact['custom']) {
            $data['custom_values'] = $contact['custom'];
        }
        $model = FluentCrmApi('contacts')->createOrUpdate($data, false, false);

        return $model ? (int) $model->id : 0;
    }

    /** Applies the changes to a contact that already exists. */
    private static function update(int $id, array $row, array $changes, array $tags, array $lists, array &$state, bool $ours): void
    {
        $data = array_merge(['email' => $row['email']], $changes['fields']);
        if ($changes['status'] !== null) {
            $data['status'] = $changes['status'];
        }
        $data['tags'] = self::ids($changes['tags'], $tags);
        $data['lists'] = self::ids($changes['lists'], $lists);
        if ($changes['custom']) {
            $data['custom_values'] = $changes['custom'];
        }
        if ($changes['remove_tags']) {
            $data['detach_tags'] = self::existing_ids(self::TAG, $changes['remove_tags']);
        }
        FluentCrmApi('contacts')->createOrUpdate($data, false, false);
        if (!$ours) {
            $record = &$state['existing'][$id];
            $record['tags_added'] = array_values(array_unique(array_merge($record['tags_added'], $data['tags'])));
            $record['lists_added'] = array_values(array_unique(array_merge($record['lists_added'], $data['lists'])));
            $record['tags_removed'] = array_values(array_unique(array_merge($record['tags_removed'], $data['detach_tags'] ?? [])));
        }
    }

    /** Keeps what an existing contact looked like before this command first changed it. */
    private static function remember(array &$state, int $id, array $row, array $changes): void
    {
        if (!isset($state['existing'][$id])) {
            $before = [];
            foreach (array_merge(ContactPlan::FIELDS, ['status', 'contact_type']) as $field) {
                $before[$field] = $row[$field] ?? null;
            }
            $state['existing'][$id] = ['before' => $before, 'custom_before' => [], 'tags_added' => [], 'lists_added' => [], 'tags_removed' => []];
        }
        $current = self::custom_values($id);
        foreach (array_keys($changes['custom']) as $slug) {
            if (!array_key_exists($slug, $state['existing'][$id]['custom_before'])) {
                $state['existing'][$id]['custom_before'][$slug] = $current[$slug] ?? null;
            }
        }
    }

    /**
     * Undoes everything recorded in $state.
     *
     * @return array Counts of what was (or would be) taken back out.
     */
    public static function undo(array &$state, bool $dry_run): array
    {
        global $wpdb;
        $report = [
            'contacts_removed' => count(array_filter($state['contacts'], [self::class, 'exists'])),
            'contacts_restored' => count(array_filter(array_keys($state['existing']), [self::class, 'exists'])),
            'tags_removed' => count($state['tags']),
            'lists_removed' => count($state['lists']),
            'fields_removed' => count($state['fields']),
        ];
        if ($dry_run) {
            return $report;
        }

        $created = array_values(array_filter($state['contacts'], [self::class, 'exists']));
        if ($created) {
            call_user_func([self::HELPER, 'deleteContacts'], $created);
        }

        $subscriber = self::SUBSCRIBER;
        foreach ($state['existing'] as $id => $record) {
            $model = $subscriber::find((int) $id);
            if (!$model) {
                continue;
            }
            $model->fill(array_filter($record['before'], fn ($value) => $value !== null))->save();
            $nulls = array_keys(array_filter($record['before'], fn ($value) => $value === null));
            if ($nulls) {
                $wpdb->update($wpdb->prefix . 'fc_subscribers', array_fill_keys($nulls, null), ['id' => (int) $id]);
            }
            foreach ($record['custom_before'] as $slug => $value) {
                $where = ['subscriber_id' => (int) $id, 'object_type' => 'custom_field', 'key' => $slug];
                if ($value === null) {
                    $wpdb->delete($wpdb->prefix . 'fc_subscriber_meta', $where);
                } elseif ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fc_subscriber_meta WHERE subscriber_id = %d AND object_type = 'custom_field' AND `key` = %s", (int) $id, $slug))) {
                    $wpdb->update($wpdb->prefix . 'fc_subscriber_meta', ['value' => maybe_serialize($value)], $where);
                } else {
                    $wpdb->insert($wpdb->prefix . 'fc_subscriber_meta', $where + ['value' => maybe_serialize($value), 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
                }
            }
            $model->detachTags(array_values(array_diff($record['tags_added'], $state['tags'])));
            $model->detachLists(array_values(array_diff($record['lists_added'], $state['lists'])));
            $model->attachTags(array_values(array_filter($record['tags_removed'], fn ($tag) => self::term_exists(self::TAG, (int) $tag))));
        }

        foreach ([[self::TAG, 'tags', 'fluent_crm/tag_deleted'], [self::LISTS, 'lists', 'fluent_crm/list_deleted']] as [$class, $key, $hook]) {
            foreach ($state[$key] as $term_id) {
                $term = $class::find((int) $term_id);
                if ($term) {
                    $term->delete();
                    // FluentCRM's own cleanup takes the tag or list off every contact.
                    do_action($hook, (int) $term_id);
                }
            }
        }

        if ($state['fields']) {
            $class = self::FIELDS_CLASS;
            $fields = (new $class())->getGlobalFields()['fields'];
            $fields = array_values(array_filter((array) $fields, fn ($field) => !in_array($field['slug'] ?? '', $state['fields'], true)));
            fluentcrm_update_option('contact_custom_fields', $fields);
        }

        $state = CrmSwitch::empty_state();

        return $report;
    }

    /** Makes sure the WooCommerce custom fields exist. */
    private static function fields(array &$state, bool $dry_run, array &$report): void
    {
        $class = self::FIELDS_CLASS;
        $fields_model = new $class();
        $fields = (array) $fields_model->getGlobalFields()['fields'];
        $slugs = array_column($fields, 'slug');
        $added = false;
        foreach (self::CUSTOM_FIELDS as $slug => $label) {
            if (in_array($slug, $slugs, true)) {
                $report['existing'][] = $slug;
                continue;
            }
            $report['created'][] = $slug;
            if ($dry_run) {
                continue;
            }
            $fields[] = ['field_key' => 'number', 'type' => 'number', 'label' => $label, 'slug' => $slug, 'group' => 'default'];
            if (!in_array($slug, $state['fields'], true)) {
                $state['fields'][] = $slug;
            }
            $added = true;
        }
        if ($added) {
            $fields_model->saveGlobalFields($fields);
        }
    }

    /**
     * Finds or creates each tag or list by title. Returns title => id (0 for one a dry run would create).
     */
    private static function terms(string $class, string $kind, array $titles, array &$state, bool $dry_run, array &$report): array
    {
        $ids = [];
        foreach ($titles as $title) {
            $term = $class::where('title', $title)->first() ?: $class::where('slug', sanitize_title($title))->first();
            if ($term) {
                $ids[$title] = (int) $term->id;
                $report[in_array((int) $term->id, $state[$kind], true) ? 'created' : 'existing'][] = $title;
                continue;
            }
            $report['created'][] = $title;
            if ($dry_run) {
                $ids[$title] = 0;
                continue;
            }
            $made = FluentCrmApi($kind)->importBulk([['title' => $title, 'slug' => sanitize_title($title)]]);
            $id = isset($made[0]) ? (int) $made[0]->id : 0;
            $ids[$title] = $id;
            if ($id) {
                $state[$kind][] = $id;
            }
        }

        return $ids;
    }

    private static function ids(array $titles, array $map): array
    {
        return array_values(array_filter(array_map(fn ($title) => $map[$title] ?? 0, $titles)));
    }

    private static function existing_ids(string $class, array $titles): array
    {
        $ids = [];
        foreach ($titles as $title) {
            $term = $class::where('title', $title)->first();
            if ($term) {
                $ids[] = (int) $term->id;
            }
        }

        return $ids;
    }

    /** Every FluentCRM contact's email (lower case) => id. */
    private static function existing_emails(): array
    {
        global $wpdb;
        $map = [];
        foreach ((array) $wpdb->get_results("SELECT id, email FROM {$wpdb->prefix}fc_subscribers", ARRAY_A) as $row) {
            $map[ContactPlan::email((string) $row['email'])] = (int) $row['id'];
        }

        return $map;
    }

    private static function row(int $id): array
    {
        global $wpdb;

        return (array) $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}fc_subscribers WHERE id = %d", $id), ARRAY_A);
    }

    /** Titles of the tags or lists a contact has. */
    private static function titles(int $id, string $type, string $table): array
    {
        global $wpdb;

        return array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT t.title FROM {$wpdb->prefix}fc_subscriber_pivot p JOIN {$wpdb->prefix}{$table} t ON t.id = p.object_id WHERE p.subscriber_id = %d AND p.object_type = %s",
            $id,
            $type
        )));
    }

    private static function custom_values(int $id): array
    {
        global $wpdb;
        $values = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT `key`, value FROM {$wpdb->prefix}fc_subscriber_meta WHERE subscriber_id = %d AND object_type = 'custom_field'", $id), ARRAY_A) as $row) {
            $values[$row['key']] = maybe_unserialize($row['value']);
        }

        return $values;
    }

    private static function exists($id): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fc_subscribers WHERE id = %d", (int) $id));
    }

    private static function term_exists(string $class, int $id): bool
    {
        return $id > 0 && (bool) $class::find($id);
    }
}

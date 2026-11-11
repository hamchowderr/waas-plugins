<?php

namespace AnyPressClient\Crm\Importers;

use FluentCrm\App\Services\CrmMigrator\BaseMigrator;
use MailerLite\Common\HttpLayer;
use MailerLite\MailerLite;

/**
 * MailerLite accounts made since March 2022 in FluentCRM's "Migrate from another CRM" screen, through MailerLite's
 * official PHP SDK. FluentCRM's own MailerLite importer calls the Classic API, which only takes keys from MailerLite
 * Classic accounts. Each MailerLite group chosen becomes a FluentCRM tag (or goes into one that exists), fields map
 * onto FluentCRM fields, and every subscriber comes across with their MailerLite status (active, unsubscribed,
 * unconfirmed, bounced, junk); one FluentCRM already holds as unsubscribed, bounced or complained stays so.
 *
 * Only loaded when FluentCRM is active (the parent class must exist).
 */
class MailerLiteMigrator extends BaseMigrator
{
    public const DRIVER = 'anypress_mailerlite';
    private const PER_PAGE = 200;
    /** MailerLite's built-in fields => FluentCRM fields. */
    private const AUTO_FIELDS = ['name' => 'first_name', 'last_name' => 'last_name', 'phone' => 'phone', 'city' => 'city', 'state' => 'state', 'country' => 'country', 'z_i_p' => 'postal_code', 'zip' => 'postal_code'];
    /** MailerLite status => FluentCRM status. */
    private const STATUSES = ['active' => 'subscribed', 'unsubscribed' => 'unsubscribed', 'unconfirmed' => 'pending', 'bounced' => 'bounced', 'junk' => 'complained'];

    public function getInfo()
    {
        return [
            'title' => __('MailerLite (accounts since 2022)', 'anypress-client'),
            'description' => __('Migrate contacts from a new MailerLite account (connect.mailerlite.com) to FluentCRM, with their groups, fields and status', 'anypress-client'),
            'logo' => '',
            'logo_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="6" fill="#09C269"/><text x="16" y="22" font-family="Arial, sans-serif" font-size="14" font-weight="700" fill="#fff" text-anchor="middle">ML</text></svg>',
            'supports' => [
                'tags' => true,
                'lists' => false,
                'empty_tags' => false,
                'active_imports_only' => false,
            ],
            'field_map_info' => __('Email address is mapped automatically, and name, last name, phone and address where MailerLite has them', 'anypress-client'),
            'tags_map_info' => __('Only the subscribers of the selected groups will be imported, each group as a tag', 'anypress-client'),
            'credentials' => ['api_key' => ''],
            'credential_fields' => [
                'api_key' => [
                    'label' => __('API token', 'anypress-client'),
                    'placeholder' => __('MailerLite API token', 'anypress-client'),
                    'data_type' => 'password',
                    'type' => 'input-text',
                    'inline_help' => $this->getInfoIcon() . ' ' . sprintf(
                        /* translators: %1$s: opening link tag to MailerLite's integrations page, %2$s: closing link tag */
                        __('Make one in MailerLite under %1$sIntegrations, MailerLite API, Generate new token%2$s. A MailerLite Classic key works only with FluentCRM\'s own MailerLite importer.', 'anypress-client'),
                        '<a href="https://dashboard.mailerlite.com/integrations/api" target="_blank" rel="noopener">',
                        '</a>'
                    ),
                ],
            ],
            'refresh_on_list_change' => false,
            'doc_url' => 'https://developers.mailerlite.com/docs/',
        ];
    }

    public function verifyCredentials($credential)
    {
        try {
            $this->api((array) $credential)->groups->get(['limit' => 1]);
        } catch (\Throwable $e) {
            return new \WP_Error('api_error', $e->getMessage());
        }

        return true;
    }

    public function getListTagMappings($settings)
    {
        try {
            $api = $this->api((array) ($settings['credential'] ?? []));
            $tags = [];
            foreach ($this->groups($api) as $group) {
                $tags[] = ['remote_name' => (string) ($group['name'] ?? ''), 'remote_id' => (string) ($group['id'] ?? ''), 'will_create' => 'no', 'fluentcrm_id' => ''];
            }
            $fields = [];
            foreach ((array) (self::body($api->fields->get(['limit' => 100]))['data'] ?? []) as $field) {
                $key = (string) ($field['key'] ?? '');
                if ($key === '' || $key === 'email') {
                    continue;
                }
                $fields[] = ['type' => 'any', 'remote_label' => (string) ($field['name'] ?? $key), 'remote_tag' => $key, 'fluentcrm_field' => self::AUTO_FIELDS[$key] ?? '', 'will_skip' => 'no'];
            }
        } catch (\Throwable $e) {
            return new \WP_Error('api_error', $e->getMessage());
        }

        return ['tags' => $tags, 'contact_fields' => $fields, 'contact_fillables' => $this->getFillables(), 'all_ready' => true];
    }

    public function getSummary($settings)
    {
        try {
            $counts = [];
            foreach ($this->groups($this->api((array) ($settings['credential'] ?? []))) as $group) {
                $counts[(string) ($group['id'] ?? '')] = (int) ($group['active_count'] ?? 0) + (int) ($group['unsubscribed_count'] ?? 0) + (int) ($group['unconfirmed_count'] ?? 0) + (int) ($group['bounced_count'] ?? 0) + (int) ($group['junk_count'] ?? 0);
            }
        } catch (\Throwable $e) {
            return new \WP_Error('api_error', $e->getMessage());
        }
        $chosen = 0;
        $contacts = 0;
        foreach ((array) ($settings['tags'] ?? []) as $map) {
            if (($map['will_create'] ?? '') === 'yes' || !empty($map['fluentcrm_id'])) {
                $chosen++;
                $contacts += $counts[(string) ($map['remote_id'] ?? '')] ?? 0;
            }
        }

        return [
            'subscribed_count' => $contacts,
            'unsubscribed_count' => 0,
            'all_count' => $contacts,
            /* translators: 1: number of MailerLite groups chosen, 2: number of subscribers in them */
            'message' => sprintf(_n('Based on your selections, %1$d group and its %2$d subscribers will be imported from MailerLite.', 'Based on your selections, %1$d groups and their %2$d subscribers will be imported from MailerLite.', $chosen, 'anypress-client'), $chosen, $contacts),
        ];
    }

    public function runImport($settings)
    {
        if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
            define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);
        }
        $tags = $this->mapTags((array) ($settings['tags'] ?? []));
        $groups = array_keys($tags);
        if (!$groups) {
            return new \WP_Error('not_found', __('Choose at least one MailerLite group to import.', 'anypress-client'));
        }
        $tracker = (array) ($settings['import_tracker'] ?? []) + ['current_index' => 0, 'cursor' => ''];
        $group = $groups[(int) $tracker['current_index']] ?? null;
        if ($group === null) {
            return ['completed' => 0, 'total' => 0, 'import_tracker' => $tracker, 'has_more' => false, 'message' => __('The import is done.', 'anypress-client')];
        }

        $params = ['limit' => self::PER_PAGE];
        if ((string) $tracker['cursor'] !== '') {
            $params['cursor'] = (string) $tracker['cursor'];
        }
        try {
            $page = self::body($this->api((array) ($settings['credential'] ?? []))->groups->getSubscribers((string) $group, $params));
        } catch (\Throwable $e) {
            return new \WP_Error('api_error', $e->getMessage());
        }

        $field_maps = (array) ($settings['contact_fields'] ?? []);
        $local_list = (int) ($settings['map_settings']['local_list_id'] ?? 0);
        foreach ((array) ($page['data'] ?? []) as $remote) {
            $email = sanitize_email((string) ($remote['email'] ?? ''));
            if (!is_email($email)) {
                continue;
            }
            $fields = (array) ($remote['fields'] ?? []);
            $data = self::contact($email, $remote, $this->getMergedData($fields, $field_maps));
            $data['tags'] = [$tags[$group]];
            if ($local_list) {
                $data['lists'] = [$local_list];
            }
            $existing = \FluentCrm\App\Models\Subscriber::where('email', $email)->first();
            if ($existing && in_array($existing->status, fluentcrm_strict_statues(), true)) {
                // Never resurrect a contact FluentCRM holds as unsubscribed, bounced or complained.
                unset($data['status']);
            }
            $contact = FluentCrmApi('contacts')->createOrUpdate($data);
            if ($contact && isset($data['status']) && $contact->status !== $data['status']) {
                $contact->updateStatus($data['status']);
            }
        }

        $next = (string) ($page['meta']['next_cursor'] ?? '');
        $tracker = $next !== ''
            ? ['current_index' => (int) $tracker['current_index'], 'cursor' => $next]
            : ['current_index' => (int) $tracker['current_index'] + 1, 'cursor' => ''];

        return [
            'completed' => 0,
            'total' => 0,
            'import_tracker' => $tracker,
            'has_more' => isset($groups[$tracker['current_index']]),
            /* translators: %d: number of MailerLite groups imported so far */
            'message' => sprintf(__('The import is running. %d groups have been imported so far.', 'anypress-client'), $tracker['current_index']),
        ];
    }

    /**
     * The FluentCRM contact for a MailerLite subscriber: email, name, status, dates, sign-up IP and mapped fields.
     *
     * @param array<string, mixed> $remote The subscriber as MailerLite's API returns it.
     * @param array<string, mixed> $mapped getMergedData() of its fields and the chosen field map.
     */
    public static function contact(string $email, array $remote, array $mapped): array
    {
        $fields = (array) ($remote['fields'] ?? []);
        $data = [
            'email' => $email,
            'first_name' => sanitize_text_field((string) ($fields['name'] ?? '')),
            'last_name' => sanitize_text_field((string) ($fields['last_name'] ?? '')),
            'source' => 'MailerLite',
            'status' => self::STATUSES[(string) ($remote['status'] ?? 'active')] ?? 'subscribed',
            'ip' => sanitize_text_field((string) ($remote['ip_address'] ?? '')),
        ];
        $time = strtotime((string) ($remote['subscribed_at'] ?? $remote['created_at'] ?? ''));
        if ($time) {
            $data['created_at'] = gmdate('Y-m-d H:i:s', $time);
        }

        return array_filter(array_merge($data, $mapped), fn ($value) => $value !== '' && $value !== null && $value !== []);
    }

    /** @return array<int, array<string, mixed>> Every group, 100 a page. */
    private function groups(MailerLite $api): array
    {
        $all = [];
        $page = 1;
        do {
            $body = self::body($api->groups->get(['limit' => 100, 'page' => $page]));
            $all = array_merge($all, (array) ($body['data'] ?? []));
            $last = (int) ($body['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $last);

        return $all;
    }

    /** The SDK's answer as an array (it decodes only an exact application/json content type). */
    private static function body(array $response): array
    {
        $body = $response['body'] ?? [];
        if (is_string($body)) {
            $body = json_decode($body, true);
        }

        return is_array($body) ? $body : [];
    }

    private function api(array $credential): MailerLite
    {
        $key = trim((string) ($credential['api_key'] ?? ''));
        $client = new WpHttpClient();
        $options = ['api_key' => $key];

        return new MailerLite($options, new HttpLayer($options, $client, $client->factory(), $client->factory()));
    }
}

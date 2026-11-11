<?php

namespace AnyPressClient\Crm\Importers;

use Brevo\Brevo;
use Brevo\Contacts\Requests\GetContactsFromListRequest;
use Brevo\Contacts\Requests\GetListsRequest;
use FluentCrm\App\Services\CrmMigrator\BaseMigrator;

/**
 * Brevo (formerly Sendinblue) in FluentCRM's own "Migrate from another CRM" screen, through Brevo's official PHP
 * SDK. Works like FluentCRM's MailerLite importer: each Brevo list chosen becomes a FluentCRM tag (or goes into one
 * that exists), Brevo's contact attributes map onto FluentCRM fields, and contacts come across a page at a time.
 * A contact Brevo has blocklisted for email comes across unsubscribed; a contact FluentCRM already holds as
 * unsubscribed, bounced or complained stays so.
 *
 * Only loaded when FluentCRM is active (the parent class must exist).
 */
class BrevoMigrator extends BaseMigrator
{
    public const DRIVER = 'anypress_brevo';
    private const PER_PAGE = 200;
    /** Brevo's built-in attributes => FluentCRM fields. */
    private const AUTO_FIELDS = ['FIRSTNAME' => 'first_name', 'LASTNAME' => 'last_name', 'SMS' => 'phone', 'NOM' => 'last_name', 'PRENOM' => 'first_name'];

    public function getInfo()
    {
        return [
            'title' => 'Brevo',
            'description' => __('Migrate your Brevo (Sendinblue) contacts, their lists and attributes to FluentCRM', 'anypress-client'),
            'logo' => '',
            'logo_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="6" fill="#0B996E"/><text x="16" y="22" font-family="Arial, sans-serif" font-size="16" font-weight="700" fill="#fff" text-anchor="middle">B</text></svg>',
            'supports' => [
                'tags' => true,
                'lists' => false,
                'empty_tags' => false,
                'active_imports_only' => false,
            ],
            'field_map_info' => __('Email address is mapped automatically, and first name, last name and SMS where Brevo has them', 'anypress-client'),
            'tags_map_info' => __('Only the contacts of the selected Brevo lists will be imported, each list as a tag', 'anypress-client'),
            'credentials' => ['api_key' => ''],
            'credential_fields' => [
                'api_key' => [
                    'label' => __('API Key', 'anypress-client'),
                    'placeholder' => __('Brevo API key (xkeysib-...)', 'anypress-client'),
                    'data_type' => 'password',
                    'type' => 'input-text',
                    'inline_help' => $this->getInfoIcon() . ' ' . sprintf(
                        /* translators: %1$s: opening link tag to Brevo's API keys page, %2$s: closing link tag */
                        __('Create one in Brevo under %1$sSMTP & API, API Keys%2$s. It is used for this import only.', 'anypress-client'),
                        '<a href="https://app.brevo.com/settings/keys/api" target="_blank" rel="noopener">',
                        '</a>'
                    ),
                ],
            ],
            'refresh_on_list_change' => false,
            'doc_url' => 'https://developers.brevo.com/docs/getting-started',
        ];
    }

    public function verifyCredentials($credential)
    {
        try {
            // The lists call needs only the key's Contacts permission, which the import needs anyway.
            $this->api((array) $credential)->contacts->getLists(new GetListsRequest(['limit' => 1]));
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
            foreach ($this->lists($api) as $list) {
                $tags[] = ['remote_name' => (string) $list->name, 'remote_id' => (string) $list->id, 'will_create' => 'no', 'fluentcrm_id' => ''];
            }
            $fields = [];
            foreach ((array) ($api->contacts->getAttributes()?->attributes ?? []) as $attribute) {
                if ($attribute->category !== 'normal') {
                    continue;
                }
                $fields[] = [
                    'type' => 'any',
                    'remote_label' => (string) $attribute->name,
                    'remote_tag' => (string) $attribute->name,
                    'fluentcrm_field' => self::AUTO_FIELDS[$attribute->name] ?? '',
                    'will_skip' => 'no',
                ];
            }
        } catch (\Throwable $e) {
            return new \WP_Error('api_error', $e->getMessage());
        }

        return ['tags' => $tags, 'contact_fields' => $fields, 'contact_fillables' => $this->getFillables(), 'all_ready' => true];
    }

    public function getSummary($settings)
    {
        try {
            $lists = [];
            foreach ($this->lists($this->api((array) ($settings['credential'] ?? []))) as $list) {
                $lists[(string) $list->id] = (int) $list->uniqueSubscribers;
            }
        } catch (\Throwable $e) {
            return new \WP_Error('api_error', $e->getMessage());
        }
        $chosen = 0;
        $contacts = 0;
        foreach ((array) ($settings['tags'] ?? []) as $map) {
            if (($map['will_create'] ?? '') === 'yes' || !empty($map['fluentcrm_id'])) {
                $chosen++;
                $contacts += $lists[(string) ($map['remote_id'] ?? '')] ?? 0;
            }
        }

        return [
            'subscribed_count' => $contacts,
            'unsubscribed_count' => 0,
            'all_count' => $contacts,
            /* translators: 1: number of Brevo lists chosen, 2: number of contacts in them */
            'message' => sprintf(_n('Based on your selections, %1$d list and its %2$d contacts will be imported from Brevo.', 'Based on your selections, %1$d lists and their %2$d contacts will be imported from Brevo.', $chosen, 'anypress-client'), $chosen, $contacts),
        ];
    }

    public function runImport($settings)
    {
        if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
            define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);
        }
        $tags = $this->mapTags((array) ($settings['tags'] ?? []));
        $lists = array_keys($tags);
        if (!$lists) {
            return new \WP_Error('not_found', __('Choose at least one Brevo list to import.', 'anypress-client'));
        }
        $tracker = (array) ($settings['import_tracker'] ?? []) + ['current_index' => 0, 'offset' => 0];
        $list = $lists[(int) $tracker['current_index']] ?? null;
        if ($list === null) {
            return ['completed' => 0, 'total' => 0, 'import_tracker' => $tracker, 'has_more' => false, 'message' => __('The import is done.', 'anypress-client')];
        }

        try {
            $page = $this->api((array) ($settings['credential'] ?? []))->contacts->getContactsFromList(
                (int) $list,
                new GetContactsFromListRequest(['limit' => self::PER_PAGE, 'offset' => (int) $tracker['offset']])
            );
        } catch (\Throwable $e) {
            return new \WP_Error('api_error', $e->getMessage());
        }

        $field_maps = (array) ($settings['contact_fields'] ?? []);
        $local_list = (int) ($settings['map_settings']['local_list_id'] ?? 0);
        foreach ($page?->contacts ?? [] as $remote) {
            $email = sanitize_email((string) $remote->email);
            if (!is_email($email)) {
                continue;
            }
            $data = self::contact(
                $email,
                (array) $remote->attributes->getAdditionalProperties(),
                (bool) $remote->emailBlacklisted,
                (string) $remote->createdAt,
                $this->getMergedData((array) $remote->attributes->getAdditionalProperties(), $field_maps)
            );
            $data['tags'] = [$tags[$list]];
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

        $done = (int) $tracker['offset'] + self::PER_PAGE;
        $tracker = $done < (int) ($page?->count ?? 0)
            ? ['current_index' => (int) $tracker['current_index'], 'offset' => $done]
            : ['current_index' => (int) $tracker['current_index'] + 1, 'offset' => 0];

        return [
            'completed' => 0,
            'total' => 0,
            'import_tracker' => $tracker,
            'has_more' => isset($lists[$tracker['current_index']]),
            /* translators: %d: number of Brevo lists imported so far */
            'message' => sprintf(__('The import is running. %d lists have been imported so far.', 'anypress-client'), $tracker['current_index']),
        ];
    }

    /**
     * The FluentCRM contact for a Brevo one: its email, mapped fields, sign-up date and status.
     *
     * @param array<string, mixed> $attributes Brevo's attributes (FIRSTNAME, LASTNAME, SMS, ...).
     * @param array<string, mixed> $mapped     getMergedData() of the attributes and the chosen field map.
     */
    public static function contact(string $email, array $attributes, bool $blocklisted, string $created, array $mapped): array
    {
        $data = [
            'email' => $email,
            'first_name' => sanitize_text_field((string) ($attributes['FIRSTNAME'] ?? $attributes['PRENOM'] ?? '')),
            'last_name' => sanitize_text_field((string) ($attributes['LASTNAME'] ?? $attributes['NOM'] ?? '')),
            'source' => 'Brevo',
            'status' => $blocklisted ? 'unsubscribed' : 'subscribed',
        ];
        $time = strtotime($created);
        if ($time) {
            $data['created_at'] = gmdate('Y-m-d H:i:s', $time);
        }

        return array_filter(array_merge($data, $mapped), fn ($value) => $value !== '' && $value !== null && $value !== []);
    }

    /** @return array<int, \Brevo\Contacts\Types\GetListsResponseListsItem> Every list, 50 at a time (Brevo's maximum). */
    private function lists(Brevo $api): array
    {
        $all = [];
        $offset = 0;
        do {
            $page = $api->contacts->getLists(new GetListsRequest(['limit' => 50, 'offset' => $offset]));
            $batch = (array) ($page?->lists ?? []);
            $all = array_merge($all, $batch);
            $offset += 50;
            $full = count($batch) === 50;
        } while ($full && $offset < (int) ($page?->count ?? 0));

        return $all;
    }

    private function api(array $credential): Brevo
    {
        return new Brevo(apiKey: trim((string) ($credential['api_key'] ?? '')), options: ['client' => new WpHttpClient()]);
    }
}

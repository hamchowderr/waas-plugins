<?php

namespace AnyPressClient\Crm;

use AnyPressClient\Core\Brand;
use AnyPressClient\Core\SignedRequest;
use AnyPressClient\Features\EditorAssistant;
use AnyPressClient\Features\PluginBootstrap;

/**
 * WP Fusion on a HighLevel agency's sites, connected to the site's own HighLevel sub-account with nobody pressing
 * Connect: the site asks the AnyPress API (signed with its site key) which sub-account it belongs to and for a token
 * for it, which comes from the agency's install of the AnyPress app and lasts about a day. WP Fusion reads the token
 * and the sub-account through its own setting filters, and the site asks again before the token ends.
 *
 * Only on stores of the "highlevel" site type with a site key. WP Fusion set up for another CRM by the owner is
 * left alone. When the API can't be reached the site keeps working: WP Fusion's calls wait for the next check.
 */
class HighLevelLink
{
    public const SITE_TYPE = 'highlevel';
    /** The tag every user of the site gets in HighLevel. */
    public const USER_TAG = 'website user';
    public const API_ROUTE = '/apps/site/crm-link';
    /** {location_id, token, expires} from the API's last good answer. */
    public const OPTION = 'anypress_highlevel_link';
    /** Unix time before which no new check is made after a failed or empty one. */
    public const RETRY_OPTION = 'anypress_highlevel_link_retry';
    public const CHECK_EVENT = 'anypress_highlevel_link_check';
    public const SYNC_EVENT = 'anypress_highlevel_link_sync';
    /** A token this close to its end is replaced. */
    public const RENEW_BEFORE = 3 * 3600;
    /** Wait after a failed check; a site with no sub-account yet waits RETRY_UNLINKED. */
    public const RETRY_FAILED = 15 * 60;
    public const RETRY_UNLINKED = 3600;
    /**
     * Lead source details WP Fusion keeps from a visitor's first visit (its lead source tracking), sent to the
     * sub-account's custom field of the same name when it has one (utm_source goes to HighLevel's own Source).
     */
    public const LEAD_FIELDS = ['utm_campaign', 'utm_medium', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'original_ref', 'landing_page'];

    /** @var callable(string, array): (array|\WP_Error) */
    private $post;

    public function __construct(?callable $post = null)
    {
        $this->post = $post ?? 'wp_remote_post';
        if (!self::applies()) {
            return;
        }
        add_action('init', [$this, 'schedule']);
        add_action(self::CHECK_EVENT, [$this, 'check']);
        add_action(self::SYNC_EVENT, [$this, 'sync']);
        add_filter('wpf_get_setting_highlevel_token', [$this, 'token']);
        add_filter('wpf_get_setting_highlevel_location_id', [$this, 'location_id']);
        // On a reseller's site, WP Fusion and HighLevel go by the reseller's brand in wp-admin.
        if (Brand::reseller() !== '') {
            add_action('wp_fusion_init_crm', [$this, 'brand_crm']);
            if (is_admin()) {
                add_filter('gettext', [$this, 'brand_text'], 10, 3);
            }
        }
    }

    /** A store of the HighLevel site type, with a site key to sign with. */
    public static function applies(): bool
    {
        if (!PluginBootstrap::is_store() || SignedRequest::key() === '') {
            return false;
        }
        $roles = (string) PluginBootstrap::constant(PluginBootstrap::TENANT_ROLES_CONSTANT);

        return in_array(self::SITE_TYPE, array_map('trim', explode(',', $roles)), true);
    }

    public function schedule(): void
    {
        if (!wp_next_scheduled(self::CHECK_EVENT)) {
            wp_schedule_event(time(), 'hourly', self::CHECK_EVENT);
        }
    }

    /** WP Fusion's HighLevel token: the API's, renewed first when it is about to end. */
    public function token($value)
    {
        if (!$this->ours()) {
            return $value;
        }
        $link = $this->link();
        if ($link && $link['expires'] - time() < self::RENEW_BEFORE) {
            $link = $this->check() ?? $link;
        }

        return $link && $link['expires'] > time() ? $link['token'] : $value;
    }

    public function location_id($value)
    {
        $link = $this->ours() ? $this->link() : null;

        return $link ? $link['location_id'] : $value;
    }

    /**
     * Asks the API for the sub-account and a token when the one kept is about to end (or there is none), keeps the
     * answer, and connects WP Fusion the first time. Returns the link kept, or null.
     */
    public function check(): ?array
    {
        $link = $this->link();
        if ($link && $link['expires'] - time() >= self::RENEW_BEFORE) {
            return $link;
        }
        if ((int) get_option(self::RETRY_OPTION, 0) > time()) {
            return $link;
        }

        $answer = $this->ask();
        if ($answer === null) {
            update_option(self::RETRY_OPTION, time() + self::RETRY_FAILED, false);
            return $link;
        }
        if (($answer['crm'] ?? null) !== 'highlevel') {
            // No sub-account (any more): nothing to connect. WP Fusion's settings are left as the owner has them.
            delete_option(self::OPTION);
            update_option(self::RETRY_OPTION, time() + self::RETRY_UNLINKED, false);
            return null;
        }
        $expires = strtotime((string) ($answer['expiresAt'] ?? ''));
        $location = sanitize_text_field((string) ($answer['locationId'] ?? ''));
        $token = (string) ($answer['accessToken'] ?? '');
        if (!$expires || $location === '' || $token === '' || preg_match('/\s/', $token)) {
            update_option(self::RETRY_OPTION, time() + self::RETRY_FAILED, false);
            return $link;
        }
        $link = ['location_id' => $location, 'token' => $token, 'expires' => $expires];
        update_option(self::OPTION, $link, false);
        delete_option(self::RETRY_OPTION);
        $this->connect($location);

        return $link;
    }

    /**
     * WP Fusion set to HighLevel for this sub-account, as its Connect button leaves it, with new users sent as
     * contacts and the default field map. Once: an owner who set WP Fusion up for another CRM keeps it.
     */
    public function connect(string $location): void
    {
        if (!function_exists('wp_fusion') || !function_exists('wpf_get_option')) {
            return;
        }
        if (wpf_get_option('connection_configured')) {
            return;
        }
        $fields = (array) wpf_get_option('contact_fields', []);
        foreach ([
            'user_email' => ['email', 'Email', true],
            'first_name' => ['firstName', 'First Name', true],
            'last_name' => ['lastName', 'Last Name', true],
            'phone_number' => ['phone', 'Phone Number', false],
            'billing_address_1' => ['address1', 'Address', false],
            'billing_city' => ['city', 'City', false],
            'billing_state' => ['state', 'State', false],
            'billing_postcode' => ['postalCode', 'Postal Code', false],
            'billing_country' => ['country', 'Country', false],
            // Lead source tracking: where the visitor first came from.
            'utm_source' => ['source', 'Source', true],
        ] as $meta => [$crmField, $label, $active]) {
            $fields[$meta] = array_merge((array) ($fields[$meta] ?? []), ['crm_field' => $crmField, 'crm_label' => $label, 'active' => $active]);
        }
        wp_fusion()->settings->set_multiple([
            'crm' => 'highlevel',
            'connection_configured' => true,
            'highlevel_location_id' => $location,
            'create_users' => true,
            // Tagged so an agency's workflow can start on "Contact Tag added: website user".
            'assign_tags' => [self::USER_TAG],
            'push' => true,
            'contact_fields' => $fields,
        ]);
        // WP Fusion loads its HighLevel connector on the next request; the tag and field lists are fetched there.
        wp_schedule_single_event(time() + 30, self::SYNC_EVENT);
    }

    /**
     * Loads the sub-account's tags and custom fields into WP Fusion, as its Refresh button does, then maps the lead
     * source details to the sub-account's custom fields named after them (utm_campaign, "UTM Campaign"...).
     */
    public function sync(): void
    {
        if (!$this->ours() || !function_exists('wp_fusion') || !isset(wp_fusion()->crm) || !is_callable([wp_fusion()->crm, 'sync'])) {
            return;
        }
        $result = wp_fusion()->crm->sync();
        if (is_wp_error($result)) {
            error_log('AnyPress Client: WP Fusion HighLevel sync: ' . $result->get_error_message()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the store's log is where this is looked into.
            return;
        }
        $custom = (array) (((array) wpf_get_option('crm_fields', []))['Custom Fields'] ?? []);
        $fields = (array) wpf_get_option('contact_fields', []);
        $changed = false;
        foreach (self::LEAD_FIELDS as $meta) {
            if (!empty($fields[$meta]['crm_field'])) {
                continue;
            }
            foreach ($custom as $id => $label) {
                if (self::key((string) $label) === $meta) {
                    $fields[$meta] = array_merge((array) ($fields[$meta] ?? []), ['crm_field' => (string) $id, 'crm_label' => (string) $label, 'active' => true]);
                    $changed = true;
                    break;
                }
            }
        }
        if ($changed) {
            wp_fusion()->settings->set('contact_fields', $fields);
        }
    }

    /** "UTM Campaign", "utm-campaign" and "utm_campaign" all read as utm_campaign. */
    private static function key(string $label): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
    }

    /** HighLevel is called "<brand> CRM" in WP Fusion on a reseller's site. */
    public function brand_crm($crm): void
    {
        if (is_object($crm) && ($crm->slug ?? '') === 'highlevel') {
            /* translators: %s: the reseller's brand */
            $crm->name = sprintf(__('%s CRM', 'anypress-client'), Brand::reseller());
        }
    }

    /** "WP Fusion" reads "<brand> Contact Sync" in wp-admin on a reseller's site (WP Fusion Lite's own text only). */
    public function brand_text($translation, $text, $domain)
    {
        if ($domain !== 'wp-fusion-lite' || !is_string($translation) || strpos($translation, 'WP Fusion') === false) {
            return $translation;
        }

        /* translators: %s: the reseller's brand */
        return str_replace('WP Fusion', sprintf(__('%s Contact Sync', 'anypress-client'), Brand::reseller()), $translation);
    }

    /** WP Fusion is set to HighLevel and this site has a link: the token and sub-account are ours to give. */
    private function ours(): bool
    {
        return function_exists('wp_fusion') && function_exists('wpf_get_option') && wpf_get_option('crm') === 'highlevel' && (bool) get_option(self::OPTION);
    }

    /** @return array{location_id: string, token: string, expires: int}|null */
    private function link(): ?array
    {
        $link = get_option(self::OPTION);

        return is_array($link) && isset($link['location_id'], $link['token'], $link['expires']) ? $link : null;
    }

    /** The API's answer, or null when it couldn't be had. */
    private function ask(): ?array
    {
        $body = (string) wp_json_encode(['site' => strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))]);
        $response = ($this->post)(self::api_url() . self::API_ROUTE, [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-AnyPress-Signature' => SignedRequest::sign(SignedRequest::key(), 'POST', self::API_ROUTE, $body),
            ],
            'body' => $body,
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $answer = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($answer) ? $answer : null;
    }

    private static function api_url(): string
    {
        return EditorAssistant::api_url();
    }
}

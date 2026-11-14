<?php

namespace AnyPressHost\Api;

use AnyPressHost\Features\PluginBootstrap;
use AnyPressHost\Features\Webhooks;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/anypress-host/v1/prospects: the AnyPress API adds the businesses a seller picked in the Sales Kit's
 * prospect finder to this storefront's FluentCRM ("Add to CRM", for a storefront that runs on its own website).
 *
 * Signed like the API's webhooks (Standard Webhooks, with the secret Webhooks registered), so only the API this
 * storefront is connected to can call it, and each signed request works once. Contacts go in through FluentCRM's own
 * PHP API, tagged "Prospect" and "Prospect: <group>", with a note of what the finder found. A new contact is
 * "transactional", so no newsletter or campaign reaches a business that never signed up; an existing contact keeps its
 * status and the fields it already has.
 *
 * FluentCRM never emails a prospect from here either: a contact tagged "Prospect" who has not subscribed is skipped by
 * FluentCRM's sending (automations and single emails included). The seller writes to prospects from their own mailbox.
 * Receipts and account emails from the shop are not FluentCRM emails, so they still reach a prospect who buys.
 *
 * Body: {"contacts": [{"id", "name", "email", "phone"?, "address"?, "type"?, "website"?, "contactPage"?, "mapsUrl"?,
 * "rating"?, "reviews"?, "hours"?: string[], "socials"?: [{"network", "url"}], "demoUrl"?, "websiteScore"?,
 * "websitePlatform"?, "tags": string[]}]}.
 * Answer: {"added", "updated", "failed": {<id>: <why>}}.
 */
class ProspectsReceiver
{
    public const ROUTE = '/prospects';
    /** Contacts one call takes (the API sends 50 at a time). */
    public const MAX_CONTACTS = 50;
    /** Request ids already used. */
    public const SEEN_TRANSIENT = 'anypress_prospects_seen_';
    /** The tag every prospect gets, and that keeps FluentCRM from emailing them. */
    public const TAG = 'Prospect';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('fluent_crm/is_simulated_mail', [__CLASS__, 'skip_prospect_mail'], 10, 2);
    }

    public function register_routes(): void
    {
        register_rest_route(PluginBootstrap::API_V1_NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            // The signature is the credential, checked in the callback.
            'permission_callback' => '__return_true',
        ]);
    }

    /** Whether FluentCRM is active with the parts used here. */
    public static function available(): bool
    {
        return function_exists('FluentCrmApi')
            && class_exists('\FluentCrm\App\Models\Tag')
            && class_exists('\FluentCrm\App\Models\SubscriberNote');
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $body = (string) $request->get_body();
        if (
            !WebhookReceiver::verify(
                Webhooks::secret(),
                (string) $request->get_header('webhook-id'),
                (string) $request->get_header('webhook-timestamp'),
                (string) $request->get_header('webhook-signature'),
                $body,
                time()
            )
        ) {
            return self::error('invalid_signature', 'Missing, stale or invalid signature.', 401);
        }
        $seen = self::SEEN_TRANSIENT . md5((string) $request->get_header('webhook-id'));
        if (get_transient($seen)) {
            return self::error('replayed', 'This request was already used.', 409);
        }
        set_transient($seen, 1, 2 * WebhookReceiver::TOLERANCE);
        if (!self::available()) {
            return self::error('no_crm', 'FluentCRM is not active on this storefront.', 503);
        }

        $data = json_decode($body, true);
        $contacts = is_array($data) && isset($data['contacts']) && is_array($data['contacts']) ? array_values($data['contacts']) : null;
        if ($contacts === null || count($contacts) > self::MAX_CONTACTS) {
            return self::error('invalid_body', sprintf('Expected {"contacts": [...]} with at most %d contacts.', self::MAX_CONTACTS), 400);
        }

        $out = ['added' => 0, 'updated' => 0, 'failed' => []];
        $tag_ids = [];
        foreach ($contacts as $contact) {
            $contact = is_array($contact) ? $contact : [];
            $id = sanitize_text_field((string) ($contact['id'] ?? ''));
            try {
                $out[self::add($contact, $tag_ids) ? 'updated' : 'added']++;
            } catch (\Throwable $e) {
                $out['failed'][$id] = mb_substr((string) preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200);
            }
        }
        $out['failed'] = (object) $out['failed'];
        return new WP_REST_Response($out, 200);
    }

    /**
     * Adds or updates one business. True when the contact was already there.
     *
     * @param array<string, mixed> $c
     * @param array<string, int>   $tag_ids Tags made or found so far, by title.
     */
    private static function add(array $c, array &$tag_ids): bool
    {
        $email = sanitize_email((string) ($c['email'] ?? ''));
        if (!is_email($email)) {
            throw new \RuntimeException('no usable email');
        }
        $tags = array_values(array_filter(array_map(
            static fn ($t) => mb_substr(sanitize_text_field((string) $t), 0, 190),
            array_merge([self::TAG], (array) ($c['tags'] ?? []))
        )));
        $api = FluentCrmApi('contacts');
        $existing = $api->getContact($email);
        $fields = [
            'email' => $email,
            'first_name' => mb_substr(sanitize_text_field((string) ($c['name'] ?? '')), 0, 190),
            'phone' => mb_substr(sanitize_text_field((string) ($c['phone'] ?? '')), 0, 50),
            'address_line_1' => mb_substr(sanitize_text_field((string) ($c['address'] ?? '')), 0, 190),
            'source' => 'Prospect finder',
            'contact_type' => 'lead',
            'tags' => array_map(static fn ($t) => self::tag_id($t, $tag_ids), array_unique($tags)),
        ];
        if (!$existing) {
            $fields['status'] = 'transactional';
        } else {
            // Fields a contact already has are kept: the seller may have corrected them.
            foreach (['first_name', 'phone', 'address_line_1', 'source', 'contact_type'] as $key) {
                if ((string) $existing->{$key} !== '') {
                    unset($fields[$key]);
                }
            }
        }
        $saved = $api->createOrUpdate(array_filter($fields, static fn ($v) => $v !== '' && $v !== []));
        if (!$saved) {
            throw new \RuntimeException('FluentCRM did not take the contact');
        }
        \FluentCrm\App\Models\SubscriberNote::create([
            'subscriber_id' => $saved->id,
            'type' => 'note',
            'title' => 'Found with the prospect finder',
            'description' => self::note($c),
            'created_by' => 0,
            'created_at' => current_time('mysql'),
        ]);
        return (bool) $existing;
    }

    /** @param array<string, int> $tag_ids */
    private static function tag_id(string $title, array &$tag_ids): int
    {
        if (!isset($tag_ids[$title])) {
            $tag = \FluentCrm\App\Models\Tag::where('title', $title)->first();
            if (!$tag) {
                $tag = \FluentCrm\App\Models\Tag::create(['title' => $title, 'slug' => sanitize_title($title)]);
            }
            $tag_ids[$title] = (int) $tag->id;
        }
        return $tag_ids[$title];
    }

    /**
     * What the finder found, as the note's list.
     *
     * @param array<string, mixed> $c
     */
    public static function note(array $c): string
    {
        $rows = [];
        $links = ['Type' => 'type', 'Website' => 'website', 'Contact page' => 'contactPage', 'Google Maps' => 'mapsUrl'];
        foreach ($links as $label => $key) {
            $value = sanitize_text_field((string) ($c[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $rows[] = '<li>' . esc_html($label) . ': ' . (preg_match('#^https?://#', $value)
                ? '<a href="' . esc_url($value) . '">' . esc_html($value) . '</a>'
                : esc_html($value)) . '</li>';
        }
        if (isset($c['rating']) && is_numeric($c['rating'])) {
            $reviews = isset($c['reviews']) && is_numeric($c['reviews']) ? ' (' . esc_html((string) (int) $c['reviews']) . ' reviews)' : '';
            $rows[] = '<li>Google rating: ' . esc_html((string) (float) $c['rating']) . $reviews . '</li>';
        }
        foreach ((array) ($c['socials'] ?? []) as $social) {
            $url = is_array($social) ? (string) ($social['url'] ?? '') : '';
            if (!preg_match('#^https?://#', $url)) {
                continue;
            }
            $rows[] = '<li>' . esc_html(ucfirst(sanitize_text_field((string) ($social['network'] ?? '')))) . ': <a href="' . esc_url($url) . '">' . esc_html($url) . '</a></li>';
        }
        // What the storefront learned reading the business's website, when it did.
        $demo = (string) ($c['demoUrl'] ?? '');
        if (preg_match('#^https?://#', $demo)) {
            $rows[] = '<li>Their demo: <a href="' . esc_url($demo) . '">' . esc_html($demo) . '</a></li>';
        }
        if (isset($c['websiteScore']) && is_numeric($c['websiteScore'])) {
            $rows[] = '<li>Website check: ' . esc_html((string) (int) $c['websiteScore']) . ' of 100</li>';
        }
        $platform = sanitize_text_field((string) ($c['websitePlatform'] ?? ''));
        if ($platform !== '') {
            $rows[] = '<li>Website runs on: ' . esc_html($platform) . '</li>';
        }
        $hours = array_filter(array_map(static fn ($h) => esc_html(sanitize_text_field((string) $h)), (array) ($c['hours'] ?? [])));
        if ($hours) {
            $rows[] = '<li>Hours:<br>' . implode('<br>', $hours) . '</li>';
        }
        return '<ul>' . implode('', $rows) . '</ul>';
    }

    /**
     * FluentCRM's "is this a test send" filter: true skips the email. Used for contacts tagged "Prospect" who have not
     * subscribed, so FluentCRM never emails a business that only came from the prospect finder. Runs only for
     * FluentCRM's own emails.
     *
     * @param mixed $simulated
     * @param mixed $data
     * @return mixed
     */
    public static function skip_prospect_mail($simulated, $data)
    {
        if ($simulated || !is_array($data) || !class_exists('\FluentCrm\App\Models\Subscriber')) {
            return $simulated;
        }
        $email = sanitize_email((string) ($data['to']['email'] ?? ''));
        if ($email === '') {
            return $simulated;
        }
        $contact = \FluentCrm\App\Models\Subscriber::where('email', $email)->first();
        if (!$contact || $contact->status === 'subscribed') {
            return $simulated;
        }
        if (!$contact->tags()->where('title', self::TAG)->exists()) {
            return $simulated;
        }
        error_log('AnyPress Host: FluentCRM email to a prospect skipped (contact ' . (int) $contact->id . ').');
        return true;
    }

    private static function error(string $code, string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['code' => $code, 'message' => $message], $status);
    }
}

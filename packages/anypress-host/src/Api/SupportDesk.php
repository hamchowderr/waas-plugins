<?php

namespace AnyPressHost\Api;

use AnyPressHost\Features\PluginBootstrap;
use AnyPressHost\Features\Webhooks;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/anypress-host/v1/support: the AnyPress API files and reads support tickets in this storefront's Fluent
 * Support for the people it signs in (store owners, sellers and resellers asking from an AI app or the dashboard).
 *
 * Signed like the API's webhooks (Standard Webhooks, with the secret Webhooks registered), so only the API this
 * storefront is connected to can call it, and every call names the customer it acts for by email. A customer only ever
 * sees and changes their own tickets. The work goes through Fluent Support's own PHP API and services, so its emails,
 * activity log and automations run as they do for a ticket from the portal.
 *
 * Body: {"action": "open"|"list"|"get"|"reply"|"close", "customer": {"email", "name"}, "ticketId"?, "title"?, "content"?}.
 */
class SupportDesk
{
    public const ROUTE = '/support';
    /** Tickets `list` returns, newest first. */
    public const LIST_LIMIT = 20;
    /** Characters kept of a title, and of a message. */
    public const MAX_TITLE = 200;
    public const MAX_CONTENT = 20000;
    /** Set once the Support page exists (or Fluent Support already had a portal page). */
    public const PAGE_OPTION = 'anypress_support_page';
    /** Request ids already used. */
    public const SEEN_TRANSIENT = 'anypress_support_seen_';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_init', [__CLASS__, 'maybe_create_page']);
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

    /** Whether Fluent Support is active with the parts used here. */
    public static function available(): bool
    {
        return function_exists('FluentSupportApi')
            && class_exists('\FluentSupport\App\Models\Ticket')
            && class_exists('\FluentSupport\App\Models\Customer');
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
        // A signed request is used once: the same one again within its five minutes does nothing.
        $seen = self::SEEN_TRANSIENT . md5((string) $request->get_header('webhook-id'));
        if (get_transient($seen)) {
            return self::error('replayed', 'This request was already used.', 409);
        }
        set_transient($seen, 1, 2 * WebhookReceiver::TOLERANCE);
        if (!self::available()) {
            return self::error('no_desk', 'Fluent Support is not active on this storefront.', 503);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return self::error('invalid_body', 'Expected a JSON object.', 400);
        }
        $action = isset($data['action']) && is_string($data['action']) ? $data['action'] : '';
        $email = isset($data['customer']['email']) ? sanitize_email((string) $data['customer']['email']) : '';
        $name = isset($data['customer']['name']) ? sanitize_text_field((string) $data['customer']['name']) : '';
        if (!$email || !is_email($email)) {
            return self::error('invalid_customer', 'customer.email must be an email address.', 400);
        }

        try {
            switch ($action) {
                case 'open':
                    return $this->open($email, $name, $data);
                case 'list':
                    return $this->list_tickets($email);
                case 'get':
                    return $this->get_ticket($email, self::ticket_id($data));
                case 'reply':
                    return $this->reply($email, self::ticket_id($data), $data);
                case 'close':
                    return $this->close($email, self::ticket_id($data));
                default:
                    return self::error('invalid_action', 'action must be open, list, get, reply or close.', 400);
            }
        } catch (\Throwable $e) {
            error_log('AnyPress Host support desk: ' . $e->getMessage());
            return self::error('desk_error', 'Fluent Support could not do that just now.', 500);
        }
    }

    private function open(string $email, string $name, array $data): WP_REST_Response
    {
        $title = self::clip(sanitize_text_field((string) ($data['title'] ?? '')), self::MAX_TITLE);
        $content = self::message((string) ($data['content'] ?? ''));
        if ($title === '' || $content === '') {
            return self::error('invalid_ticket', 'title and content are required.', 400);
        }

        $customer = $this->customer($email, $name, true);
        if (!$customer) {
            return self::error('invalid_customer', 'Fluent Support could not add this customer.', 400);
        }
        if (method_exists($customer, 'isBlocked') && $customer->isBlocked()) {
            return self::error('blocked', 'This customer may not open tickets.', 403);
        }

        $ticket = FluentSupportApi('tickets')->createTicket([
            'customer_id' => $customer->id,
            'title' => $title,
            'content' => $content,
            'source' => 'web',
        ]);
        if (!$ticket) {
            return self::error('invalid_ticket', 'Fluent Support did not take the ticket.', 400);
        }

        return new WP_REST_Response(['ticket' => self::ticket_view($ticket, true)], 201);
    }

    private function list_tickets(string $email): WP_REST_Response
    {
        $customer = $this->customer($email, '', false);
        if (!$customer) {
            return new WP_REST_Response(['tickets' => []], 200);
        }
        $tickets = \FluentSupport\App\Models\Ticket::where('customer_id', $customer->id)
            ->orderBy('id', 'DESC')
            ->limit(self::LIST_LIMIT)
            ->get();

        $out = [];
        foreach ($tickets as $ticket) {
            $out[] = self::ticket_view($ticket, false);
        }
        return new WP_REST_Response(['tickets' => $out], 200);
    }

    private function get_ticket(string $email, int $ticket_id): WP_REST_Response
    {
        $ticket = $this->own_ticket($email, $ticket_id);
        if (!$ticket) {
            return self::not_found();
        }
        return new WP_REST_Response(['ticket' => self::ticket_view($ticket, true)], 200);
    }

    private function reply(string $email, int $ticket_id, array $data): WP_REST_Response
    {
        $content = self::message((string) ($data['content'] ?? ''));
        if ($content === '') {
            return self::error('invalid_reply', 'content is required.', 400);
        }
        $customer = $this->customer($email, '', false);
        $ticket = $this->own_ticket($email, $ticket_id);
        if (!$customer || !$ticket) {
            return self::not_found();
        }

        // A reply opens a closed ticket again, as a reply from the portal or by email does.
        if ($ticket->status === 'closed') {
            (new \FluentSupport\App\Services\Tickets\TicketService())->reopen($ticket, $customer);
        }
        $created = (new \FluentSupport\App\Services\Tickets\ResponseService())->createResponse(
            ['content' => $content, 'conversation_type' => 'response'],
            $customer,
            $ticket
        );
        if (!$created) {
            return self::error('invalid_reply', 'Fluent Support did not take the reply.', 400);
        }

        return new WP_REST_Response(['ticket' => self::ticket_view(\FluentSupport\App\Models\Ticket::find($ticket->id), true)], 200);
    }

    private function close(string $email, int $ticket_id): WP_REST_Response
    {
        $customer = $this->customer($email, '', false);
        $ticket = $this->own_ticket($email, $ticket_id);
        if (!$customer || !$ticket) {
            return self::not_found();
        }
        if ($ticket->status !== 'closed') {
            (new \FluentSupport\App\Services\Tickets\TicketService())->close($ticket, $customer);
        }
        return new WP_REST_Response(['ticket' => self::ticket_view(\FluentSupport\App\Models\Ticket::find($ticket->id), false)], 200);
    }

    /** The Fluent Support customer for this email; made (with the name) when $create is set. */
    private function customer(string $email, string $name, bool $create)
    {
        $existing = \FluentSupport\App\Models\Customer::where('email', $email)->first();
        if ($existing || !$create) {
            return $existing ?: null;
        }
        $parts = preg_split('/\s+/', trim($name), 2);
        $customer = \FluentSupport\App\Models\Customer::maybeCreateCustomer([
            'email' => $email,
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
        ]);
        return $customer ?: null;
    }

    /** One of this customer's own tickets, or null: someone else's ticket is the same as no ticket. */
    private function own_ticket(string $email, int $ticket_id)
    {
        $customer = $this->customer($email, '', false);
        if (!$customer || $ticket_id <= 0) {
            return null;
        }
        return \FluentSupport\App\Models\Ticket::where('customer_id', $customer->id)->where('id', $ticket_id)->first();
    }

    /** What the API gets of a ticket: never agents' internal notes, emails or anything else of Fluent Support's. */
    public static function ticket_view($ticket, bool $with_messages): array
    {
        $view = [
            'id' => (int) $ticket->id,
            'title' => (string) $ticket->title,
            'status' => (string) $ticket->status,
            'createdAt' => self::iso($ticket->created_at ?? null),
            'updatedAt' => self::iso($ticket->updated_at ?? null),
            'url' => self::ticket_url($ticket),
        ];
        if (!$with_messages) {
            return $view;
        }

        $messages = [['from' => 'you', 'content' => self::plain((string) $ticket->content), 'createdAt' => $view['createdAt']]];
        $replies = \FluentSupport\App\Models\Conversation::where('ticket_id', $ticket->id)
            ->where('conversation_type', 'response')
            ->orderBy('id', 'ASC')
            ->get();
        foreach ($replies as $reply) {
            $messages[] = [
                'from' => (int) $reply->person_id === (int) $ticket->customer_id ? 'you' : 'support',
                'content' => self::plain((string) $reply->content),
                'createdAt' => self::iso($reply->created_at ?? null),
            ];
        }
        $view['messages'] = $messages;
        return $view;
    }

    /** The ticket in the customer portal, when there is one. */
    private static function ticket_url($ticket): ?string
    {
        $helper = '\FluentSupport\App\Services\Helper';
        if (!class_exists($helper) || !method_exists($helper, 'getTicketViewSignedUrl')) {
            return null;
        }
        $url = (string) $helper::getTicketViewSignedUrl($ticket);
        return preg_match('#^https?://[^/]+/.+#', $url) ? $url : null;
    }

    /**
     * Once, while an admin is in wp-admin: a Support page with Fluent Support's customer portal, made its portal page,
     * unless Fluent Support already has one. Never again after that, even if the page is removed.
     */
    public static function maybe_create_page(): void
    {
        if (get_option(self::PAGE_OPTION) || !self::available() || !current_user_can('manage_options')) {
            return;
        }
        $helper = '\FluentSupport\App\Services\Helper';
        if (!class_exists($helper) || !method_exists($helper, 'getOption') || !method_exists($helper, 'updateOption')) {
            return;
        }
        $settings = $helper::getOption('global_business_settings', []);
        $settings = is_array($settings) ? $settings : [];
        if (!empty($settings['portal_page_id']) && get_post_status((int) $settings['portal_page_id']) === 'publish') {
            update_option(self::PAGE_OPTION, (int) $settings['portal_page_id'], true);
            return;
        }

        $page_id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => __('Support', 'anypress-host'),
            'post_name' => 'support',
            'post_content' => '<!-- wp:shortcode -->[fluent_support_portal]<!-- /wp:shortcode -->',
        ]);
        if (!$page_id || is_wp_error($page_id)) {
            return;
        }
        $settings['portal_page_id'] = (int) $page_id;
        $helper::updateOption('global_business_settings', $settings);
        update_option(self::PAGE_OPTION, (int) $page_id, true);
    }

    private static function ticket_id(array $data): int
    {
        return isset($data['ticketId']) && is_numeric($data['ticketId']) ? (int) $data['ticketId'] : 0;
    }

    /** A message as Fluent Support stores it: paragraphs of escaped text. */
    public static function message(string $text): string
    {
        $text = trim(self::clip(str_replace("\r\n", "\n", $text), self::MAX_CONTENT));
        if ($text === '') {
            return '';
        }
        $paragraphs = preg_split("/\n{2,}/", $text);
        return implode('', array_map(static fn ($p) => '<p>' . str_replace("\n", '<br>', esc_html(trim($p))) . '</p>', $paragraphs));
    }

    /** A stored message as plain text, for an AI app to read. */
    public static function plain(string $html): string
    {
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</p>\s*#i', "\n\n", (string) $html);
        return trim(html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function clip(string $text, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    private static function iso($date): ?string
    {
        if (!$date) {
            return null;
        }
        // Fluent Support keeps the site's local time.
        $time = strtotime(get_gmt_from_date((string) $date) . ' UTC');
        return $time ? gmdate('c', $time) : null;
    }

    private static function not_found(): WP_REST_Response
    {
        return self::error('not_found', 'No such ticket for this customer.', 404);
    }

    private static function error(string $code, string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['code' => $code, 'message' => $message], $status);
    }
}

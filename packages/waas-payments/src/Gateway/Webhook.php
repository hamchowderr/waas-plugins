<?php

namespace WaaSPayments\Gateway;

/**
 * Receives Authorize.Net webhooks: a backstop that brings FluentCart in line with what
 * happened at Authorize.Net outside the normal flow (beads waas-hju.10). Payments and renewals
 * don't depend on it; they are confirmed synchronously.
 *
 * - A charge whose answer never reached the site, or one the merchant approved after
 *   Authorize.Net's fraud filters held it, marks its order paid.
 * - A refund or void made in the Authorize.Net Merchant Interface is recorded in FluentCart.
 *
 * Authorize.Net rejects webhook URLs with a query string, so this is a REST route
 * (/wp-json/waas-payments/v1/authorizenet/webhook, which needs pretty permalinks) rather than
 * FluentCart's IPN listener (?fluent-cart=fct_payment_listener_ipn&method=...).
 *
 * Every notification must carry the account's Signature Key signature. Even then the payload is
 * only taken as "look at transaction X": WebhookSync reads the transaction back from Authorize.Net
 * with the store's own credentials before changing anything.
 */
class Webhook
{
    const NAMESPACE = 'waas-payments/v1';
    const ROUTE = '/authorizenet/webhook';

    // The events WebhookSync acts on. Authorize.Net's names (GET /rest/v1/eventtypes).
    const EVENT_TYPES = [
        'net.authorize.payment.authcapture.created',
        'net.authorize.payment.fraud.approved',
        'net.authorize.payment.fraud.declined',
        'net.authorize.payment.refund.created',
        'net.authorize.payment.void.created',
    ];

    private $gateway;

    public function __construct(AuthorizeNetGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function register(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route(self::NAMESPACE, self::ROUTE, [
                'methods'             => 'POST',
                'callback'            => [$this, 'handleRequest'],
                // Authenticated by the signature, in handle().
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public static function url(): string
    {
        return rest_url(self::NAMESPACE . self::ROUTE);
    }

    /**
     * @param \WP_REST_Request $request
     */
    public function handleRequest($request)
    {
        $result = $this->handle((string) $request->get_body(), (string) $request->get_header('x-anet-signature'));

        return new \WP_REST_Response(['message' => $result['message']], $result['status']);
    }

    /**
     * @return array{status:int, message:string}
     */
    public function handle(string $body, string $signature): array
    {
        $settings = $this->gateway->settings;
        $current = $settings->getMode();
        $key = $settings->getSignatureKey($current);
        $otherMode = $current === 'test' ? 'live' : 'test';
        $otherKey = $settings->getSignatureKey($otherMode);

        if ($key === '' && $otherKey === '') {
            return self::result(401, 'No Signature Key is set up for Authorize.Net webhooks.');
        }

        if ($key === '' || !self::verifySignature($body, $signature, $key)) {
            if ($otherKey !== '' && self::verifySignature($body, $signature, $otherKey)) {
                // A genuine notification from the account the store isn't using now: nothing to sync.
                AuthorizeNetSettings::recordWebhook($otherMode);
                return self::result(200, 'Ignored: the store is not in ' . $otherMode . ' mode.');
            }
            return self::result(401, 'Invalid signature.');
        }

        AuthorizeNetSettings::recordWebhook($current);

        $notification = json_decode($body, true);
        $eventType = (string) ($notification['eventType'] ?? '');
        $payload = $notification['payload'] ?? [];
        $transactionId = (string) ($payload['id'] ?? '');

        if (!in_array($eventType, self::EVENT_TYPES, true) || ($payload['entityName'] ?? '') !== 'transaction' || $transactionId === '') {
            return self::result(200, 'Ignored.');
        }

        $outcome = (new WebhookSync($this->gateway))->sync($transactionId);

        if (is_wp_error($outcome)) {
            // Authorize.Net retries a delivery that doesn't get a 200.
            return self::result(503, $outcome->get_error_message());
        }

        return self::result(200, $outcome);
    }

    /**
     * X-ANET-Signature is "sha512=" and the HMAC-SHA512 of the body with the Signature Key, in
     * upper-case hex.
     */
    public static function verifySignature(string $body, string $header, string $key): bool
    {
        if (stripos($header, 'sha512=') !== 0) {
            return false;
        }

        return hash_equals(strtoupper(hash_hmac('sha512', $body, $key)), strtoupper(trim(substr($header, 7))));
    }

    private static function result(int $status, string $message): array
    {
        return ['status' => $status, 'message' => $message];
    }
}

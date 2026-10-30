<?php

namespace WaaSHost\Api;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\PluginBootstrap;
use WaaSHost\Features\TenantStatusService;
use WaaSHost\Features\Webhooks;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/waas-host/v1/webhook: the provisioning API's events (its docs/webhooks.md), signed per Standard
 * Webhooks with the secret Webhooks registered.
 *
 * The payload only says which store changed. Its state is read from the API again by the poller's own code
 * (TenantStatusService), so the HTTPS check, the Ready state and waas_tenant_ready happen exactly as when polling,
 * and an event that arrives twice, late or out of order does no harm.
 */
class WebhookReceiver
{
    /** Seconds a webhook-timestamp may be off. */
    public const TOLERANCE = 300;
    /** Event ids seen recently, so a retried delivery is answered without acting twice. */
    public const SEEN_TRANSIENT = 'waas_webhook_seen_';

    private TenantStatusService $statusService;

    public function __construct(TenantStatusService $statusService)
    {
        $this->statusService = $statusService;

        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(PluginBootstrap::API_V1_NAMESPACE, Webhooks::ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            // The signature is the credential, checked in the callback.
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * True when one of the space-separated `v1,<base64>` signatures is the HMAC-SHA256 of "<id>.<timestamp>.<body>"
     * keyed by the base64-decoded secret after `whsec_`, and the timestamp is within TOLERANCE of $now.
     */
    public static function verify(string $secret, string $id, string $timestamp, string $signatures, string $body, int $now): bool
    {
        if ($id === '' || !ctype_digit($timestamp) || abs($now - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }
        if (strpos($secret, 'whsec_') !== 0) {
            return false;
        }
        $key = base64_decode(substr($secret, strlen('whsec_')), true);
        if ($key === false || $key === '') {
            return false;
        }

        $expected = 'v1,' . base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, $key, true));
        foreach (preg_split('/\s+/', trim($signatures)) as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $id = (string) $request->get_header('webhook-id');
        $body = (string) $request->get_body();

        if (!self::verify(
            Webhooks::secret(),
            $id,
            (string) $request->get_header('webhook-timestamp'),
            (string) $request->get_header('webhook-signature'),
            $body,
            time()
        )) {
            return new WP_REST_Response(['code' => 'invalid_signature', 'message' => 'Missing, stale or invalid webhook signature.'], 401);
        }

        $seen = self::SEEN_TRANSIENT . md5($id);
        if (get_transient($seen)) {
            return new WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }

        $event = json_decode($body);
        if (!is_object($event) || !isset($event->type) || !is_string($event->type)) {
            return new WP_REST_Response(['code' => 'invalid_body', 'message' => 'Expected {"type", "timestamp", "data"}.'], 400);
        }

        Webhooks::record_event_received();
        $handled = $this->dispatch($event->type, isset($event->data) && is_object($event->data) ? $event->data : new \stdClass());

        // Only once it was dealt with: a delivery that failed here is retried by the API.
        set_transient($seen, 1, DAY_IN_SECONDS);

        return new WP_REST_Response(['received' => true, 'handled' => $handled], 200);
    }

    /** Acts on one event. False for events WaaS Host leaves alone, and for stores it doesn't have. */
    public function dispatch(string $type, object $data): bool
    {
        if (!in_array($type, Webhooks::EVENT_TYPES, true)) {
            return false;
        }

        $external_id = isset($data->tenant->externalId) ? (string) $data->tenant->externalId : '';
        $subscription_id = $this->find_subscription($external_id);
        if ($subscription_id === null) {
            return false;
        }

        switch ($type) {
            case 'tenant.deleted':
                $this->statusService->forget_if_gone($subscription_id);
                return true;
            case 'tenant.restored':
                $this->statusService->bring_back_if_restored($subscription_id);
                // And on, as for ready.
            case 'tenant.ready':
                // The store is answering: take it on towards Ready now. A certificate that isn't served yet gets
                // one more look in a few seconds; after that, the poll.
                if (in_array($this->statusService->process_subscription($subscription_id), TenantStatusService::IN_PROGRESS, true)) {
                    $this->statusService->recheck_soon($subscription_id);
                }
                return true;
            case 'tenant.domains_changed':
                $this->statusService->sync_tenant_domain($subscription_id);
                return true;
            case 'tenant.failed':
                Webhooks::record_tenant_failure($subscription_id, isset($data->action) ? sanitize_key((string) $data->action) : '');
                return true;
        }

        return false;
    }

    /** @return int|string|null the subscription whose store has $external_id */
    private function find_subscription(string $external_id)
    {
        $commerce = Commerce::adapter();
        if ($commerce === null || $external_id === '') {
            return null;
        }

        foreach ($commerce->find_subscriptions_by_meta(Tenant::TENANT_EXTERNAL_ID_META, ['']) as $subscription_id => $value) {
            if ((string) $value === $external_id) {
                return $subscription_id;
            }
        }

        return null;
    }
}

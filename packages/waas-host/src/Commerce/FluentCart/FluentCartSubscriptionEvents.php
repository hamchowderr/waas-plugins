<?php

namespace WaaSHost\Commerce\FluentCart;

/**
 * FluentCart subscription events that no product feed trigger covers, turned into waas-host's events:
 * - paused (an admin paused it, or PayPal suspended it): the store goes offline, without a backup or deletion;
 * - resumed (paused, then active again): the store comes back. FluentCart fires only "resumed" for that
 *   transition, not "reactivated";
 * - renewed (a renewal was paid): brings back a store waas-host took offline for a billing event, if the
 *   subscription is active again (see TenantsSubscriptionManager::resume_after_renewal).
 *
 * These hooks run in the request that changed the subscription (a webhook, an admin action, the renewal charge).
 */
class FluentCartSubscriptionEvents
{
    private FluentCartStore $store;

    public function __construct(FluentCartStore $store)
    {
        $this->store = $store;
    }

    public function register(): void
    {
        add_action('fluent_cart/subscription_paused', [$this, 'paused']);
        add_action('fluent_cart/subscription_resumed', [$this, 'resumed']);
        add_action('fluent_cart/subscription_renewed', [$this, 'renewed']);
    }

    /** @param array $data subscription, order, customer, old_status, reason */
    public function paused($data): void
    {
        $this->handle($data, 'waas_subscription_suspended', __('Website taken offline', WAAS_HOST_TEXTDOMAIN), 'subscription_paused');
    }

    /** @param array $data subscription, order, customer, old_status, reason */
    public function resumed($data): void
    {
        $this->handle($data, 'waas_subscription_reactivated', __('Website resumed', WAAS_HOST_TEXTDOMAIN), 'subscription_resumed');
    }

    /** @param array $data subscription, order (the renewal order), main_order, customer */
    public function renewed($data): void
    {
        $this->handle($data, 'waas_subscription_renewed', __('Website resumed', WAAS_HOST_TEXTDOMAIN), 'subscription_renewed');
    }

    private function handle($data, string $action, string $title, string $event): void
    {
        $subscription = is_array($data) ? ($data['subscription'] ?? null) : null;
        if (!$subscription || empty($subscription->id)) {
            return;
        }
        $subscription_id = (int) $subscription->id;

        WaasEventNotes::fire($this->store, $data['order'] ?? null, $subscription_id, $action, [], $title, sprintf('Subscription #%d (%s)', $subscription_id, $event));
    }
}

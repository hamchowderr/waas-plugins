<?php

namespace WaaSHost\Commerce;

use WaaSHost\Core\WPCSService;

/**
 * What the storefront needs from the cart it runs on. One adapter is active per
 * storefront (see Commerce). The shared code (store creation, status polling,
 * one-click login, notifications) only talks to the cart through this.
 *
 * Subscription ids are the cart's own ids; only the active adapter interprets them.
 */
interface CommerceAdapter
{
    /** Short identifier, e.g. "woocommerce". */
    public function get_id(): string;

    /** True when this cart is active on the site. */
    public function is_available(): bool;

    /**
     * Register the cart's own hooks: plan settings on products, the website-name
     * field at checkout, subscription events, and the customer page. Events:
     * wpcs_subscription_created (active; firing it again for a subscription that has a
     * store resumes or restores that store), wpcs_subscription_suspended (on hold),
     * wpcs_subscription_reactivated (active after a hold) and wpcs_subscription_expired
     * (cancelled or expired).
     */
    public function boot(WPCSService $wpcs_service): void;

    /**
     * What a new store needs from the purchase: its website-plan products (with the
     * role and snapshot group each grants, read from wherever this cart keeps product
     * settings), the website name and the customer's email. $purchase_context is
     * whatever the adapter passed as the second argument of wpcs_subscription_created.
     */
    public function get_plan_purchase($subscription_id, $purchase_context): PlanPurchase;

    public function get_subscription_meta($subscription_id, string $key);

    public function update_subscription_meta($subscription_id, string $key, $value): void;

    /**
     * Subscriptions that have $key set to a value not in $excluded_values.
     *
     * @return array<int|string, mixed> subscription id => meta value
     */
    public function find_subscriptions_by_meta(string $key, array $excluded_values): array;

    /** WordPress user id of the customer who owns the subscription, 0 if unknown. */
    public function get_customer_id($subscription_id): int;

    public function get_customer_email($subscription_id): string;

    /** Customer name used to derive the store's WordPress username. */
    public function get_customer_name($subscription_id): string;

    /** Customer-facing page for the subscription on the storefront. */
    public function get_subscription_url($subscription_id): string;
}

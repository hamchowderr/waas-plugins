<?php

namespace WaaSHost\Commerce\WooCommerce;

/**
 * A WooCommerce subscription plugin. The WooCommerce adapter uses exactly one (see WooCommerceAdapter): WP Swings'
 * free Subscriptions for WooCommerce by default, or WooCommerce Subscriptions.
 *
 * Both store subscriptions as WooCommerce orders of their own order type, so they are read and written through the
 * order API (wc_get_order, get_meta, update_meta_data, save), which works with and without High-Performance Order
 * Storage.
 */
interface SubscriptionPlugin
{
    /** Short identifier, e.g. "wp-swings". */
    public function get_id(): string;

    public function is_active(): bool;

    /** WooCommerce order type the plugin stores subscriptions as. */
    public function get_order_type(): string;

    /**
     * Translate the plugin's subscription events into SubscriptionEvents calls, and show the store's status and
     * login link on the customer's subscription page.
     */
    public function register_hooks(): void;

    /** The order the subscription was bought with, or null (e.g. a subscription an admin created by hand). */
    public function get_parent_order(\WC_Order $subscription): ?\WC_Order;

    /** WordPress user id of the subscription's current owner, 0 if unknown. */
    public function get_customer_id(\WC_Order $subscription): int;

    /** The subscription's page in My Account. */
    public function get_subscription_url(\WC_Order $subscription): string;

    /** True while the subscription is active (see CommerceAdapter::is_subscription_active). */
    public function is_subscription_active(\WC_Order $subscription): bool;
}

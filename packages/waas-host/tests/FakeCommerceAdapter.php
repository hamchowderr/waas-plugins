<?php

namespace Tests;

use WaaSHost\Commerce\CommerceAdapter;
use WaaSHost\Commerce\PlanPurchase;
use WaaSHost\Core\WPCSService;

/**
 * In-memory cart for testing the shared code without WooCommerce or FluentCart.
 */
class FakeCommerceAdapter implements CommerceAdapter
{
    public string $id;
    public bool $available;
    /** @var array<int|string, array<string, mixed>> subscription id => meta */
    public array $meta = [];
    public bool $booted = false;

    public function __construct(string $id = 'fake', bool $available = true)
    {
        $this->id = $id;
        $this->available = $available;
    }

    public function get_id(): string
    {
        return $this->id;
    }

    public function is_available(): bool
    {
        return $this->available;
    }

    public function boot(WPCSService $wpcs_service): void
    {
        $this->booted = true;
    }

    /** @param PlanPurchase $purchase_context tests pass the purchase itself */
    public function get_plan_purchase($subscription_id, $purchase_context): PlanPurchase
    {
        return $purchase_context;
    }

    public function get_subscription_meta($subscription_id, string $key)
    {
        return $this->meta[$subscription_id][$key] ?? '';
    }

    public function update_subscription_meta($subscription_id, string $key, $value): void
    {
        $this->meta[$subscription_id][$key] = $value;
    }

    public function find_subscriptions_by_meta(string $key, array $excluded_values): array
    {
        $found = [];
        foreach ($this->meta as $id => $meta) {
            if (isset($meta[$key]) && !in_array($meta[$key], $excluded_values, true)) {
                $found[$id] = $meta[$key];
            }
        }
        return $found;
    }

    public function get_customer_id($subscription_id): int
    {
        return 7;
    }

    public function get_customer_email($subscription_id): string
    {
        return 'owner@apexresearch.example';
    }

    public function get_customer_name($subscription_id): string
    {
        return 'Jordan Reyes';
    }

    public function get_subscription_url($subscription_id): string
    {
        return "https://storefront.example/account/subscriptions/$subscription_id";
    }
}

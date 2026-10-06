<?php

namespace WaaSHost\Commerce\FluentCart;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Services\URL;

/**
 * The only class that reads FluentCart's models, so the adapter, checkout and
 * portal logic can be tested against a mock of it. Checked against FluentCart 1.7.
 */
class FluentCartStore
{
    /** @return Subscription|null */
    public function find_subscription($subscription_id)
    {
        return Subscription::query()->find((int) $subscription_id);
    }

    /**
     * The subscription a subscription order started (renewals point at their parent order).
     *
     * @return Subscription|null
     */
    public function find_subscription_for_order($order)
    {
        $parent_order_id = $order->type === 'renewal' ? $order->parent_id : $order->id;

        return Subscription::query()->where('parent_order_id', $parent_order_id)->first();
    }

    public function get_subscription_meta($subscription_id, string $key)
    {
        $subscription = $this->find_subscription($subscription_id);

        return $subscription ? $subscription->getMeta($key, '') : '';
    }

    public function update_subscription_meta($subscription_id, string $key, $value): void
    {
        $subscription = $this->find_subscription($subscription_id);
        if ($subscription) {
            $subscription->updateMeta($key, $value);
        }
    }

    /** @return array<int, mixed> subscription id => meta value */
    public function find_subscription_meta(string $key, array $excluded_values): array
    {
        $query = SubscriptionMeta::query()->where('meta_key', $key);
        if ($excluded_values) {
            $query->whereNotIn('meta_value', $excluded_values);
        }

        $found = [];
        foreach ($query->get() as $meta) {
            $found[(int) $meta->subscription_id] = $meta->meta_value;
        }

        return $found;
    }

    /**
     * Settings of the enabled WaaS feed on a product, or null when the product is not a website plan.
     *
     * @return array<string, mixed>|null
     */
    public function get_plan_feed($product_id): ?array
    {
        $feeds = ProductMeta::query()
            ->where('object_id', (int) $product_id)
            ->where('object_type', 'product_integration')
            ->where('meta_key', WaasProvisioningIntegration::KEY)
            ->get();

        foreach ($feeds as $feed) {
            $settings = $feed->meta_value;
            if (is_array($settings) && ($settings['enabled'] ?? '') === 'yes') {
                return $settings;
            }
        }

        return null;
    }

    public function has_plan_feed(): bool
    {
        return ProductMeta::query()
            ->where('object_type', 'product_integration')
            ->where('meta_key', WaasProvisioningIntegration::KEY)
            ->exists();
    }

    /** @return int[] product ids on the order, in order */
    public function get_order_product_ids($order): array
    {
        $ids = [];
        foreach ($order->order_items as $item) {
            $ids[] = (int) $item->post_id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public function get_order_meta($order, string $key): string
    {
        return (string) $order->getMeta($key, '');
    }

    /** @return int[] product ids in a checkout cart */
    public function get_cart_product_ids($cart): array
    {
        $ids = [];
        foreach ((array) ($cart->cart_data ?? []) as $item) {
            $ids[] = (int) ($item['post_id'] ?? 0);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @return array{user_id: int, email: string, name: string}|null
     */
    public function get_subscription_customer($subscription_id): ?array
    {
        $subscription = $this->find_subscription($subscription_id);
        $customer = $subscription ? $subscription->customer : null;
        if (!$customer) {
            return null;
        }

        return [
            'user_id' => (int) $customer->user_id,
            'email' => (string) $customer->email,
            'name' => (string) $customer->full_name,
        ];
    }

    public function get_subscription_url($subscription_id): string
    {
        $subscription = $this->find_subscription($subscription_id);

        return $subscription ? (string) $subscription->getViewUrl('customer') : '';
    }

    /**
     * Subscription ids of the FluentCart customer for a WordPress user: linked by user id, or by
     * email when FluentCart did not link them (it doesn't when the email already had an account).
     *
     * @return int[]
     */
    public function find_user_subscription_ids(int $user_id, string $email): array
    {
        $customer_ids = Customer::query()
            ->where('user_id', $user_id)
            ->orWhere(function ($query) use ($email) {
                $query->where('email', $email)->where(function ($unlinked) {
                    $unlinked->whereNull('user_id')->orWhere('user_id', 0);
                });
            })
            ->pluck('id')
            ->toArray();

        if (!$customer_ids) {
            return [];
        }

        return array_map('intval', Subscription::query()
            ->whereIn('customer_id', $customer_ids)
            ->orderBy('id', 'DESC')
            ->pluck('id')
            ->toArray());
    }

    public function get_portal_url(string $path): string
    {
        return URL::getCustomerDashboardUrl($path);
    }
}

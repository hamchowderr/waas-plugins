<?php

namespace AnyPressHost\Commerce\FluentCart;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API as StripeApi;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Plan as StripePlan;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeSettingsBase;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\URL;
use AnyPressHost\Core\Tenant;
use AnyPressHost\Features\StoreJobs;

/**
 * The only class that reads FluentCart's models, so the adapter, checkout and
 * portal logic can be tested against a mock of it. Checked against FluentCart 1.7.
 * Its feed and order queries are in the FluentCartFeedQueries and FluentCartOrderQueries traits.
 */
class FluentCartStore
{
    use FluentCartFeedQueries;
    use FluentCartOrderQueries;

    /** Subscription statuses FluentCart grants access with (its "validable" statuses). */
    public const ACTIVE_STATUSES = ['active', 'trialing'];

    /** @return Subscription|null */
    public function find_subscription($subscription_id)
    {
        return Subscription::query()->find((int) $subscription_id);
    }

    /** The subscription's status now, '' when there is no such subscription. */
    public function get_subscription_status($subscription_id): string
    {
        $subscription = $this->find_subscription($subscription_id);

        return $subscription ? (string) $subscription->status : '';
    }

    /** How many of the product the subscription bills for (FluentCart's quantity), at least 1. */
    public function get_subscription_quantity($subscription_id): int
    {
        $subscription = $this->find_subscription($subscription_id);

        return $subscription ? max(1, (int) ($subscription->quantity ?? 1)) : 0;
    }

    /**
     * When the subscription's paid period ends: its next billing date (FluentCart keeps it on a cancel), as a unix
     * time. 0 when there is none.
     */
    public function get_paid_through($subscription_id): int
    {
        $subscription = $this->find_subscription($subscription_id);
        $date = $subscription ? (string) $subscription->next_billing_date : '';
        if ($date === '' || strpos($date, '0000-00-00') === 0) {
            return 0;
        }

        $time = strtotime($date . ' UTC');

        return $time === false ? 0 : $time;
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

    /** The subscription meta that records what anypress-host has done or plans for the store. */
    public const ANYPRESS_STATE_META = [
        Tenant::TENANT_STATE,
        Tenant::TENANT_DELETE_AT_META,
        Tenant::TENANT_SUSPEND_AT_META,
        Tenant::TENANT_SUSPEND_REASON_META,
        Tenant::NAME_CONFLICT_META,
        StoreJobs::QUEUE_META,
        StoreJobs::FAILURE_META,
    ];

    /**
     * What anypress-host has recorded for the subscription's store. Compared before and after an event to tell whether
     * anypress-host acted on it.
     */
    public function get_anypress_state($subscription_id): array
    {
        $subscription = $this->find_subscription($subscription_id);
        $state = [];
        foreach (self::ANYPRESS_STATE_META as $key) {
            $state[$key] = $subscription ? $subscription->getMeta($key, '') : '';
        }

        return $state;
    }

    public function update_subscription_meta($subscription_id, string $key, $value): void
    {
        $subscription = $this->find_subscription($subscription_id);
        if ($subscription) {
            $subscription->updateMeta($key, $value);
        }
    }

    /** @return int[] ids of subscriptions whose $key meta is $value */
    public function find_subscription_ids_with_meta(string $key, string $value): array
    {
        return array_map('intval', SubscriptionMeta::query()->where('meta_key', $key)->where('meta_value', $value)->pluck('subscription_id')->toArray());
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
     * @return array{id: int, user_id: int, email: string, name: string, country: string}|null
     */
    public function get_subscription_customer($subscription_id): ?array
    {
        $subscription = $this->find_subscription($subscription_id);
        $customer = $subscription ? $subscription->customer : null;
        if (!$customer) {
            return null;
        }

        return [
            'id' => (int) $customer->id,
            'user_id' => (int) $customer->user_id,
            'email' => (string) $customer->email,
            'name' => (string) $customer->full_name,
            'country' => (string) $customer->country,
        ];
    }

    public function get_subscription_url($subscription_id): string
    {
        $subscription = $this->find_subscription($subscription_id);

        return $subscription ? (string) $subscription->getViewUrl('customer') : '';
    }

    /** The subscription in FluentCart's admin. */
    public function get_admin_subscription_url($subscription_id): string
    {
        $subscription = $this->find_subscription($subscription_id);

        return $subscription ? (string) $subscription->getViewUrl('admin') : '';
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

    /** @return int[] subscription ids of one FluentCart customer */
    public function find_customer_subscription_ids(int $customer_id): array
    {
        if ($customer_id <= 0) {
            return [];
        }

        return array_map('intval', Subscription::query()->where('customer_id', $customer_id)->pluck('id')->toArray());
    }

    /** @return int[] the product of each subscription in $subscription_ids that is in an active status */
    public function get_active_subscription_product_ids(array $subscription_ids): array
    {
        if (!$subscription_ids) {
            return [];
        }

        return array_map('intval', Subscription::query()
            ->whereIn('id', array_map('intval', $subscription_ids))
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->pluck('product_id')
            ->toArray());
    }

    /**
     * How a subscription is billed, for re-pricing it. Amounts are in cents; currency and mode are its first order's.
     * store_billed is FluentCart's usesRenewalEngine(): the store makes the renewal orders (manual or auto-charge)
     * rather than the gateway.
     *
     * @return array{id: int, status: string, product_id: int, variation_id: int, billing_interval: string, collection_method: string, store_billed: bool, payment_method: string, vendor_subscription_id: string, recurring_amount: int, recurring_tax_total: int, recurring_total: int, parent_order_id: int, currency: string, mode: string}|null
     */
    public function get_subscription_billing($subscription_id): ?array
    {
        $subscription = $this->find_subscription($subscription_id);
        if (!$subscription) {
            return null;
        }
        $order = $subscription->order;

        return [
            'id' => (int) $subscription->id,
            'status' => (string) $subscription->status,
            'product_id' => (int) $subscription->product_id,
            'variation_id' => (int) $subscription->variation_id,
            'billing_interval' => (string) $subscription->billing_interval,
            'collection_method' => (string) $subscription->collection_method,
            'store_billed' => (bool) $subscription->usesRenewalEngine(),
            'payment_method' => (string) $subscription->current_payment_method,
            'vendor_subscription_id' => (string) $subscription->vendor_subscription_id,
            'recurring_amount' => (int) $subscription->recurring_amount,
            'recurring_tax_total' => (int) $subscription->recurring_tax_total,
            'recurring_total' => (int) $subscription->recurring_total,
            'parent_order_id' => (int) $subscription->parent_order_id,
            'currency' => $order ? (string) $order->currency : '',
            'mode' => $order ? (string) $order->mode : '',
        ];
    }

    /**
     * Gives a store-billed subscription a new renewal price through FluentCart's own subscription edit, which also
     * updates an open renewal order. That edit keeps the tax as it is and sets recurring_amount to the total minus
     * the tax, so the new tax is put on the subscription first and saved with it.
     *
     * @return true|\WP_Error
     */
    public function update_store_billed_total($subscription_id, int $recurring_total, int $recurring_tax_total)
    {
        $subscription = $this->find_subscription($subscription_id);
        if (!$subscription) {
            return new \WP_Error('anypress_no_subscription', 'The subscription no longer exists.');
        }

        $old_tax = $subscription->recurring_tax_total;
        $subscription->recurring_tax_total = $recurring_tax_total;
        // It takes the total as a decimal and converts it to cents.
        $result = SubscriptionService::updateSubscription($subscription, ['recurring_total' => number_format($recurring_total / 100, 2, '.', '')]);
        if ($result instanceof \WP_Error) {
            $subscription->recurring_tax_total = $old_tax;
        }

        return $result;
    }

    /** Saves a subscription's recurring amounts (cents), for a price the gateway has already changed. */
    public function set_subscription_totals($subscription_id, array $totals): void
    {
        $subscription = $this->find_subscription($subscription_id);
        if ($subscription) {
            $subscription->fill(array_intersect_key($totals, array_flip(['recurring_amount', 'recurring_tax_total', 'recurring_total'])));
            $subscription->save();
        }
    }

    /** Adds a note to the subscription's activity in FluentCart. */
    public function add_subscription_note($subscription_id, string $title, string $detail, string $type = 'info'): void
    {
        $subscription = $this->find_subscription($subscription_id);
        if ($subscription) {
            $subscription->addLog($title, $detail, $type, 'AnyPress');
        }
    }

    /** An amount in cents as FluentCart shows it, with the currency sign, as plain text (FluentCart's sign is an HTML entity). */
    public function format_price(int $cents, string $currency): string
    {
        $price = (string) Helper::toDecimal($cents, true, $currency !== '' ? $currency : null);

        return html_entity_decode(wp_strip_all_tags($price), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The mode (live or test) FluentCart's Stripe uses for its own calls, such as creating prices. */
    public function get_stripe_mode(): string
    {
        return (string) (new StripeSettingsBase())->getMode();
    }

    /**
     * FluentCart's Stripe price for a subscription item at a recurring total, created when it doesn't exist yet.
     *
     * @param array $args order_id, product_id, variation_id, billing_interval, recurring_total (cents), currency, trial_days, interval_count
     * @return array|\WP_Error the Stripe plan
     */
    public function get_stripe_plan(array $args)
    {
        return StripePlan::getStripePricing($args);
    }

    /**
     * A call to the Stripe API with FluentCart's keys for $mode.
     *
     * @return array|\WP_Error the decoded response
     */
    public function stripe_request(string $method, string $path, array $data, string $mode)
    {
        $api = new StripeApi();

        return $method === 'GET' ? $api->getStripeObject($path, $data, $mode) : $api->createStripeObject($path, $data, $mode);
    }

    public function get_portal_url(string $path): string
    {
        return URL::getCustomerDashboardUrl($path);
    }
}

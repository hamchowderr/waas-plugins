<?php

namespace WaaSHost\Commerce\FluentCart;

use WaaSHost\Features\StoreJobs;
use WaaSHost\Features\VolumeDiscount;

/**
 * Keeps a customer's existing website plans on their volume discount tier (VolumeDiscount). The checkout coupon
 * (FluentCartVolumeDiscount) bakes the tier into a plan's renewal price when it is bought; when the customer's live
 * websites later cross a tier, up or down, their other plans get the new tier's percent from their next renewal. Nothing
 * is prorated or charged now.
 *
 * When a website plan's status moves into or out of an active status, a re-price job for its customer is queued
 * (Action Scheduler, which FluentCart bundles, or WP-Cron), so Stripe is never called inside a webhook. The job counts the
 * customer's live websites the way the checkout does and moves each active plan from the percent it has to the tier's:
 * - the percent it has is the subscription's waas_volume_percent meta, written by this job, or else the volume coupon on
 *   the order that started it, so the plan just bought with the coupon isn't discounted twice;
 * - the renewal total is scaled by (100 - new) / (100 - old), which keeps the effect of other recurring coupons;
 * - a Stripe subscription FluentCart created gets the Stripe price for the new total, without proration; a store-billed
 *   one (manual or auto-charge) is edited the way FluentCart's admin does; any other gateway is left alone with a note
 *   asking the shop owner to change it there.
 * Running it again changes nothing. A failure leaves the price and the meta as they were and adds a note.
 */
class FluentCartVolumeRepricing
{
    public const JOB_HOOK = 'waas_volume_reprice';
    /** Subscription meta: the volume discount percent in the plan's renewal price. */
    public const PERCENT_META = 'waas_volume_percent';
    /** Subscription meta: the percent a note last asked the shop owner to set by hand, so it is asked once. */
    public const MANUAL_META = 'waas_volume_percent_manual';

    private FluentCartAdapter $adapter;
    private FluentCartStore $store;
    private FluentCartVolumeDiscount $discount;
    /** @var array<string, bool> customers queued in this request */
    private array $queued = [];

    public function __construct(FluentCartAdapter $adapter, FluentCartStore $store, FluentCartVolumeDiscount $discount)
    {
        $this->adapter = $adapter;
        $this->store = $store;
        $this->discount = $discount;
    }

    public function register(): void
    {
        // Most status changes. Some paths change the status without it: Stripe confirming a new subscription
        // (activated only), store-billed pause and resume, a cancel from the subscription page and an unpaid
        // store-billed renewal going past due. A change seen twice queues one job.
        add_action('fluent_cart/payments/subscription_status_changed', [$this, 'status_changed'], 20, 1);
        add_action('fluent_cart/subscription_activated', [$this, 'status_changed'], 20, 1);
        add_action('fluent_cart/subscription_paused', [$this, 'status_changed'], 20, 1);
        add_action('fluent_cart/subscription_resumed', [$this, 'status_changed'], 20, 1);
        add_action('fluent_cart/subscription_canceled', [$this, 'status_changed'], 20, 1);
        add_action('fluent_cart/subscription_past_due', [$this, 'status_changed'], 20, 1);
        add_action(self::JOB_HOOK, [$this, 'run'], 10, 1);
    }

    /**
     * Queues a re-price for the subscription's customer when a website plan starts or stops counting as live.
     *
     * @param array $data subscription, order, customer, and old_status (and new_status) when FluentCart knows it
     */
    public function status_changed($data): void
    {
        $subscription = is_array($data) ? ($data['subscription'] ?? null) : null;
        if (!$subscription || empty($subscription->id) || !VolumeDiscount::is_enabled()) {
            return;
        }

        if (isset($data['old_status'])) {
            $new_status = (string) ($data['new_status'] ?? $subscription->status ?? '');
            $was_live = in_array((string) $data['old_status'], FluentCartStore::ACTIVE_STATUSES, true);
            if ($was_live === in_array($new_status, FluentCartStore::ACTIVE_STATUSES, true)) {
                return; // trialing to active, paused to canceled: the live count is the same
            }
        }

        if (!$this->discount->is_plan_product((int) ($subscription->product_id ?? 0))) {
            return;
        }

        $customer = $this->customer_key((int) $subscription->id);
        if ($customer !== '') {
            $this->queue($customer);
        }
    }

    /** "user:<id>" for a customer with a WordPress account (the checkout counts by account), else "customer:<id>". */
    private function customer_key(int $subscription_id): string
    {
        $user_id = $this->adapter->get_customer_id($subscription_id);
        if ($user_id > 0) {
            return 'user:' . $user_id;
        }

        $customer = $this->store->get_subscription_customer($subscription_id);
        $customer_id = (int) ($customer['id'] ?? 0);

        return $customer_id > 0 ? 'customer:' . $customer_id : '';
    }

    /** One pending job per customer: a job already waiting will see this change too. */
    private function queue(string $customer): void
    {
        if (isset($this->queued[$customer])) {
            return;
        }
        $this->queued[$customer] = true;

        $args = [$customer];
        if (function_exists('as_enqueue_async_action')) {
            $pending = function_exists('as_get_scheduled_actions')
                ? as_get_scheduled_actions(['hook' => self::JOB_HOOK, 'args' => $args, 'group' => StoreJobs::GROUP, 'status' => 'pending', 'per_page' => 1], 'ids')
                : [];
            if (!$pending) {
                as_enqueue_async_action(self::JOB_HOOK, $args, StoreJobs::GROUP);
            }
        } else {
            // WordPress skips an event that is already waiting with the same arguments.
            wp_schedule_single_event(time(), self::JOB_HOOK, $args);
        }
    }

    /** The re-price job: moves each of the customer's active website plans to the tier their live websites earn. */
    public function run($customer): void
    {
        if (!VolumeDiscount::is_enabled()) {
            return;
        }

        $subscription_ids = $this->customer_subscription_ids((string) $customer);
        if (!$subscription_ids) {
            return;
        }

        $sites = $this->discount->count_live_sites($subscription_ids);
        $tier = VolumeDiscount::tier_for($sites);
        $target = $tier !== null ? (float) $tier['percent'] : 0.0;

        foreach ($subscription_ids as $subscription_id) {
            $billing = $this->store->get_subscription_billing($subscription_id);
            if ($billing === null
                || !in_array($billing['status'], FluentCartStore::ACTIVE_STATUSES, true)
                || $billing['recurring_total'] <= 0
                || !$this->discount->is_plan_product($billing['product_id'])) {
                continue;
            }

            $this->reprice($billing, $target, $sites);
        }
    }

    /** @return int[] */
    private function customer_subscription_ids(string $customer): array
    {
        [$kind, $id] = array_pad(explode(':', $customer, 2), 2, '');
        $id = (int) $id;
        if ($id <= 0) {
            return [];
        }
        if ($kind === 'user') {
            return $this->adapter->get_customer_subscription_ids($id);
        }

        return $kind === 'customer' ? $this->store->find_customer_subscription_ids($id) : [];
    }

    private function reprice(array $billing, float $target, int $sites): void
    {
        $subscription_id = $billing['id'];
        $current = $this->current_percent($billing);
        if (abs($target - $current) < 0.005) {
            $this->clear_manual_change($subscription_id);
            return;
        }

        $from = VolumeDiscount::format_percent($current) . '%';
        $to = VolumeDiscount::format_percent($target) . '%';
        if ($current >= 100) {
            // A free plan's price before the discount can't be worked out.
            $this->failed($billing, $from, $to, $sites, 'the plan is free, so its price without the discount is unknown');
            return;
        }

        $totals = $this->scaled_totals($billing, (100 - $target) / (100 - $current));
        $price = $this->store->format_price($totals['recurring_total'], $billing['currency']);

        if ($totals['recurring_total'] !== $billing['recurring_total']) {
            if ($billing['store_billed']) {
                $result = $this->store->update_store_billed_total($subscription_id, $totals['recurring_total'], $totals['recurring_tax_total']);
            } elseif ($this->is_stripe_managed($billing)) {
                $result = $this->update_stripe($billing, $totals['recurring_total']);
                if ($result === true) {
                    $this->store->set_subscription_totals($subscription_id, $totals);
                }
            } else {
                $this->ask_for_manual_change($billing, $target, $from, $to, $sites, $price);
                return;
            }

            if ($result instanceof \WP_Error) {
                $this->failed($billing, $from, $to, $sites, $result->get_error_message());
                return;
            }
        }

        $this->store->update_subscription_meta($subscription_id, self::PERCENT_META, VolumeDiscount::format_percent($target));
        $this->clear_manual_change($subscription_id);
        $this->store->add_subscription_note(
            $subscription_id,
            __('Volume discount changed', 'waas-host'),
            sprintf('Volume discount changed from %s to %s (%s); renews at %s.', $from, $to, $this->live_websites($sites), $price)
        );
    }

    /** Forgets a request for a manual change that is no longer needed, so the next one is asked again. */
    private function clear_manual_change(int $subscription_id): void
    {
        if ((string) $this->store->get_subscription_meta($subscription_id, self::MANUAL_META) !== '') {
            $this->store->update_subscription_meta($subscription_id, self::MANUAL_META, '');
        }
    }

    private function live_websites(int $sites): string
    {
        return sprintf($sites === 1 ? '%d live website' : '%d live websites', $sites);
    }

    /** The percent in the plan's renewal price: what this job set, else the volume coupon it was bought with. */
    private function current_percent(array $billing): float
    {
        $saved = $this->store->get_subscription_meta($billing['id'], self::PERCENT_META);
        if (is_numeric($saved)) {
            return (float) $saved;
        }

        foreach ($this->store->get_order_coupon_codes($billing['parent_order_id']) as $code) {
            $tier = VolumeDiscount::tier_with_code((string) $code);
            if ($tier !== null) {
                return (float) $tier['percent'];
            }
        }

        return 0.0;
    }

    /**
     * The recurring amounts times $ratio, in cents. The tax is scaled with the total; when amount + tax made up the
     * total they still do.
     *
     * @return array{recurring_amount: int, recurring_tax_total: int, recurring_total: int}
     */
    private function scaled_totals(array $billing, float $ratio): array
    {
        $total = (int) round($billing['recurring_total'] * $ratio);
        $tax = (int) round($billing['recurring_tax_total'] * $ratio);
        $adds_up = $billing['recurring_amount'] + $billing['recurring_tax_total'] === $billing['recurring_total'];

        return [
            'recurring_amount' => $adds_up ? $total - $tax : (int) round($billing['recurring_amount'] * $ratio),
            'recurring_tax_total' => $tax,
            'recurring_total' => $total,
        ];
    }

    /** A Stripe subscription FluentCart created and Stripe bills, as the live shop runs. */
    private function is_stripe_managed(array $billing): bool
    {
        return $billing['collection_method'] === 'automatic'
            && $billing['payment_method'] === 'stripe'
            && $billing['vendor_subscription_id'] !== '';
    }

    /**
     * Moves the Stripe subscription's one item to FluentCart's Stripe price for $recurring_total, the way FluentCart
     * prices a new subscription, with no proration: the next invoice is the first at the new price.
     *
     * @return true|\WP_Error
     */
    private function update_stripe(array $billing, int $recurring_total)
    {
        $mode = $billing['mode'] !== '' ? $billing['mode'] : 'live';
        // FluentCart makes prices with the keys of the store's current mode only.
        $store_mode = $this->store->get_stripe_mode();
        if ($store_mode !== '' && $store_mode !== $mode) {
            return new \WP_Error('waas_stripe_mode', sprintf('the subscription is in Stripe %s mode but the store is in %s mode', $mode, $store_mode));
        }

        $plan = $this->store->get_stripe_plan([
            'order_id' => $billing['parent_order_id'],
            'product_id' => $billing['product_id'],
            'variation_id' => $billing['variation_id'],
            'billing_interval' => $billing['billing_interval'],
            'recurring_total' => $recurring_total,
            'currency' => $billing['currency'],
            'trial_days' => 0,
            'interval_count' => 1,
        ]);
        if ($plan instanceof \WP_Error) {
            return $plan;
        }
        $plan_id = (string) ($plan['id'] ?? '');
        if ($plan_id === '') {
            return new \WP_Error('waas_stripe_plan', 'Stripe returned no price');
        }

        $path = 'subscriptions/' . $billing['vendor_subscription_id'];
        $remote = $this->store->stripe_request('GET', $path, [], $mode);
        if ($remote instanceof \WP_Error) {
            return $remote;
        }

        $items = (array) ($remote['items']['data'] ?? []);
        if (count($items) !== 1 || empty($items[0]['id'])) {
            return new \WP_Error('waas_stripe_items', sprintf('the Stripe subscription has %d items, not one', count($items)));
        }
        $item = $items[0];
        if ((string) ($item['price']['id'] ?? '') === $plan_id) {
            return true; // already at the price, from a run that couldn't save it here
        }

        $updated = $this->store->stripe_request('POST', $path, [
            'items' => [[
                'id' => (string) $item['id'],
                'price' => $plan_id,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            ]],
            'proration_behavior' => 'none',
        ], $mode);

        return $updated instanceof \WP_Error ? $updated : true;
    }

    private function ask_for_manual_change(array $billing, float $target, string $from, string $to, int $sites, string $price): void
    {
        $subscription_id = $billing['id'];
        $percent = VolumeDiscount::format_percent($target);
        if ((string) $this->store->get_subscription_meta($subscription_id, self::MANUAL_META) === $percent) {
            return; // already asked
        }

        $this->store->update_subscription_meta($subscription_id, self::MANUAL_META, $percent);
        $this->store->add_subscription_note(
            $subscription_id,
            __('Volume discount needs a manual change', 'waas-host'),
            sprintf(
                'The customer now has %s, so this plan should renew with %s off instead of %s, at %s. WaaS Host only re-prices plans billed by Stripe or by the store: change the price in %s by hand. The price is unchanged.',
                $this->live_websites($sites),
                $to,
                $from,
                $price,
                $billing['payment_method'] !== '' ? $billing['payment_method'] : 'the payment gateway'
            ),
            'warning'
        );
    }

    private function failed(array $billing, string $from, string $to, int $sites, string $error): void
    {
        error_log(sprintf('WaaS Host: changing the volume discount of subscription %d from %s to %s failed: %s', $billing['id'], $from, $to, $error));
        $this->store->add_subscription_note(
            $billing['id'],
            __('Volume discount not changed', 'waas-host'),
            sprintf('The volume discount should change from %s to %s (%s), but it failed: %s. The price is unchanged.', $from, $to, $this->live_websites($sites), $error),
            'error'
        );
    }
}

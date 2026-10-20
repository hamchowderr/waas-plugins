<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\FluentCart\FluentCartAdapter;
use WaaSHost\Commerce\FluentCart\FluentCartStore;
use WaaSHost\Commerce\FluentCart\FluentCartVolumeDiscount;
use WaaSHost\Commerce\FluentCart\FluentCartVolumeRepricing;
use WaaSHost\Features\StoreJobs;
use WaaSHost\Features\VolumeDiscount;
use WP_Error;

class FluentCartVolumeRepricingTest extends TestCase
{
    private const PLAN = 101;
    private const ADDON = 201;
    private const USER = 7;

    private $adapter;
    private $store;
    private FluentCartVolumeRepricing $repricing;
    private bool $enabled = true;
    /** @var array<int, array> subscription id => billing (FluentCartStore::get_subscription_billing) */
    private array $subs = [];
    /** @var array<int, array<string, string>> subscription id => meta */
    private array $meta = [];
    /** @var array<int, string[]> order id => coupon codes */
    private array $coupons = [];
    private array $notes = [];
    private array $queued = [];
    private array $pending = [];
    private array $stripe_calls = [];
    private array $plan_args = [];
    private array $store_billed_calls = [];
    /** @var array<string, string> Stripe subscription id => price id of its item */
    private array $stripe_prices = [];
    /** @var WP_Error|null what the Stripe subscription update returns */
    private $stripe_post_error = null;
    /** @var WP_Error|null what FluentCart's store-billed subscription edit returns */
    private $store_billed_error = null;
    private string $stripe_mode = 'live';
    private $previous_error_log;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('get_option')->alias(fn ($key, $default = false) => $key === VolumeDiscount::ENABLED_OPTION ? ($this->enabled ? 'on' : '') : $default);
        Functions\when('as_get_scheduled_actions')->alias(fn ($args) => in_array($args['args'], $this->pending, true) ? [1] : []);
        Functions\when('as_enqueue_async_action')->alias(function ($hook, $args, $group) {
            $this->queued[] = [$hook, $args, $group];
            return 1;
        });
        $this->previous_error_log = ini_set('error_log', '/dev/null');

        $this->adapter = Mockery::mock(FluentCartAdapter::class);
        $this->adapter->allows('is_plan_product')->andReturnUsing(fn ($id) => $id === self::PLAN);
        $this->adapter->allows('get_customer_id')->andReturn(self::USER);
        $this->adapter->allows('get_customer_subscription_ids')->with(self::USER)->andReturnUsing(fn () => array_keys($this->subs));

        $this->store = Mockery::mock(FluentCartStore::class);
        $this->store->allows('get_active_subscription_product_ids')->andReturnUsing(function ($ids) {
            $products = [];
            foreach ($ids as $id) {
                if (in_array($this->subs[$id]['status'], FluentCartStore::ACTIVE_STATUSES, true)) {
                    $products[] = $this->subs[$id]['product_id'];
                }
            }
            return $products;
        });
        $this->store->allows('get_subscription_billing')->andReturnUsing(fn ($id) => $this->subs[$id] ?? null);
        $this->store->allows('get_subscription_meta')->andReturnUsing(fn ($id, $key) => $this->meta[$id][$key] ?? '');
        $this->store->allows('update_subscription_meta')->andReturnUsing(function ($id, $key, $value) {
            $this->meta[$id][$key] = $value;
        });
        $this->store->allows('get_order_coupon_codes')->andReturnUsing(fn ($order_id) => $this->coupons[$order_id] ?? []);
        $this->store->allows('format_price')->andReturnUsing(fn ($cents, $currency) => '$' . number_format($cents / 100, 2));
        $this->store->allows('add_subscription_note')->andReturnUsing(function ($id, $title, $detail, $type = 'info') {
            $this->notes[] = [$id, $title, $detail, $type];
        });
        $this->store->allows('set_subscription_totals')->andReturnUsing(function ($id, $totals) {
            $this->subs[$id] = array_merge($this->subs[$id], $totals);
        });
        $this->store->allows('update_store_billed_total')->andReturnUsing(function ($id, $total, $tax) {
            $this->store_billed_calls[] = [$id, $total, $tax];
            if ($this->store_billed_error) {
                return $this->store_billed_error;
            }
            $this->subs[$id]['recurring_total'] = $total;
            $this->subs[$id]['recurring_tax_total'] = $tax;
            $this->subs[$id]['recurring_amount'] = $total - $tax;
            return true;
        });
        $this->store->allows('get_stripe_mode')->andReturnUsing(fn () => $this->stripe_mode);
        $this->store->allows('get_stripe_plan')->andReturnUsing(function ($args) {
            $this->plan_args[] = $args;
            return ['id' => 'fct_price_' . $args['variation_id'] . '_' . $args['recurring_total']];
        });
        $this->store->allows('stripe_request')->andReturnUsing(function ($method, $path, $data, $mode) {
            $this->stripe_calls[] = [$method, $path, $data, $mode];
            $vendor_id = substr($path, strlen('subscriptions/'));
            if ($method === 'GET') {
                return ['id' => $vendor_id, 'items' => ['data' => [['id' => 'si_' . $vendor_id, 'quantity' => 1, 'price' => ['id' => $this->stripe_prices[$vendor_id] ?? 'old']]]]];
            }
            if ($this->stripe_post_error) {
                return $this->stripe_post_error;
            }
            $this->stripe_prices[$vendor_id] = $data['items'][0]['price'];
            return ['id' => $vendor_id];
        });

        $discount = new FluentCartVolumeDiscount($this->adapter, $this->store);
        $this->repricing = new FluentCartVolumeRepricing($this->adapter, $this->store, $discount);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previous_error_log);
        parent::tearDown();
    }

    /** A website plan billed by Stripe at $total cents a month, started by order $id + 1000. */
    private function stripe_sub(int $id, int $total, array $overrides = []): void
    {
        $this->subs[$id] = array_merge([
            'id' => $id,
            'status' => 'active',
            'product_id' => self::PLAN,
            'variation_id' => 301,
            'billing_interval' => 'monthly',
            'collection_method' => 'automatic',
            'store_billed' => false,
            'payment_method' => 'stripe',
            'vendor_subscription_id' => 'sub_' . $id,
            'recurring_amount' => $total,
            'recurring_tax_total' => 0,
            'recurring_total' => $total,
            'parent_order_id' => $id + 1000,
            'currency' => 'USD',
            'mode' => 'live',
        ], $overrides);
    }

    private function store_billed_sub(int $id, int $total, array $overrides = []): void
    {
        $this->stripe_sub($id, $total, array_merge(['collection_method' => 'manual', 'store_billed' => true, 'payment_method' => 'offline_payment', 'vendor_subscription_id' => ''], $overrides));
    }

    private function totals(int $id): array
    {
        return [$this->subs[$id]['recurring_amount'], $this->subs[$id]['recurring_tax_total'], $this->subs[$id]['recurring_total']];
    }

    private function stripe_posts(): array
    {
        return array_values(array_filter($this->stripe_calls, fn ($call) => $call[0] === 'POST'));
    }

    private function event(int $id, ?string $old_status, string $new_status, int $product_id = self::PLAN): array
    {
        $data = ['subscription' => (object) ['id' => $id, 'status' => $new_status, 'product_id' => $product_id]];
        if ($old_status !== null) {
            $data['old_status'] = $old_status;
            $data['new_status'] = $new_status;
        }

        return $data;
    }

    public function test_hooks_into_fluentcart_status_changes_and_the_job(): void
    {
        $this->repricing->register();

        foreach (['fluent_cart/payments/subscription_status_changed', 'fluent_cart/subscription_activated', 'fluent_cart/subscription_paused', 'fluent_cart/subscription_resumed', 'fluent_cart/subscription_canceled', 'fluent_cart/subscription_past_due'] as $hook) {
            $this->assertNotFalse(has_action($hook, [$this->repricing, 'status_changed']), $hook);
        }
        $this->assertNotFalse(has_action(FluentCartVolumeRepricing::JOB_HOOK, [$this->repricing, 'run']));
    }

    public function test_a_plan_going_live_queues_one_job_for_its_customer(): void
    {
        $this->repricing->status_changed($this->event(13, 'pending', 'active'));
        // FluentCart fires several hooks for one change.
        $this->repricing->status_changed($this->event(13, null, 'active'));

        $this->assertSame([[FluentCartVolumeRepricing::JOB_HOOK, ['user:' . self::USER], StoreJobs::GROUP]], $this->queued);
    }

    public function test_a_plan_stopping_queues_a_job(): void
    {
        $this->repricing->status_changed($this->event(13, 'active', 'canceled'));

        $this->assertCount(1, $this->queued);
    }

    public function test_a_change_that_keeps_the_live_count_queues_nothing(): void
    {
        $this->repricing->status_changed($this->event(13, 'trialing', 'active'));
        $this->repricing->status_changed($this->event(14, 'paused', 'canceled'));

        $this->assertSame([], $this->queued);
    }

    public function test_other_products_queue_nothing(): void
    {
        $this->repricing->status_changed($this->event(13, 'pending', 'active', self::ADDON));

        $this->assertSame([], $this->queued);
    }

    public function test_a_job_already_waiting_for_the_customer_is_not_queued_again(): void
    {
        $this->pending[] = ['user:' . self::USER];

        $this->repricing->status_changed($this->event(13, 'pending', 'active'));

        $this->assertSame([], $this->queued);
    }

    public function test_a_customer_without_an_account_is_queued_by_customer_id(): void
    {
        $this->adapter = Mockery::mock(FluentCartAdapter::class);
        $this->adapter->allows('is_plan_product')->andReturn(true);
        $this->adapter->allows('get_customer_id')->andReturn(0);
        $this->store->allows('get_subscription_customer')->with(13)->andReturn(['id' => 40, 'user_id' => 0, 'email' => 'buyer@example.com']);
        $repricing = new FluentCartVolumeRepricing($this->adapter, $this->store, new FluentCartVolumeDiscount($this->adapter, $this->store));

        $repricing->status_changed($this->event(13, 'pending', 'active'));

        $this->assertSame(['customer:40'], $this->queued[0][1]);
    }

    public function test_the_job_for_a_guest_counts_their_customer_subscriptions(): void
    {
        $this->store->allows('find_customer_subscription_ids')->with(40)->andReturnUsing(fn () => array_keys($this->subs));
        $this->stripe_sub(11, 2000);
        $this->stripe_sub(12, 2000);
        $this->stripe_sub(13, 1800);
        $this->coupons[1013] = ['VOLUME10'];

        $this->repricing->run('customer:40');

        $this->assertSame(1800, $this->subs[11]['recurring_total']);
    }

    public function test_crossing_up_reprices_the_other_plans_to_the_new_tier(): void
    {
        // Two plans at full price; the third was just bought with the 10% coupon.
        $this->stripe_sub(11, 2000);
        $this->stripe_sub(12, 2000);
        $this->stripe_sub(13, 1800);
        $this->coupons[1013] = ['SUMMER', 'VOLUME10'];

        $this->repricing->run('user:' . self::USER);

        $this->assertSame([1800, 0, 1800], $this->totals(11));
        $this->assertSame([1800, 0, 1800], $this->totals(12));
        $this->assertSame([1800, 0, 1800], $this->totals(13));
        $this->assertSame('10', $this->meta[11][FluentCartVolumeRepricing::PERCENT_META]);
        $this->assertSame('10', $this->meta[12][FluentCartVolumeRepricing::PERCENT_META]);
        $this->assertArrayNotHasKey(13, $this->meta);
        $this->assertCount(2, $this->stripe_posts());
        $this->assertSame([11, 'Volume discount changed', 'Volume discount changed from 0% to 10% (3 live websites); renews at $18.00.', 'info'], $this->notes[0]);
        $this->assertSame(12, $this->notes[1][0]);
        $this->assertCount(2, $this->notes);
    }

    public function test_the_stripe_subscription_moves_to_fluentcarts_price_without_proration(): void
    {
        $this->stripe_sub(11, 2000, ['mode' => 'test', 'currency' => 'EUR', 'billing_interval' => 'yearly']);
        $this->stripe_sub(12, 1800);
        $this->stripe_sub(13, 1800);
        $this->meta[12][FluentCartVolumeRepricing::PERCENT_META] = '10';
        $this->meta[13][FluentCartVolumeRepricing::PERCENT_META] = '10';
        $this->stripe_mode = 'test';

        $this->repricing->run('user:' . self::USER);

        $this->assertSame([[
            'order_id' => 1011,
            'product_id' => self::PLAN,
            'variation_id' => 301,
            'billing_interval' => 'yearly',
            'recurring_total' => 1800,
            'currency' => 'EUR',
            'trial_days' => 0,
            'interval_count' => 1,
        ]], $this->plan_args);
        $this->assertSame([
            ['GET', 'subscriptions/sub_11', [], 'test'],
            ['POST', 'subscriptions/sub_11', ['items' => [['id' => 'si_sub_11', 'price' => 'fct_price_301_1800', 'quantity' => 1]], 'proration_behavior' => 'none'], 'test'],
        ], $this->stripe_calls);
    }

    public function test_crossing_down_takes_the_discount_away(): void
    {
        $this->stripe_sub(11, 1800);
        $this->stripe_sub(12, 1800);
        $this->stripe_sub(13, 1800, ['status' => 'canceled']);
        $this->meta[11][FluentCartVolumeRepricing::PERCENT_META] = '10';
        $this->coupons[1012] = ['VOLUME10'];
        $this->coupons[1013] = ['VOLUME10'];

        $this->repricing->run('user:' . self::USER);

        $this->assertSame(2000, $this->subs[11]['recurring_total']);
        $this->assertSame(2000, $this->subs[12]['recurring_total']);
        $this->assertSame(1800, $this->subs[13]['recurring_total']);
        $this->assertSame('0', $this->meta[12][FluentCartVolumeRepricing::PERCENT_META]);
        $this->assertSame('Volume discount changed from 10% to 0% (2 live websites); renews at $20.00.', $this->notes[0][2]);
    }

    public function test_moving_to_a_higher_tier_keeps_other_coupons(): void
    {
        // 10 live websites: 20%. This plan has 10% volume and a 50% coupon of the shop's: 2000 x 0.5 x 0.9 = 900.
        for ($id = 11; $id <= 19; $id++) {
            $this->stripe_sub($id, 1600);
            $this->meta[$id][FluentCartVolumeRepricing::PERCENT_META] = '20';
        }
        $this->stripe_sub(20, 900);
        $this->coupons[1020] = ['HALF', 'VOLUME10'];

        $this->repricing->run('user:' . self::USER);

        $this->assertSame(800, $this->subs[20]['recurring_total']);
        $this->assertSame('20', $this->meta[20][FluentCartVolumeRepricing::PERCENT_META]);
        $this->assertCount(1, $this->stripe_posts());
    }

    public function test_no_change_within_a_tier(): void
    {
        foreach ([11, 12, 13, 14] as $id) {
            $this->stripe_sub($id, 1800);
            $this->meta[$id][FluentCartVolumeRepricing::PERCENT_META] = '10';
        }

        $this->repricing->run('user:' . self::USER);

        $this->assertSame([], $this->stripe_calls);
        $this->assertSame([], $this->notes);
    }

    public function test_running_twice_changes_nothing_the_second_time(): void
    {
        $this->stripe_sub(11, 2000);
        $this->store_billed_sub(12, 2000);
        $this->stripe_sub(13, 1800);
        $this->coupons[1013] = ['VOLUME10'];

        $this->repricing->run('user:' . self::USER);
        $calls = [count($this->stripe_calls), count($this->store_billed_calls), count($this->notes)];
        $totals = $this->subs;
        $this->repricing->run('user:' . self::USER);

        $this->assertSame([2, 1, 2], $calls);
        $this->assertSame($calls, [count($this->stripe_calls), count($this->store_billed_calls), count($this->notes)]);
        $this->assertSame($totals, $this->subs);
    }

    public function test_store_billed_plans_are_edited_in_fluentcart_with_the_tax_scaled(): void
    {
        $this->store_billed_sub(11, 2200, ['recurring_amount' => 2000, 'recurring_tax_total' => 200]);
        $this->store_billed_sub(12, 1980, ['recurring_amount' => 1800, 'recurring_tax_total' => 180]);
        $this->store_billed_sub(13, 1980, ['recurring_amount' => 1800, 'recurring_tax_total' => 180]);
        $this->coupons[1012] = ['VOLUME10'];
        $this->coupons[1013] = ['VOLUME10'];

        $this->repricing->run('user:' . self::USER);

        $this->assertSame([[11, 1980, 180]], $this->store_billed_calls);
        $this->assertSame([1800, 180, 1980], $this->totals(11));
        $this->assertSame([], $this->stripe_calls);
        $this->assertSame('10', $this->meta[11][FluentCartVolumeRepricing::PERCENT_META]);
    }

    public function test_a_failed_store_billed_edit_changes_nothing(): void
    {
        $this->store_billed_error = new WP_Error('invalid_value', 'recurring_total cannot be less than the existing tax total.');
        $this->store_billed_sub(11, 2000);
        $this->stripe_sub(12, 1800);
        $this->stripe_sub(13, 1800);
        $this->coupons[1012] = ['VOLUME10'];
        $this->coupons[1013] = ['VOLUME10'];

        $this->repricing->run('user:' . self::USER);

        $this->assertSame(2000, $this->subs[11]['recurring_total']);
        $this->assertArrayNotHasKey(11, $this->meta);
        $this->assertSame('error', $this->notes[0][3]);
    }

    public function test_other_gateways_are_left_alone_with_one_note(): void
    {
        $this->stripe_sub(11, 2000, ['payment_method' => 'paypal', 'vendor_subscription_id' => 'I-ABC']);
        $this->stripe_sub(12, 1800);
        $this->stripe_sub(13, 1800);
        $this->coupons[1012] = ['VOLUME10'];
        $this->coupons[1013] = ['VOLUME10'];

        $this->repricing->run('user:' . self::USER);
        $this->repricing->run('user:' . self::USER);

        $this->assertSame(2000, $this->subs[11]['recurring_total']);
        $this->assertSame([], $this->stripe_calls);
        $this->assertArrayNotHasKey(FluentCartVolumeRepricing::PERCENT_META, $this->meta[11]);
        $this->assertCount(1, $this->notes);
        $this->assertSame('Volume discount needs a manual change', $this->notes[0][1]);
        $this->assertStringContainsString('10% off instead of 0%, at $18.00', $this->notes[0][2]);
        $this->assertStringContainsString('in paypal by hand', $this->notes[0][2]);
        $this->assertSame('warning', $this->notes[0][3]);
    }

    public function test_a_stripe_failure_leaves_the_price_and_meta_alone(): void
    {
        $this->stripe_sub(11, 2000);
        $this->stripe_sub(12, 1800);
        $this->stripe_sub(13, 1800);
        $this->coupons[1012] = ['VOLUME10'];
        $this->coupons[1013] = ['VOLUME10'];
        $this->stripe_post_error = new WP_Error('api_error', 'No such subscription');

        $this->repricing->run('user:' . self::USER);

        $this->assertSame([2000, 0, 2000], $this->totals(11));
        $this->assertArrayNotHasKey(11, $this->meta);
        $this->assertSame([11, 'Volume discount not changed', 'The volume discount should change from 0% to 10% (3 live websites), but it failed: No such subscription. The price is unchanged.', 'error'], $this->notes[0]);

        // Stripe works again: the next run makes the change.
        $this->stripe_post_error = null;
        $this->repricing->run('user:' . self::USER);
        $this->assertSame(1800, $this->subs[11]['recurring_total']);
    }

    public function test_a_stripe_subscription_already_at_the_price_is_only_saved_here(): void
    {
        $this->stripe_sub(11, 2000);
        $this->stripe_sub(12, 1800);
        $this->stripe_sub(13, 1800);
        $this->coupons[1012] = ['VOLUME10'];
        $this->coupons[1013] = ['VOLUME10'];
        $this->stripe_prices['sub_11'] = 'fct_price_301_1800';

        $this->repricing->run('user:' . self::USER);

        $this->assertSame([], $this->stripe_posts());
        $this->assertSame(1800, $this->subs[11]['recurring_total']);
    }

    public function test_a_stripe_subscription_in_the_other_mode_is_not_changed(): void
    {
        $this->stripe_sub(11, 2000, ['mode' => 'test']);
        $this->stripe_sub(12, 1800);
        $this->stripe_sub(13, 1800);
        $this->coupons[1012] = ['VOLUME10'];
        $this->coupons[1013] = ['VOLUME10'];

        $this->repricing->run('user:' . self::USER);

        $this->assertSame([], $this->stripe_calls);
        $this->assertSame(2000, $this->subs[11]['recurring_total']);
        $this->assertStringContainsString('Stripe test mode but the store is in live mode', $this->notes[0][2]);
    }

    public function test_inactive_free_and_non_plan_subscriptions_are_skipped(): void
    {
        $this->stripe_sub(11, 2000);
        $this->stripe_sub(12, 2000);
        $this->stripe_sub(13, 2000);
        $this->stripe_sub(14, 2000, ['status' => 'paused']);
        $this->stripe_sub(15, 0, ['recurring_amount' => 0]);
        $this->stripe_sub(16, 2000, ['product_id' => self::ADDON]);

        $this->repricing->run('user:' . self::USER);

        $this->assertSame(2000, $this->subs[14]['recurring_total']);
        $this->assertSame(0, $this->subs[15]['recurring_total']);
        $this->assertSame(2000, $this->subs[16]['recurring_total']);
        // 11, 12, 13 and the free 15 are live: four websites.
        $this->assertCount(3, $this->stripe_posts());
        $this->assertStringContainsString('(4 live websites)', $this->notes[0][2]);
    }

    public function test_does_nothing_while_the_discount_is_off(): void
    {
        $this->enabled = false;
        $this->stripe_sub(11, 2000);
        $this->stripe_sub(12, 2000);
        $this->stripe_sub(13, 1800);
        $this->coupons[1013] = ['VOLUME10'];

        $this->repricing->status_changed($this->event(13, 'pending', 'active'));
        $this->repricing->run('user:' . self::USER);

        $this->assertSame([], $this->queued);
        $this->assertSame([], $this->stripe_calls);
        $this->assertSame([], $this->notes);
        $this->assertSame(2000, $this->subs[11]['recurring_total']);
    }

    public function test_an_unknown_customer_does_nothing(): void
    {
        $this->repricing->run('nobody');
        $this->repricing->run('user:0');
        $this->repricing->status_changed(['subscription' => null]);
        $this->repricing->status_changed('not an array');

        $this->assertSame([], $this->stripe_calls);
        $this->assertSame([], $this->queued);
    }
}

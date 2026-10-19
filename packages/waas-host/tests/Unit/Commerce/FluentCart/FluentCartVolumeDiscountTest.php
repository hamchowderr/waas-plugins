<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\FluentCart\FluentCartAdapter;
use WaaSHost\Commerce\FluentCart\FluentCartStore;
use WaaSHost\Commerce\FluentCart\FluentCartVolumeDiscount;
use WaaSHost\Features\VolumeDiscount;

class FluentCartVolumeDiscountTest extends TestCase
{
    private const PLAN = 101;
    private const OTHER_PLAN = 102;
    private const ADDON = 201;
    private const USER = 7;

    private $adapter;
    private $store;
    private FluentCartVolumeDiscount $discount;
    private bool $enabled = true;
    private int $current_user = self::USER;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('get_option')->alias(fn ($key, $default = false) => $key === VolumeDiscount::ENABLED_OPTION ? ($this->enabled ? 'on' : '') : $default);
        Functions\when('is_user_logged_in')->alias(fn () => $this->current_user > 0);
        Functions\when('get_current_user_id')->alias(fn () => $this->current_user);

        $this->adapter = Mockery::mock(FluentCartAdapter::class);
        $this->adapter->allows('is_plan_product')->andReturnUsing(fn ($id) => in_array($id, [self::PLAN, self::OTHER_PLAN], true));
        $this->store = Mockery::mock(FluentCartStore::class);
        $this->store->allows('get_cart_line_product_ids')->andReturnUsing(fn ($cart) => array_column($cart->cart_data, 'post_id'));
        $this->store->allows('make_coupon')->andReturnUsing(fn ($attributes) => (object) $attributes);

        $this->discount = new FluentCartVolumeDiscount($this->adapter, $this->store);
    }

    /** The signed-in user has these active subscriptions (product ids). */
    private function user_has(array $product_ids): void
    {
        $ids = array_keys($product_ids);
        $this->adapter->allows('get_customer_subscription_ids')->with(self::USER)->andReturn($ids);
        $this->store->allows('get_active_subscription_product_ids')->with($ids)->andReturn(array_values($product_ids));
    }

    private function cart(array $product_ids = [self::PLAN], array $coupons = []): FakeCart
    {
        return new FakeCart(array_map(fn ($id) => ['post_id' => $id], $product_ids), $coupons);
    }

    private function resolve($cart, array $codes, array $db_coupons = []): array
    {
        $resolved = $this->discount->resolve_coupons(new FakeCoupons($db_coupons), $codes, ['cart' => $cart]);

        return array_map(fn ($coupon) => $coupon->code, $resolved->items);
    }

    public function test_hooks_into_fluentcart(): void
    {
        $this->discount->register();

        $this->assertNotFalse(has_filter('fluent_cart/coupon/resolve_coupons', [$this->discount, 'resolve_coupons']));
        $this->assertNotFalse(has_filter('fluent_cart/coupon/will_skip_item', [$this->discount, 'skip_item']));
        $this->assertNotFalse(has_action('fluent_cart/cart/cart_data_items_updated', [$this->discount, 'sync_cart']));
        $this->assertNotFalse(has_filter('fluent_cart/checkout/before_patch_checkout_data', [$this->discount, 'sync_on_email_change']));
    }

    public function test_third_site_gets_ten_percent(): void
    {
        $this->user_has([11 => self::PLAN, 12 => self::OTHER_PLAN]);

        $this->assertSame('VOLUME10', $this->discount->cart_tier($this->cart())['code']);
        $this->assertSame(['VOLUME10'], $this->resolve($this->cart(), ['VOLUME10']));
    }

    public function test_second_site_gets_nothing(): void
    {
        $this->user_has([11 => self::PLAN]);

        $this->assertNull($this->discount->cart_tier($this->cart()));
        $this->assertSame([], $this->resolve($this->cart(), ['VOLUME10']));
    }

    public function test_a_higher_tier_code_than_the_customer_has_is_not_resolved(): void
    {
        $this->user_has([11 => self::PLAN, 12 => self::PLAN]);

        $this->assertSame([], $this->resolve($this->cart(), ['VOLUME20']));
        $this->assertSame([], $this->resolve($this->cart(), ['VOLUME30']));
    }

    public function test_tenth_and_twenty_fifth_sites(): void
    {
        $this->user_has(array_fill(1, 9, self::PLAN));
        $this->assertSame('VOLUME20', $this->discount->cart_tier($this->cart())['code']);

        $other_user = new FluentCartVolumeDiscount($this->adapter, $this->store);
        $this->adapter->allows('get_customer_subscription_ids')->with(8)->andReturn([500]);
        $this->store->allows('get_active_subscription_product_ids')->with([500])->andReturn(array_fill(0, 24, self::PLAN));
        $this->current_user = 8;
        $this->assertSame('VOLUME30', $other_user->cart_tier($this->cart())['code']);
    }

    public function test_only_website_plans_count_as_live_sites(): void
    {
        $this->user_has([11 => self::PLAN, 12 => self::ADDON, 13 => self::ADDON]);

        $this->assertNull($this->discount->cart_tier($this->cart()));
    }

    public function test_every_website_plan_in_the_cart_counts(): void
    {
        $this->user_has([11 => self::PLAN]);

        $this->assertSame('VOLUME10', $this->discount->cart_tier($this->cart([self::PLAN, self::OTHER_PLAN]))['code']);
    }

    public function test_no_discount_without_a_website_plan_in_the_cart(): void
    {
        $this->user_has([11 => self::PLAN, 12 => self::PLAN, 13 => self::PLAN]);

        $this->assertNull($this->discount->cart_tier($this->cart([self::ADDON])));
    }

    public function test_no_discount_while_turned_off(): void
    {
        $this->enabled = false;
        $this->adapter->expects('get_customer_subscription_ids')->never();

        $this->assertNull($this->discount->cart_tier($this->cart()));
        $this->assertSame([], $this->resolve($this->cart(), ['VOLUME10']));
    }

    public function test_saved_coupons_with_a_tier_code_are_never_used(): void
    {
        $this->enabled = false;

        $this->assertSame(['SUMMER'], $this->resolve($this->cart(), ['VOLUME10', 'SUMMER'], [(object) ['code' => 'VOLUME10'], (object) ['code' => 'SUMMER']]));
    }

    public function test_other_codes_are_left_alone(): void
    {
        $this->adapter->expects('get_customer_subscription_ids')->never();
        $db_coupons = new FakeCoupons([(object) ['code' => 'SUMMER']]);

        $this->assertSame($db_coupons, $this->discount->resolve_coupons($db_coupons, ['SUMMER'], ['cart' => $this->cart()]));
    }

    public function test_guest_is_matched_by_the_customer_fluentcart_guesses_from_the_email(): void
    {
        $this->current_user = 0;
        $cart = $this->cart();
        $this->store->allows('guess_cart_customer')->with($cart)->andReturn(['id' => 40, 'user_id' => 0, 'email' => 'buyer@example.com']);
        $this->store->allows('find_customer_subscription_ids')->with(40)->andReturn([21, 22]);
        $this->store->allows('get_active_subscription_product_ids')->with([21, 22])->andReturn([self::PLAN, self::PLAN]);

        $this->assertSame('VOLUME10', $this->discount->cart_tier($cart)['code']);
    }

    public function test_guest_matching_a_registered_customer_counts_that_account(): void
    {
        $this->current_user = 0;
        $cart = $this->cart();
        $this->store->allows('guess_cart_customer')->andReturn(['id' => 40, 'user_id' => self::USER, 'email' => 'buyer@example.com']);
        $this->user_has([11 => self::PLAN, 12 => self::PLAN]);

        $this->assertSame('VOLUME10', $this->discount->cart_tier($cart)['code']);
    }

    public function test_unknown_customer_gets_nothing(): void
    {
        $this->current_user = 0;
        $this->store->allows('guess_cart_customer')->andReturnNull();

        $this->assertNull($this->discount->cart_tier($this->cart()));
    }

    public function test_renewal_and_upgrade_carts_get_nothing(): void
    {
        $this->user_has([11 => self::PLAN, 12 => self::PLAN]);
        $locked = $this->cart();
        $locked->checkout_data = ['is_locked' => 'yes'];
        $upgrade = $this->cart();
        $upgrade->checkout_data = ['upgrade_data' => ['from' => 11]];

        $this->assertNull($this->discount->cart_tier($locked));
        $this->assertNull($this->discount->cart_tier($upgrade));
    }

    public function test_coupon_is_a_recurring_percentage_coupon(): void
    {
        $this->user_has([11 => self::PLAN, 12 => self::PLAN]);
        $coupon = $this->discount->resolve_coupons(new FakeCoupons([]), ['VOLUME10'], ['cart' => $this->cart()])->items[0];

        $this->assertSame('percentage', $coupon->type);
        $this->assertSame(10.0, $coupon->amount);
        $this->assertSame('active', $coupon->status);
        $this->assertSame('yes', $coupon->conditions['is_recurring']);
        $this->assertSame(0, $coupon->conditions['max_uses']);
    }

    public function test_placed_order_records_the_coupon(): void
    {
        $this->adapter->expects('get_customer_subscription_ids')->never();
        $resolved = $this->discount->resolve_coupons(new FakeCoupons([]), ['VOLUME20'], ['order' => (object) []]);

        $this->assertSame('VOLUME20', $resolved->items[0]->code);
    }

    public function test_nothing_is_resolved_without_a_cart_or_an_order(): void
    {
        $this->assertSame([], $this->discount->resolve_coupons(new FakeCoupons([]), ['VOLUME10'], ['cart' => null])->items);
        $this->assertSame([], $this->discount->resolve_coupons(new FakeCoupons([]), ['VOLUME10'])->items);
    }

    public function test_discount_skips_products_that_are_not_website_plans(): void
    {
        $volume = (object) ['code' => 'VOLUME10'];
        $other = (object) ['code' => 'SUMMER'];

        $this->assertTrue($this->discount->skip_item(false, ['coupon' => $volume, 'item' => ['post_id' => self::ADDON]]));
        $this->assertFalse($this->discount->skip_item(false, ['coupon' => $volume, 'item' => ['post_id' => self::PLAN]]));
        $this->assertFalse($this->discount->skip_item(false, ['coupon' => $other, 'item' => ['post_id' => self::ADDON]]));
        $this->assertTrue($this->discount->skip_item(true, ['coupon' => $volume, 'item' => ['post_id' => self::PLAN]]));
    }

    public function test_sync_applies_the_tier(): void
    {
        $this->user_has([11 => self::PLAN, 12 => self::PLAN]);
        $cart = $this->cart([self::PLAN], ['SUMMER']);

        $this->discount->sync_cart(['cart' => $cart, 'scope' => 'item_added', 'scope_data' => ['post_id' => self::PLAN, 'other_info' => ['payment_type' => 'subscription']]]);

        $this->assertSame([['apply', ['VOLUME10']]], $cart->calls);
    }

    public function test_sync_removes_the_discount_when_the_cart_stops_qualifying(): void
    {
        $this->user_has([11 => self::PLAN, 12 => self::PLAN]);
        $cart = $this->cart([self::ADDON], ['SUMMER', 'VOLUME10']);

        $this->discount->sync_cart(['cart' => $cart, 'scope' => 'item_removed']);

        $this->assertSame([['remove', ['VOLUME10']]], $cart->calls);
    }

    public function test_sync_swaps_tiers(): void
    {
        $this->user_has(array_fill(1, 9, self::PLAN));
        $cart = $this->cart([self::PLAN], ['VOLUME10']);

        $this->discount->sync_cart(['cart' => $cart, 'scope' => 'loading']);

        $this->assertSame([['remove', ['VOLUME10']], ['apply', ['VOLUME20']]], $cart->calls);
    }

    public function test_sync_leaves_a_cart_that_already_has_its_tier(): void
    {
        $this->user_has([11 => self::PLAN, 12 => self::PLAN]);
        $cart = $this->cart([self::PLAN], ['VOLUME10']);

        $this->discount->sync_cart(['cart' => $cart, 'scope' => 'loading']);

        $this->assertSame([], $cart->calls);
    }

    public function test_sync_ignores_unsaved_carts_and_its_own_changes(): void
    {
        $this->user_has([11 => self::PLAN, 12 => self::PLAN]);
        $unsaved = $this->cart();
        $unsaved->exists = false;
        $this->discount->sync_cart(['cart' => $unsaved]);
        $this->assertSame([], $unsaved->calls);

        $cart = $this->cart();
        $cart->on_apply = fn () => $this->discount->sync_cart(['cart' => $cart, 'scope' => 'apply_coupons', 'scope_data' => ['VOLUME10']]);
        $this->discount->sync_cart(['cart' => $cart, 'scope' => 'loading']);
        $this->assertSame([['apply', ['VOLUME10']]], $cart->calls);
    }

    public function test_removed_discount_stays_off_until_typed_back(): void
    {
        $this->user_has([11 => self::PLAN, 12 => self::PLAN]);
        $cart = $this->cart();

        $this->discount->sync_cart(['cart' => $cart, 'scope' => 'remove_coupon', 'scope_data' => ['VOLUME10']]);
        $this->discount->sync_cart(['cart' => $cart, 'scope' => 'item_added']);
        $this->assertSame([['save']], $cart->calls);

        $cart->coupons = ['VOLUME10'];
        $this->discount->sync_cart(['cart' => $cart, 'scope' => 'apply_coupons', 'scope_data' => 'VOLUME10']);
        $this->assertArrayNotHasKey(FluentCartVolumeDiscount::DECLINED, $cart->checkout_data);
    }

    public function test_guest_email_change_applies_the_discount_to_the_patched_checkout(): void
    {
        $this->current_user = 0;
        $cart = $this->cart();
        $this->store->allows('guess_cart_customer')->andReturnUsing(fn ($cart) => $cart->email === 'buyer@example.com' ? ['id' => 40, 'user_id' => 0, 'email' => $cart->email] : null);
        $this->store->allows('find_customer_subscription_ids')->with(40)->andReturn([21, 22]);
        $this->store->allows('get_active_subscription_product_ids')->with([21, 22])->andReturn([self::PLAN, self::PLAN]);
        $cart->on_apply = function () use ($cart) {
            $cart->coupons = ['VOLUME10'];
            $cart->cart_data[0]['coupon_discount'] = 1000;
            $cart->checkout_data['__per_coupon_discounts'] = ['VOLUME10' => 1000];
        };

        $fill = $this->discount->sync_on_email_change(
            ['cart_data' => [], 'checkout_data' => ['form_data' => ['billing_email' => 'buyer@example.com']], 'hook_changes' => []],
            ['cart' => $cart, 'changes' => ['billing_email' => 'buyer@example.com']]
        );

        $this->assertSame(1000, $fill['cart_data'][0]['coupon_discount']);
        $this->assertSame(['VOLUME10' => 1000], $fill['checkout_data']['__per_coupon_discounts']);
        $this->assertSame('buyer@example.com', $fill['checkout_data']['form_data']['billing_email']);
        $this->assertTrue($fill['hook_changes']['waas_volume_discount']);
    }

    public function test_signed_in_customer_is_not_rematched_by_email(): void
    {
        $cart = $this->cart();
        $fill = ['cart_data' => [], 'checkout_data' => [], 'hook_changes' => []];

        $this->assertSame($fill, $this->discount->sync_on_email_change($fill, ['cart' => $cart, 'changes' => ['billing_email' => 'someone@example.com']]));
        $this->assertSame([], $cart->calls);
    }
}

/** The parts of FluentCart's Cart the discount uses, recording coupon changes. */
class FakeCart
{
    public bool $exists = true;
    public array $cart_data;
    public array $coupons;
    public array $checkout_data = [];
    public int $user_id = 0;
    public string $email = '';
    public array $calls = [];
    /** @var callable|null */
    public $on_apply = null;

    public function __construct(array $cart_data, array $coupons)
    {
        $this->cart_data = $cart_data;
        $this->coupons = $coupons;
    }

    public function applyCoupon($codes)
    {
        $this->calls[] = ['apply', $codes];
        if ($this->on_apply) {
            ($this->on_apply)();
        }

        return [];
    }

    public function removeCoupon($codes)
    {
        $this->calls[] = ['remove', $codes];

        return $this;
    }

    public function save()
    {
        $this->calls[] = ['save'];
    }
}

/** The parts of FluentCart's Collection resolve_coupons uses. */
class FakeCoupons
{
    public array $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function filter(callable $callback): self
    {
        return new self(array_filter($this->items, $callback));
    }

    public function values(): self
    {
        return new self(array_values($this->items));
    }

    public function push($item): self
    {
        $this->items[] = $item;

        return $this;
    }
}

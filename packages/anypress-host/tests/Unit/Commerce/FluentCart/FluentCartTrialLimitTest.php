<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use AnyPressHost\Commerce\FluentCart\FluentCartStore;
use AnyPressHost\Commerce\FluentCart\FluentCartTrialLimit;

/** One free trial per person: a plan's free days only for someone who never had a plan. */
class FluentCartTrialLimitTest extends TestCase
{
    private const PLAN = 101;
    private const PACK = 260;

    private $store;
    private FluentCartTrialLimit $limit;
    private int $user_id = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('sanitize_email')->alias(fn ($v) => trim((string) $v));
        Functions\when('get_current_user_id')->alias(fn () => $this->user_id);
        Functions\when('get_userdata')->justReturn((object) ['user_email' => 'member@anypress.example']);

        $this->store = Mockery::mock(FluentCartStore::class);
        $this->store->allows('get_plan_feed')->andReturnUsing(fn ($id) => $id === self::PLAN ? ['role' => 'website'] : null);
        $this->limit = new FluentCartTrialLimit($this->store);
    }

    public function test_a_first_trial_goes_through(): void
    {
        $this->store->allows('get_cart_trial_product_ids')->andReturn([self::PLAN]);
        $this->store->expects('has_had_plan')->with(0, 'new@anypress.example')->andReturn(false);
        $this->store->expects('remove_cart_trials')->never();

        $this->assertSame([], $this->limit->validate_checkout([], ['cart' => (object) [], 'data' => ['billing_email' => 'new@anypress.example']]));
    }

    public function test_someone_who_had_a_plan_loses_the_trial_and_is_asked_to_reload(): void
    {
        $this->store->allows('get_cart_trial_product_ids')->andReturn([self::PLAN, self::PACK]);
        $this->store->expects('has_had_plan')->with(0, 'back@anypress.example')->andReturn(true);
        $this->store->expects('remove_cart_trials')->withArgs(fn ($cart, $ids) => $ids === [self::PLAN]);

        $errors = $this->limit->validate_checkout([], ['cart' => (object) [], 'data' => ['billing_email' => 'back@anypress.example']]);
        $this->assertStringContainsString('reload the page', $errors['anypress_trial']['used']);
    }

    public function test_a_signed_in_customer_is_found_by_their_account(): void
    {
        $this->user_id = 7;
        $this->store->allows('get_cart_trial_product_ids')->andReturn([self::PLAN]);
        $this->store->expects('has_had_plan')->with(7, 'member@anypress.example')->andReturn(true);
        $this->store->allows('remove_cart_trials');

        $this->assertArrayHasKey('anypress_trial', $this->limit->validate_checkout([], ['cart' => (object) [], 'data' => []]));
    }

    public function test_carts_without_a_plan_trial_are_left_alone(): void
    {
        $this->store->allows('get_cart_trial_product_ids')->andReturn([], [self::PACK]);
        $this->store->expects('has_had_plan')->never();

        $this->assertSame(['x' => 1], $this->limit->validate_checkout(['x' => 1], ['cart' => (object) [], 'data' => []]));
        $this->assertSame([], $this->limit->validate_checkout([], ['cart' => (object) [], 'data' => []]));
        $this->assertSame([], $this->limit->validate_checkout([], ['data' => []]), 'no cart');
    }
}

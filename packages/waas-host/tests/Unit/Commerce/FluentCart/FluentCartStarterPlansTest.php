<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\FluentCart\FluentCartStarterPlans;
use WaaSHost\Commerce\FluentCart\FluentCartStore;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Features\PluginBootstrap;

class FluentCartStarterPlansTest extends TestCase
{
    private $store;
    private FluentCartStarterPlans $plans;
    private $roles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('get_option')->alias(fn ($name, $default = false) => $name === PluginBootstrap::ROLES_WP_OPTION ? $this->roles : $default);
        Functions\when('admin_url')->alias(fn ($path = '') => 'https://shop.example/wp-admin/' . $path);
        Functions\when('wp_nonce_url')->alias(fn ($url) => $url . '&_wpnonce=n');
        $this->store = Mockery::mock(FluentCartStore::class);
        $this->plans = new FluentCartStarterPlans(Mockery::mock(ProvisioningApi::class), $this->store);
    }

    private function step(): array
    {
        return ['create_woo_waas_product' => ['label' => 'old', 'description' => 'old', 'action_label' => 'Add a plan', 'action_url' => 'products', 'is_done' => false]];
    }

    public function test_only_plans_whose_role_is_loaded_are_made_and_the_old_store_role_is_left_out(): void
    {
        $this->roles = (object) ['website' => (object) ['title' => 'Website'], 'everything' => (object) [], 'store' => (object) [], 'custom' => (object) []];

        $this->assertSame(['website', 'everything'], array_keys(FluentCartStarterPlans::available()));
    }

    public function test_the_plan_step_offers_to_make_every_size_of_every_loaded_plan(): void
    {
        $this->roles = ['website' => [], 'bookings' => []];

        $item = $this->plans->checklist($this->step())['create_woo_waas_product'];

        $this->assertSame('Create my plans', $item['action_label']);
        $this->assertStringContainsString('admin-post.php?action=waas_create_plans', $item['action_url']);
        $this->assertStringContainsString('Makes 6 ready-to-sell plans', $item['description']);
    }

    public function test_without_loaded_plans_or_once_done_the_step_is_left_alone(): void
    {
        $this->assertSame($this->step(), $this->plans->checklist($this->step()));

        $this->roles = ['website' => []];
        $done = $this->step();
        $done['create_woo_waas_product']['is_done'] = true;
        $this->assertSame($done, $this->plans->checklist($done));
    }

    public function test_the_header_button_opens_the_plans_once_they_exist(): void
    {
        $this->store->allows('has_plan_feed')->andReturn(true);

        $this->assertSame(['Website plans', 'https://shop.example/wp-admin/admin.php?page=fluent-cart#/products'], $this->plans->plan_button(['Add a website plan', 'x']));
    }

    public function test_the_header_button_makes_the_plans_when_there_are_none(): void
    {
        $this->store->allows('has_plan_feed')->andReturn(false);
        $this->assertSame(['Add a website plan', 'x'], $this->plans->plan_button(['Add a website plan', 'x']));

        $this->roles = ['website' => []];
        $this->assertSame('Create my plans', $this->plans->plan_button(['Add a website plan', 'x'])[0]);
    }

    public function test_prices_follow_the_shop_small_medium_large_and_ten_months_a_year(): void
    {
        $this->assertSame(['small' => 1, 'medium' => 2, 'large' => 4], FluentCartStarterPlans::SIZES);
        $this->assertSame(1200, FluentCartStarterPlans::catalogue()['website'][1]);
        $this->assertSame(7900, FluentCartStarterPlans::catalogue()['everything'][1]);
    }
}

<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Functions;
use Mockery;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\PluginBootstrap;

class PluginBootstrapTest extends TestCase
{
    private const PLAN = 10;
    private const ADDON = 20;

    private PluginBootstrap $bootstrap;
    private FakeCommerceAdapter $cart;

    protected function setUp(): void
    {
        parent::setUp();
        Commerce::reset();
        $this->cart = new FakeCommerceAdapter();
        Commerce::register($this->cart);
        $this->cart->meta[500] = [Tenant::TENANT_EXTERNAL_ID_META => 'store-1042'];
        Functions\when('wp_get_post_terms')->alias(fn ($id) => $id === self::ADDON ? ['add-on'] : []);
        $this->bootstrap = new PluginBootstrap();
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    private function item(int $subscription_id, int $product_id)
    {
        if (!class_exists('WC_Order_Item_Product')) {
            eval('class WC_Order_Item_Product { public function get_order_id() {} public function get_product_id() {} }');
        }
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->allows('get_order_id')->andReturn($subscription_id);
        $item->allows('get_product_id')->andReturn($product_id);

        return $item;
    }

    public function test_on_a_subscription_with_a_store_only_add_ons_can_be_removed(): void
    {
        $this->assertFalse($this->bootstrap->only_addons_can_be_removed_from_subscription(true, $this->item(500, self::PLAN)));
        $this->assertTrue($this->bootstrap->only_addons_can_be_removed_from_subscription(true, $this->item(500, self::ADDON)));
    }

    public function test_other_subscriptions_keep_their_own_rules(): void
    {
        // M26: this used to make every non-add-on item in the whole shop unremovable.
        $this->assertTrue($this->bootstrap->only_addons_can_be_removed_from_subscription(true, $this->item(501, self::PLAN)));
    }

    public function test_an_earlier_no_or_a_null_is_passed_through(): void
    {
        $this->assertFalse($this->bootstrap->only_addons_can_be_removed_from_subscription(false, $this->item(500, self::ADDON)));
        $this->assertNull($this->bootstrap->only_addons_can_be_removed_from_subscription(null, $this->item(500, self::ADDON)));
    }

    public function test_add_ons_are_left_out_of_shortcodes_and_related_products(): void
    {
        $args = $this->bootstrap->hide_addon_products_from_shortcodes(['post_type' => 'product']);
        $related = $this->bootstrap->hide_addon_products_from_related([self::PLAN, self::ADDON, 30]);

        $this->assertSame(['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => 'add-on', 'operator' => 'NOT IN'], $args['tax_query'][0]);
        $this->assertSame([self::PLAN, 30], $related);
    }

    public function test_add_ons_are_left_out_of_every_catalog_page_not_just_the_shop(): void
    {
        $query = Mockery::mock();
        $query->allows('get')->with('tax_query')->andReturn([['taxonomy' => 'product_visibility']]);
        $query->expects('set')->with('tax_query', Mockery::on(fn ($tax) => count($tax) === 2 && $tax[1]['terms'] === 'add-on'));
        Functions\expect('is_shop')->never();

        $this->bootstrap->hide_addon_products_from_shop_page($query);
    }

    public function test_add_ons_are_left_out_of_the_site_search_only(): void
    {
        Functions\when('is_admin')->justReturn(false);
        $search = Mockery::mock();
        $search->allows(['is_main_query' => true, 'is_search' => true, 'get' => []]);
        $search->expects('set')->with('tax_query', [['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => 'add-on', 'operator' => 'NOT IN']]);
        $blog = Mockery::mock();
        $blog->allows(['is_main_query' => true, 'is_search' => false]);
        $blog->expects('set')->never();

        $this->bootstrap->hide_addon_products_from_search($search);
        $this->bootstrap->hide_addon_products_from_search($blog);
    }
}

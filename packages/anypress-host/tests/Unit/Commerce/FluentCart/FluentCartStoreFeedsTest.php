<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use Tests\Unit\TestCase;
use AnyPressHost\Commerce\FluentCart\FluentCartAdapter;
use AnyPressHost\Commerce\FluentCart\FluentCartStore;
use AnyPressHost\Commerce\FluentCart\AnyPressProvisioningIntegration;

/** FluentCartStore's reading of AnyPress feeds: website plans and email packs, over stubbed FluentCart models. */
class FluentCartStoreFeedsTest extends TestCase
{
    private const PLAN = 101;
    private const LEGACY_PLAN = 102;
    private const PACK = 301;
    private const BIG_PACK = 302;
    private const DRAFT_PACK = 303;
    private const CARE_PLUS = 401;
    private const CARE = 402;
    private const SETUP = 501;

    private FluentCartStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_post_status')->alias(fn ($id) => $id === self::DRAFT_PACK ? 'draft' : 'publish');
        Functions\when('get_the_title')->alias(fn ($id) => "Product $id");
        Functions\when('get_the_excerpt')->justReturn('');
        Functions\when('get_post_field')->alias(fn ($field, $id) => $id === self::CARE_PLUS ? 2 : 1);
        Functions\when('site_url')->alias(fn ($path = '') => 'https://storefront.example/' . ltrim($path, '/'));

        ProductMeta::$rows = [
            $this->feed(self::PLAN, ['enabled' => 'yes', 'product_type' => 'plan', 'role' => 'peptide-store', 'emails_per_pack' => '']),
            $this->feed(self::LEGACY_PLAN, ['enabled' => 'yes', 'role' => 'starter']),
            $this->feed(self::BIG_PACK, ['enabled' => 'yes', 'product_type' => 'email_pack', 'emails_per_pack' => 50000]),
            $this->feed(self::PACK, ['enabled' => 'yes', 'product_type' => 'email_pack', 'emails_per_pack' => '5000']),
            $this->feed(self::DRAFT_PACK, ['enabled' => 'yes', 'product_type' => 'email_pack', 'emails_per_pack' => 1000]),
            $this->feed(304, ['enabled' => 'no', 'product_type' => 'email_pack', 'emails_per_pack' => 1000]),
            $this->feed(305, ['enabled' => 'yes', 'product_type' => 'email_pack', 'emails_per_pack' => '']), // no emails: not sold
            $this->feed(self::CARE_PLUS, ['enabled' => 'yes', 'product_type' => 'care', 'care_minutes' => '120', 'care_answer' => '1 business day']),
            $this->feed(self::CARE, ['enabled' => 'yes', 'product_type' => 'care', 'care_minutes' => '30', 'care_answer' => '2 business days']),
            $this->feed(self::SETUP, ['enabled' => 'yes', 'product_type' => 'service', 'service_minutes' => '']),
        ];
        ProductVariation::$rows = [
            (object) ['id' => 32, 'post_id' => self::BIG_PACK, 'serial_index' => 1],
            (object) ['id' => 33, 'post_id' => self::PACK, 'serial_index' => 2],
            (object) ['id' => 31, 'post_id' => self::PACK, 'serial_index' => 1],
            (object) ['id' => 34, 'post_id' => self::DRAFT_PACK, 'serial_index' => 1],
            (object) ['id' => 42, 'post_id' => self::CARE, 'serial_index' => 2, 'variation_title' => 'Yearly', 'item_price' => 79000.0, 'payment_type' => 'subscription', 'other_info' => ['repeat_interval' => 'yearly']],
            (object) ['id' => 41, 'post_id' => self::CARE, 'serial_index' => 1, 'variation_title' => 'Monthly', 'item_price' => 7900.0, 'payment_type' => 'subscription', 'other_info' => ['repeat_interval' => 'monthly']],
            (object) ['id' => 43, 'post_id' => self::CARE_PLUS, 'serial_index' => 1, 'variation_title' => 'Monthly', 'item_price' => 19900.0, 'payment_type' => 'subscription', 'other_info' => ['repeat_interval' => 'monthly']],
            (object) ['id' => 51, 'post_id' => self::SETUP, 'serial_index' => 1, 'variation_title' => 'Once', 'item_price' => 29900.0, 'payment_type' => 'onetime', 'other_info' => []],
        ];
        $this->store = new FluentCartStore();
    }

    protected function tearDown(): void
    {
        ProductMeta::$rows = [];
        ProductVariation::$rows = [];
        parent::tearDown();
    }

    private function feed(int $product_id, array $settings): object
    {
        return (object) ['object_id' => $product_id, 'object_type' => 'product_integration', 'meta_key' => AnyPressProvisioningIntegration::KEY, 'meta_value' => $settings];
    }

    public function test_plans_old_and_new_are_plan_feeds(): void
    {
        $this->assertSame('peptide-store', $this->store->get_plan_feed(self::PLAN)['role']);
        $this->assertSame('starter', $this->store->get_plan_feed(self::LEGACY_PLAN)['role']);
        $this->assertNull($this->store->get_email_pack_feed(self::PLAN));
        $this->assertNull($this->store->get_email_pack_feed(self::LEGACY_PLAN));
    }

    public function test_an_email_pack_is_never_a_website_plan(): void
    {
        $this->assertNull($this->store->get_plan_feed(self::PACK));
        $this->assertSame('5000', $this->store->get_email_pack_feed(self::PACK)['emails_per_pack']);
        $this->assertNull($this->store->get_plan_feed(305), 'a pack without emails is not a plan either');
        $this->assertNull($this->store->get_email_pack_feed(305));
        $this->assertNull($this->store->get_email_pack_feed(304), 'disabled');
    }

    public function test_the_adapter_checkout_and_volume_discount_see_a_pack_as_no_plan(): void
    {
        // FluentCartCheckout (website name field) and FluentCartVolumeDiscount (counting and discounting) both ask this.
        $adapter = new FluentCartAdapter($this->store);

        $this->assertTrue($adapter->is_plan_product(self::PLAN));
        $this->assertTrue($adapter->is_plan_product(self::LEGACY_PLAN));
        $this->assertFalse($adapter->is_plan_product(self::PACK));
        $this->assertFalse($adapter->is_plan_product(self::BIG_PACK));
    }

    public function test_a_shop_with_only_email_packs_has_no_plan_product(): void
    {
        $this->assertTrue($this->store->has_plan_feed());

        ProductMeta::$rows = array_values(array_filter(ProductMeta::$rows, fn ($row) => ($row->meta_value['product_type'] ?? '') === 'email_pack'));

        $this->assertFalse($this->store->has_plan_feed());
    }

    public function test_pack_products_are_the_published_ones_fewest_emails_first_with_their_first_variations_checkout(): void
    {
        $this->assertSame([
            [
                'product_id' => self::PACK,
                'emails' => 5000,
                'title' => 'Product 301',
                'checkout_url' => 'https://storefront.example/?fluent-cart=instant_checkout&item_id=31&quantity=1',
            ],
            [
                'product_id' => self::BIG_PACK,
                'emails' => 50000,
                'title' => 'Product 302',
                'checkout_url' => 'https://storefront.example/?fluent-cart=instant_checkout&item_id=32&quantity=1',
            ],
        ], $this->store->get_email_pack_products());
    }

    public function test_care_packages_are_never_plans_and_come_in_menu_order_with_every_variation(): void
    {
        $this->assertNull($this->store->get_plan_feed(self::CARE));
        $this->assertNull($this->store->get_email_pack_feed(self::CARE));
        $this->assertSame('30', $this->store->get_feed_of_type(self::CARE, 'care')['care_minutes']);
        $this->assertNull($this->store->get_feed_of_type(self::CARE, 'service'));

        $packages = $this->store->get_typed_products('care');
        $this->assertSame([self::CARE, self::CARE_PLUS], array_column($packages, 'product_id'));
        $this->assertSame([
            ['id' => 41, 'title' => 'Monthly', 'price' => 7900, 'interval' => 'monthly', 'checkout_url' => 'https://storefront.example/?fluent-cart=instant_checkout&item_id=41&quantity=1'],
            ['id' => 42, 'title' => 'Yearly', 'price' => 79000, 'interval' => 'yearly', 'checkout_url' => 'https://storefront.example/?fluent-cart=instant_checkout&item_id=42&quantity=1'],
        ], $packages[0]['variations']);
        $this->assertSame('', $this->store->get_typed_products('service')[0]['variations'][0]['interval']);
    }

    public function test_packs_care_and_services_are_bought_for_a_website_and_plans_are_not(): void
    {
        $this->assertTrue($this->store->is_website_addon(self::PACK));
        $this->assertTrue($this->store->is_website_addon(self::CARE));
        $this->assertTrue($this->store->is_website_addon(self::SETUP));
        $this->assertFalse($this->store->is_website_addon(self::PLAN));
        $this->assertFalse($this->store->is_website_addon(self::LEGACY_PLAN));
        $this->assertFalse($this->store->is_website_addon(305));

        ProductMeta::$rows = array_values(array_filter(ProductMeta::$rows, fn ($row) => in_array($row->meta_value['product_type'] ?? '', ['care', 'service'], true)));
        $this->assertFalse($this->store->has_plan_feed());
    }

    public function test_cart_checkout_values_are_set_cleared_and_saved_only_on_change(): void
    {
        $cart = new class {
            public $checkout_data = ['other' => 1];
            public int $saves = 0;

            public function save(): void
            {
                $this->saves++;
            }
        };

        $this->store->set_cart_checkout_value($cart, 'anypress_email_pack_website', 900);
        $this->store->set_cart_checkout_value($cart, 'anypress_email_pack_website', 900);
        $this->assertSame(900, $this->store->get_cart_checkout_value($cart, 'anypress_email_pack_website'));
        $this->assertSame(1, $cart->saves);

        $this->store->set_cart_checkout_value($cart, 'anypress_email_pack_website', null);
        $this->store->set_cart_checkout_value($cart, 'anypress_email_pack_website', null);
        $this->assertNull($this->store->get_cart_checkout_value($cart, 'anypress_email_pack_website'));
        $this->assertSame(['other' => 1], $cart->checkout_data);
        $this->assertSame(2, $cart->saves);
    }
}

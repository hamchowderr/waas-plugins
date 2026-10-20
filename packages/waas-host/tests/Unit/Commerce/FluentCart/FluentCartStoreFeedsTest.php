<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\FluentCart\FluentCartAdapter;
use WaaSHost\Commerce\FluentCart\FluentCartStore;
use WaaSHost\Commerce\FluentCart\WaasProvisioningIntegration;

/** FluentCartStore's reading of WaaS feeds: website plans and email packs, over stubbed FluentCart models. */
class FluentCartStoreFeedsTest extends TestCase
{
    private const PLAN = 101;
    private const LEGACY_PLAN = 102;
    private const PACK = 301;
    private const BIG_PACK = 302;
    private const DRAFT_PACK = 303;

    private FluentCartStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_post_status')->alias(fn ($id) => $id === self::DRAFT_PACK ? 'draft' : 'publish');
        Functions\when('get_the_title')->alias(fn ($id) => "Product $id");
        Functions\when('site_url')->alias(fn ($path = '') => 'https://storefront.example/' . ltrim($path, '/'));

        ProductMeta::$rows = [
            $this->feed(self::PLAN, ['enabled' => 'yes', 'product_type' => 'plan', 'role' => 'peptide-store', 'emails_per_pack' => '']),
            $this->feed(self::LEGACY_PLAN, ['enabled' => 'yes', 'role' => 'starter']),
            $this->feed(self::BIG_PACK, ['enabled' => 'yes', 'product_type' => 'email_pack', 'emails_per_pack' => 50000]),
            $this->feed(self::PACK, ['enabled' => 'yes', 'product_type' => 'email_pack', 'emails_per_pack' => '5000']),
            $this->feed(self::DRAFT_PACK, ['enabled' => 'yes', 'product_type' => 'email_pack', 'emails_per_pack' => 1000]),
            $this->feed(304, ['enabled' => 'no', 'product_type' => 'email_pack', 'emails_per_pack' => 1000]),
            $this->feed(305, ['enabled' => 'yes', 'product_type' => 'email_pack', 'emails_per_pack' => '']), // no emails: not sold
        ];
        ProductVariation::$rows = [
            (object) ['id' => 32, 'post_id' => self::BIG_PACK, 'serial_index' => 1],
            (object) ['id' => 33, 'post_id' => self::PACK, 'serial_index' => 2],
            (object) ['id' => 31, 'post_id' => self::PACK, 'serial_index' => 1],
            (object) ['id' => 34, 'post_id' => self::DRAFT_PACK, 'serial_index' => 1],
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
        return (object) ['object_id' => $product_id, 'object_type' => 'product_integration', 'meta_key' => WaasProvisioningIntegration::KEY, 'meta_value' => $settings];
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

        $this->store->set_cart_checkout_value($cart, 'waas_email_pack_website', 900);
        $this->store->set_cart_checkout_value($cart, 'waas_email_pack_website', 900);
        $this->assertSame(900, $this->store->get_cart_checkout_value($cart, 'waas_email_pack_website'));
        $this->assertSame(1, $cart->saves);

        $this->store->set_cart_checkout_value($cart, 'waas_email_pack_website', null);
        $this->store->set_cart_checkout_value($cart, 'waas_email_pack_website', null);
        $this->assertNull($this->store->get_cart_checkout_value($cart, 'waas_email_pack_website'));
        $this->assertSame(['other' => 1], $cart->checkout_data);
        $this->assertSame(2, $cart->saves);
    }
}

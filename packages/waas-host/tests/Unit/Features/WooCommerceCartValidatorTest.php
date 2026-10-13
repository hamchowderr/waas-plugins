<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Features\WooCommerceCartValidator;

/**
 * Add-on status comes from the "add-on" category only (M25: the old check read a type meta the product screen
 * never set, so it never fired).
 */
class WooCommerceCartValidatorTest extends TestCase
{
    private const PLAN = 10;
    private const ADDON = 20;
    private const T_SHIRT = 30;

    private WooCommerceCartValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('WAAS_HOST_TEXTDOMAIN')) {
            define('WAAS_HOST_TEXTDOMAIN', 'waas-host');
        }
        Functions\stubTranslationFunctions();
        Functions\when('wp_get_post_terms')->alias(fn ($id) => $id === self::ADDON ? ['add-on'] : ['uncategorized']);
        Functions\when('wc_get_product')->alias(fn ($id) => new \WC_Product($id, in_array($id, [self::PLAN, self::ADDON], true) ? ['_is_wpcs_product' => 'yes'] : []));
        $this->validator = new WooCommerceCartValidator();
    }

    private static function items(int ...$product_ids): array
    {
        return array_map(fn ($id) => ['product_id' => $id], $product_ids);
    }

    public function test_an_add_on_alone_is_refused(): void
    {
        $this->assertTrue($this->validator->has_addon_without_base_product(self::items(self::ADDON)));
        $this->assertTrue($this->validator->has_addon_without_base_product(self::items(self::ADDON, self::T_SHIRT)));
    }

    public function test_an_add_on_with_a_plan_or_no_add_on_is_fine(): void
    {
        $this->assertFalse($this->validator->has_addon_without_base_product(self::items(self::ADDON, self::PLAN)));
        $this->assertFalse($this->validator->has_addon_without_base_product(self::items(self::T_SHIRT)));
        $this->assertFalse($this->validator->has_addon_without_base_product([]));
    }

    public function test_the_block_checkout_gets_the_error_through_the_store_api(): void
    {
        $cart = Mockery::mock();
        $cart->allows('get_cart')->andReturn(self::items(self::ADDON));
        $errors = Mockery::mock();
        $errors->expects('add')->once()->with(WooCommerceCartValidator::ERROR_CODE, Mockery::type('string'));

        $this->validator->validate_store_api_cart($errors, $cart);
    }

    public function test_the_classic_checkout_gets_an_error_notice(): void
    {
        $cart = Mockery::mock();
        $cart->allows('get_cart')->andReturn(self::items(self::ADDON));
        Functions\when('WC')->justReturn((object) ['cart' => $cart]);
        Functions\expect('wc_add_notice')->once()->with(Mockery::type('string'), 'error');

        $this->validator->validate_cart_contents();
    }
}

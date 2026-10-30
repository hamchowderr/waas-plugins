<?php

namespace WaaSHost\Features;

use WaaSHost\Core\StoreProduct;

/**
 * An add-on can only be bought together with a website plan. Checked for the classic cart and checkout, and for
 * the block checkout (Store API), which doesn't run woocommerce_check_cart_items.
 */
class WooCommerceCartValidator
{
    public const ERROR_CODE = 'waas_addon_without_base_product';

    public function __construct()
    {
        add_action('woocommerce_check_cart_items', [$this, 'validate_cart_contents']);
        add_action('woocommerce_store_api_cart_errors', [$this, 'validate_store_api_cart'], 10, 2);
    }

    public function validate_cart_contents()
    {
        $cart = \WC()->cart;
        if ($cart && $this->has_addon_without_base_product($cart->get_cart())) {
            // Needs to be of type error, otherwise checkout is not prevented.
            \wc_add_notice(self::message(), 'error');
        }
    }

    /**
     * @param \WP_Error $errors
     * @param \WC_Cart $cart
     */
    public function validate_store_api_cart($errors, $cart)
    {
        if ($cart && $this->has_addon_without_base_product($cart->get_cart())) {
            $errors->add(self::ERROR_CODE, self::message());
        }
    }

    /** @param array $items cart items, each with a product_id */
    public function has_addon_without_base_product(array $items): bool
    {
        $has_addon = false;
        $has_base_product = false;

        foreach ($items as $item) {
            $product_id = (int) ($item['product_id'] ?? 0);
            if (AddonProductCategory::is_product_addon($product_id)) {
                $has_addon = true;
            } elseif ((new StoreProduct($product_id))->is_store_product()) {
                $has_base_product = true;
            }
        }

        return $has_addon && !$has_base_product;
    }

    private static function message(): string
    {
        return __('Cannot proceed to checkout with an Add-on but no base product. Please add a base product to your cart.', 'waas-host');
    }
}

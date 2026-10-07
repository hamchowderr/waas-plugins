<?php

namespace WaaSHost\Features;

/**
 * Add-ons are the products in the "add-on" product category. That category is the only place add-on status is
 * kept: the cart check, the shop listing and the subscription item rules all read it from here.
 */
class AddonProductCategory
{
    public const TERM_SLUG = 'add-on';
    public const TERM_TAX_SLUG = 'product_cat';

    public static function is_product_addon($product_id): bool
    {
        $product_categories = wp_get_post_terms((int) $product_id, self::TERM_TAX_SLUG, ['fields' => 'slugs']);

        return is_array($product_categories) && in_array(self::TERM_SLUG, $product_categories, true);
    }
}

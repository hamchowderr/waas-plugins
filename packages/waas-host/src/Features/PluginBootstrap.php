<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\WPCSTenant;

class PluginBootstrap
{
    const ROLES_WP_OPTION = 'waas-host-roles-options';
    const API_V1_NAMESPACE = 'waas-host/v1';

    public function __construct()
    {
        // Add-ons are bought from the subscription page, not browsed: keep them out of the catalog listings.
        add_action('woocommerce_product_query', [$this, 'hide_addon_products_from_shop_page']);
        add_action('pre_get_posts', [$this, 'hide_addon_products_from_search']);
        add_filter('woocommerce_shortcode_products_query', [$this, 'hide_addon_products_from_shortcodes']);
        add_filter('woocommerce_related_products', [$this, 'hide_addon_products_from_related'], 10, 1);
        add_filter('wcs_can_item_be_removed', [$this, 'only_addons_can_be_removed_from_subscription'], 10, 2);
    }

    /** Shop, category, tag and product search pages (WooCommerce's main product query). */
    function hide_addon_products_from_shop_page($q)
    {
        $q->set('tax_query', array_merge((array) $q->get('tax_query'), [self::exclude_addons()]));
    }

    /** The site search, which doesn't go through WooCommerce's product query. Other content is unaffected. */
    public function hide_addon_products_from_search($q)
    {
        if (is_admin() || !$q->is_main_query() || !$q->is_search()) {
            return;
        }

        $q->set('tax_query', array_merge((array) $q->get('tax_query'), [self::exclude_addons()]));
    }

    /** [products] and the other product shortcodes. */
    public function hide_addon_products_from_shortcodes($query_args)
    {
        $query_args['tax_query'] = array_merge((array) ($query_args['tax_query'] ?? []), [self::exclude_addons()]);

        return $query_args;
    }

    /** @param int[] $related_ids */
    public function hide_addon_products_from_related($related_ids)
    {
        return array_values(array_filter((array) $related_ids, fn ($id) => !AddonProductCategory::is_product_addon($id)));
    }

    /**
     * On a subscription with a store, only add-ons can be removed: removing the plan would leave the store without
     * one. Other subscriptions keep WooCommerce Subscriptions' own rules.
     *
     * @param mixed $can_remove
     * @param \WC_Order_Item_Product $item
     */
    public function only_addons_can_be_removed_from_subscription($can_remove, $item)
    {
        // Honour anyone who already said no.
        if (!$can_remove || !$item instanceof \WC_Order_Item_Product) {
            return $can_remove;
        }

        $commerce = Commerce::adapter();
        if ($commerce === null || empty($commerce->get_subscription_meta($item->get_order_id(), WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META))) {
            return $can_remove;
        }

        return AddonProductCategory::is_product_addon($item->get_product_id());
    }

    private static function exclude_addons(): array
    {
        return [
            'taxonomy' => AddonProductCategory::TERM_TAX_SLUG,
            'field' => 'slug',
            'terms' => AddonProductCategory::TERM_SLUG,
            'operator' => 'NOT IN',
        ];
    }
}

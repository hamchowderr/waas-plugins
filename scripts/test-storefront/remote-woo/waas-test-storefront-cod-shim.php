<?php
/**
 * Plugin Name: WaaS test storefront shim: pay later for subscriptions
 * Description: TEST STOREFRONT SHIM, never for a real store. Installed by scripts/test-storefront (--cart woo). Lets WP Swings' Subscriptions for WooCommerce accept Cash on delivery, so the
 * subscription lifecycle can be tested by hand without a card gateway: an admin marks the order paid (or failed).
 */
function waas_test_cod_for_subscriptions($supported, $gateway_id)
{
    if ($gateway_id === 'cod') {
        $supported[] = 'cod';
    }

    return $supported;
}
add_filter('wps_sfw_supported_payment_gateway_for_woocommerce', 'waas_test_cod_for_subscriptions', 10, 2);

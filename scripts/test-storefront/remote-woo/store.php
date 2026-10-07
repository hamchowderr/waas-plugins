<?php
// WooCommerce store basics, its pages, and WP Swings switched on. Idempotent.
update_option('woocommerce_currency', 'USD');
update_option('woocommerce_default_country', 'US:CA');
// Open the shop (new stores start in "coming soon" mode) and skip the setup wizard.
update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_store_pages_only', 'no');
update_option('woocommerce_onboarding_profile', ['skipped' => true]);
update_option('woocommerce_task_list_hidden', 'yes');
// A subscription needs an account: create one at checkout.
update_option('woocommerce_enable_guest_checkout', 'no');
update_option('woocommerce_enable_signup_and_login_from_checkout', 'yes');
update_option('woocommerce_registration_generate_password', 'yes');
update_option('woocommerce_demo_store', 'yes');
update_option('woocommerce_demo_store_notice', 'Test storefront. Payments run in test mode; nothing is charged.');
\WC_Install::create_pages();

// WP Swings does nothing until it is enabled; customers may cancel from My Account.
update_option('wps_sfw_enable_plugin', 'on');
update_option('wps_sfw_cancel_subscription_for_customer', 'on');

foreach (['shop', 'cart', 'checkout', 'myaccount'] as $page) {
    echo $page . ': ' . wc_get_page_permalink($page) . "\n";
}
echo 'coming soon: ' . get_option('woocommerce_coming_soon') . ', wp-swings enabled: ' . get_option('wps_sfw_enable_plugin') . "\n";
echo 'hpos: ' . (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off') . "\n";

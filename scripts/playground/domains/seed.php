<?php
// A customer (shopper / password) with one live website, and the shop selling domains with $3 on top.
update_option('anypress_api_base_url', 'https://api.example.test');
update_option('anypress_api_key', 'key');
update_option('anypress_api_secret', 'secret');
update_option('anypress_can_reach_api', true);
update_option('anypress_domains_enabled', 'on');
update_option('anypress_domains_extra_dollars', 3);
// Offline payments, so the checkout can be finished without a card.
if (class_exists('\FluentCart\App\Modules\PaymentMethods\Core\GatewayManager')) {
    update_option('fluent_cart_payment_settings_offline_payment', ['is_active' => 'yes', 'checkout_label' => 'Pay later']);
}

if (!(new \FluentCart\Api\StoreSettings())->get('customer_profile_page_id')) { (new \FluentCart\App\CPT\Pages())->createPages(); }
$user_id = username_exists('shopper') ?: wp_insert_user(['user_login' => 'shopper', 'user_pass' => 'password', 'user_email' => 'shopper@example.com', 'first_name' => 'Ada', 'last_name' => 'Baker', 'role' => 'subscriber']);
$customer = \FluentCart\App\Models\Customer::query()->where('email', 'shopper@example.com')->first()
    ?: \FluentCart\App\Models\Customer::query()->create(['email' => 'shopper@example.com', 'first_name' => 'Ada', 'last_name' => 'Baker', 'user_id' => $user_id]);
$sub = \FluentCart\App\Models\Subscription::query()->create([
    'customer_id' => $customer->id, 'parent_order_id' => 0, 'product_id' => 0, 'variation_id' => 0, 'item_name' => 'Website Small',
    'status' => 'active', 'billing_interval' => 'monthly', 'recurring_amount' => 1200, 'recurring_total' => 1200, 'bill_times' => 0,
]);
$sub->updateMeta('anypress_tenant_external_id', 'ext-bakery');
$sub->updateMeta('anypress_tenant_state', 'Ready');
$sub->updateMeta('anypress_domain_name', 'bakery.stores.example');
flush_rewrite_rules();
echo "seeded subscription {$sub->id} for user {$user_id}\n";

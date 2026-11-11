<?php
// A customer (shopper / password) with one live website, and the shop selling a $5 a month storage pack.
update_option('anypress_api_base_url', 'https://api.example.test');
update_option('anypress_api_key', 'key');
update_option('anypress_api_secret', 'secret');
update_option('anypress_can_reach_api', true);

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

// The storage pack: a published FluentCart product, $5 a month, with the AnyPress feed set to "Storage pack".
$product_id = wp_insert_post(['post_type' => 'fluent-products', 'post_status' => 'publish', 'post_title' => '25 GB more storage']);
\FluentCart\App\Models\ProductDetail::query()->create(['post_id' => $product_id, 'fulfillment_type' => 'digital', 'min_price' => 500, 'max_price' => 500, 'variation_type' => 'simple', 'stock_availability' => 'in-stock']);
$variation = \FluentCart\App\Models\ProductVariation::query()->create([
    'post_id' => $product_id, 'variation_title' => '25 GB more storage', 'item_price' => 500, 'serial_index' => 1, 'item_status' => 'active',
    'payment_type' => 'subscription', 'stock_status' => 'in-stock', 'fulfillment_type' => 'digital',
    'other_info' => ['payment_type' => 'subscription', 'repeat_interval' => 'monthly', 'times' => 0],
]);
\FluentCart\App\Models\ProductMeta::query()->create([
    'object_id' => $product_id, 'object_type' => 'product_integration', 'meta_key' => 'anypress_provisioning',
    'meta_value' => ['enabled' => 'yes', 'name' => 'Storage pack', 'product_type' => 'storage_pack', 'event_trigger' => ['subscription_activated', 'subscription_reactivated', 'subscription_canceled', 'subscription_eot']],
]);
update_option('storage_check_ids', ['website' => $sub->id, 'customer' => $customer->id, 'product' => $product_id, 'variation' => $variation->id], false);
flush_rewrite_rules();
echo "seeded website subscription {$sub->id}, storage pack product {$product_id} (variation {$variation->id})\n";

<?php
// The shop selling one website plan with 7 free days, and a customer who had that plan before (back@example.com).
if (!(new \FluentCart\Api\StoreSettings())->get('customer_profile_page_id')) { (new \FluentCart\App\CPT\Pages())->createPages(); }

$product_id = wp_insert_post(['post_type' => 'fluent-products', 'post_status' => 'publish', 'post_title' => 'Website (Small)']);
\FluentCart\App\Models\ProductDetail::query()->create(['post_id' => $product_id, 'fulfillment_type' => 'digital', 'min_price' => 1200, 'max_price' => 1200, 'variation_type' => 'simple', 'stock_availability' => 'in-stock']);
$variation = \FluentCart\App\Models\ProductVariation::query()->create([
    'post_id' => $product_id, 'variation_title' => 'Monthly', 'item_price' => 1200, 'serial_index' => 1, 'item_status' => 'active',
    'payment_type' => 'subscription', 'stock_status' => 'in-stock', 'fulfillment_type' => 'digital',
    'other_info' => ['payment_type' => 'subscription', 'repeat_interval' => 'monthly', 'times' => '', 'trial_days' => '7', 'manage_setup_fee' => 'no'],
]);
\FluentCart\App\Models\ProductMeta::query()->create([
    'object_id' => $product_id, 'object_type' => 'product_integration', 'meta_key' => 'anypress_provisioning',
    'meta_value' => ['enabled' => 'yes', 'name' => 'Website plan', 'product_type' => 'plan', 'role' => 'website', 'event_trigger' => ['subscription_activated']],
]);

// back@example.com had this plan and cancelled it; pending@example.com only ever left a checkout unfinished.
foreach (['back@example.com' => 'canceled', 'pending@example.com' => 'pending'] as $email => $status) {
    $customer = \FluentCart\App\Models\Customer::query()->create(['email' => $email, 'first_name' => 'Sam', 'last_name' => 'Lee']);
    \FluentCart\App\Models\Subscription::query()->create([
        'customer_id' => $customer->id, 'parent_order_id' => 0, 'product_id' => $product_id, 'variation_id' => $variation->id, 'item_name' => 'Website (Small)',
        'status' => $status, 'billing_interval' => 'monthly', 'recurring_amount' => 1200, 'recurring_total' => 1200, 'bill_times' => 0,
    ]);
}
update_option('trial_check_ids', ['product' => $product_id, 'variation' => $variation->id], false);
flush_rewrite_rules();
echo "seeded plan {$product_id} (variation {$variation->id}, 7 free days)\n";

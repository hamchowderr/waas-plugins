<?php
// The shop for the care check: a customer (shopper / password) with one live website, a website plan product, the care
// products made by AnyPress > Care's button and published, and Fluent Support's first mailbox.
update_option('anypress_api_base_url', 'https://api.example.test');
update_option('anypress_api_key', 'key');
update_option('anypress_api_secret', 'secret');
update_option('anypress_can_reach_api', true);
if (class_exists('\FluentCart\App\Modules\PaymentMethods\Core\GatewayManager')) {
    fluent_cart_update_option('fluent_cart_payment_settings_offline_payment', ['is_active' => 'yes', 'payment_mode' => 'live', 'checkout_label' => 'Pay later']);
}
if (!(new \FluentCart\Api\StoreSettings())->get('customer_profile_page_id')) { (new \FluentCart\App\CPT\Pages())->createPages(); }
if (!\FluentSupport\App\Models\MailBox::count()) {
    \FluentSupport\App\Models\MailBox::create(['name' => 'AnyPress', 'slug' => 'anypress', 'box_type' => 'web', 'email' => 'support@shop.example', 'is_default' => 'yes', 'created_by' => 1]);
}

$user_id = username_exists('shopper') ?: wp_insert_user(['user_login' => 'shopper', 'user_pass' => 'password', 'user_email' => 'shopper@example.com', 'first_name' => 'Ada', 'last_name' => 'Baker', 'role' => 'subscriber']);
$customer = \FluentCart\App\Models\Customer::query()->where('email', 'shopper@example.com')->first()
    ?: \FluentCart\App\Models\Customer::query()->create(['email' => 'shopper@example.com', 'first_name' => 'Ada', 'last_name' => 'Baker', 'user_id' => $user_id]);
$sub = \FluentCart\App\Models\Subscription::query()->create([
    'customer_id' => $customer->id, 'parent_order_id' => 0, 'product_id' => 0, 'variation_id' => 0, 'item_name' => 'Community & Courses (Small)',
    'status' => 'active', 'billing_interval' => 'monthly', 'recurring_amount' => 4900, 'recurring_total' => 4900, 'bill_times' => 0,
]);
$sub->updateMeta('anypress_tenant_external_id', 'ext-studio');
$sub->updateMeta('anypress_tenant_state', 'Ready');
$sub->updateMeta('anypress_domain_name', 'sams-studio.anypress.site');

// A website plan, as FluentCartStarterPlans makes one.
$result = (new \FluentCart\App\Services\BulkProductInsertService())->insertChunk([[
    '_cid' => 'community-small', 'post_title' => 'Community & Courses (Small)', 'post_status' => 'published',
    'post_content' => 'Your community, courses and shop on your own WordPress.',
    'detail' => ['fulfillment_type' => 'digital', 'variation_type' => 'simple_variations'],
    'variants' => [['variation_title' => 'Monthly', 'item_price' => 4900, 'other_info' => ['payment_type' => 'subscription', 'repeat_interval' => 'monthly', 'times' => '', 'trial_days' => 0, 'manage_setup_fee' => 'no']]],
]]);
$plan_id = (int) $result['created'][0]['id'];
wp_update_post(['ID' => $plan_id, 'post_name' => 'community-small']);
\FluentCart\App\Models\ProductMeta::create(['object_id' => $plan_id, 'object_type' => 'product_integration', 'meta_key' => 'anypress_provisioning', 'meta_value' => ['enabled' => 'yes', 'product_type' => 'plan', 'role' => 'community', 'size' => 'small']]);

$made = (new \AnyPressHost\Commerce\FluentCart\FluentCartCareProducts())->create();
$drafts = 0;
foreach (get_posts(['post_type' => 'fluent-products', 'post_status' => 'draft', 'numberposts' => -1]) as $post) {
    $drafts++;
    wp_update_post(['ID' => $post->ID, 'post_status' => 'publish']); // published for the check only
}
flush_rewrite_rules();
echo "seeded website {$sub->id}, plan {$plan_id}, care products made {$made} (drafts {$drafts})\n";

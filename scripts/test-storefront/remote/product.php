<?php
// Creates "Website plan (test)": a monthly FluentCart subscription with the WaaS Provisioning feed. Idempotent.
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Modules\Integrations\IntegrationHelper;
use FluentCart\App\Services\BulkProductInsertService;

$title = 'Website plan (test)';
$existing = get_posts(['post_type' => 'fluent-products', 'title' => $title, 'post_status' => 'any', 'numberposts' => 1]);
if ($existing) {
    $id = $existing[0]->ID;
    echo "product exists: $id\n";
} else {
    $res = (new BulkProductInsertService())->insertChunk([[
        '_cid' => 'p1', 'post_title' => $title, 'post_status' => 'published',
        'post_content' => 'Test plan for the WaaS end-to-end check. A website on the Starter store (FluentCart) template. Not a real offer.',
        'detail' => ['fulfillment_type' => 'digital', 'variation_type' => 'simple'],
        'variants' => [[
            'variation_title' => 'Monthly', 'item_price' => 100,
            'other_info' => ['payment_type' => 'subscription', 'repeat_interval' => 'monthly', 'times' => '', 'trial_days' => 0, 'manage_setup_fee' => 'no'],
        ]],
    ]]);
    if (empty($res['created'])) { echo 'create failed: ' . json_encode($res['errors']) . "\n"; return; }
    $id = $res['created'][0]['id'];
    echo "product created: $id\n";
}

$roles = array_keys((array) get_option('waas-host-roles-options', []));
$feed = [
    'enabled' => 'yes', 'name' => 'Website plan', 'role' => (string) ($roles[0] ?? ''), 'group_name' => '',
    'event_trigger' => ['subscription_activated', 'subscription_canceled', 'subscription_eot'],
    'watch_on_access_revoke' => 'yes',
];
$data = IntegrationHelper::validateAndFormatIntegrationFeedSettings($feed, ['provider' => 'waas_provisioning', 'scope' => 'product', 'product_id' => $id, 'integration_id' => null]);
if (is_wp_error($data)) { echo 'feed invalid: ' . $data->get_error_message() . ' ' . json_encode($data->get_error_data()) . "\n"; return; }
$meta = ProductMeta::where('object_id', $id)->where('object_type', 'product_integration')->where('meta_key', 'waas_provisioning')->first();
if ($meta) { $meta->meta_value = $data; $meta->save(); } else {
    ProductMeta::create(['object_id' => $id, 'object_type' => 'product_integration', 'meta_key' => 'waas_provisioning', 'meta_value' => $data]);
}
do_action('fluent_cart/reindex_integration_feeds', []);
echo 'feed saved, role: ' . ($feed['role'] ?: '(default)') . ', roles available: ' . implode(',', $roles) . "\n";
echo 'url: ' . get_permalink($id) . "\n";
$v = \FluentCart\App\Models\ProductVariation::where('post_id', $id)->first();
echo 'variation: ' . $v->id . ' ' . $v->payment_type . ' ' . $v->item_price . ' ' . json_encode($v->other_info) . "\n";

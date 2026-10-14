<?php
// Creates the storefront's plan: a monthly FluentCart subscription with the WaaS Provisioning feed. Idempotent.
// The test storefront gets "Website plan (test)" at $1; the shop's compose sets PLAN_TITLE, PLAN_PRICE (cents) and
// PLAN_CONTENT for its placeholder plan.
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Modules\Integrations\IntegrationHelper;
use FluentCart\App\Services\BulkProductInsertService;

$title = getenv('PLAN_TITLE') ?: 'Website plan (test)';
$price = (int) (getenv('PLAN_PRICE') ?: 100);
$content = getenv('PLAN_CONTENT') ?: 'Test plan for the WaaS end-to-end check. A website on the Starter store (FluentCart) template. Not a real offer.';
$existing = get_posts(['post_type' => 'fluent-products', 'title' => $title, 'post_status' => 'any', 'numberposts' => 1]);
if ($existing) {
    $id = $existing[0]->ID;
    echo "product exists: $id\n";
} else {
    $res = (new BulkProductInsertService())->insertChunk([[
        '_cid' => 'p1', 'post_title' => $title, 'post_status' => 'published',
        'post_content' => $content,
        'detail' => ['fulfillment_type' => 'digital', 'variation_type' => 'simple'],
        'variants' => [[
            'variation_title' => 'Monthly', 'item_price' => $price,
            'other_info' => ['payment_type' => 'subscription', 'repeat_interval' => 'monthly', 'times' => '', 'trial_days' => 0, 'manage_setup_fee' => 'no'],
        ]],
    ]]);
    if (empty($res['created'])) { echo 'create failed: ' . json_encode($res['errors']) . "\n"; return; }
    $id = $res['created'][0]['id'];
    echo "product created: $id\n";
}

$roles = array_keys((array) get_option('waas-host-roles-options', []));
$meta = ProductMeta::where('object_id', $id)->where('object_type', 'product_integration')->where('meta_key', 'waas_provisioning')->first();
$old = $meta && is_array($meta->meta_value) ? $meta->meta_value : [];
// waas-host's default triggers; keep a role already chosen in wp-admin.
$feed = [
    'enabled' => 'yes', 'name' => 'Website plan', 'role' => (string) ($old['role'] ?? '') ?: (string) ($roles[0] ?? ''),
    'group_name' => (string) ($old['group_name'] ?? ''),
    'event_trigger' => \WaaSHost\Commerce\FluentCart\WaasProvisioningIntegration::DEFAULT_TRIGGERS,
    'watch_on_access_revoke' => 'yes',
];
$data = IntegrationHelper::validateAndFormatIntegrationFeedSettings($feed, ['provider' => 'waas_provisioning', 'scope' => 'product', 'product_id' => $id, 'integration_id' => null]);
if (is_wp_error($data)) { echo 'feed invalid: ' . $data->get_error_message() . ' ' . json_encode($data->get_error_data()) . "\n"; return; }
if ($meta) { $meta->meta_value = $data; $meta->save(); } else {
    ProductMeta::create(['object_id' => $id, 'object_type' => 'product_integration', 'meta_key' => 'waas_provisioning', 'meta_value' => $data]);
}
do_action('fluent_cart/reindex_integration_feeds', []);
$saved = ProductMeta::where('object_id', $id)->where('object_type', 'product_integration')->where('meta_key', 'waas_provisioning')->first()->meta_value;
echo 'feed saved, role: ' . ($feed['role'] ?: '(default)') . ', roles available: ' . implode(',', $roles) . "\n";
echo 'feed triggers: ' . implode(',', (array) ($saved['event_trigger'] ?? [])) . "\n";
echo 'url: ' . get_permalink($id) . "\n";
$v = \FluentCart\App\Models\ProductVariation::where('post_id', $id)->first();
echo 'variation: ' . $v->id . ' ' . $v->payment_type . ' ' . $v->item_price . ' ' . json_encode($v->other_info) . "\n";

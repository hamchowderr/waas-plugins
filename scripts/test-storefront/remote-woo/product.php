<?php
// Creates "Website plan (test)": a $1 monthly WP Swings subscription, marked as a WaaS product. Idempotent.
use WaaSHost\Core\StoreProduct;

$title = 'Website plan (test)';
$existing = get_posts(['post_type' => 'product', 'title' => $title, 'post_status' => 'any', 'numberposts' => 1]);
if ($existing) {
    $product = wc_get_product($existing[0]->ID);
    echo 'product exists: ' . $product->get_id() . "\n";
} else {
    $product = new WC_Product_Simple();
    $product->set_name($title);
    $product->set_slug('website-plan-test');
    $product->set_status('publish');
    $product->set_description('Test plan for the WaaS end-to-end check. Not a real offer.');
    echo "product created\n";
}
$product->set_virtual(true);
$product->set_sold_individually(true);
$product->set_regular_price('1.00');
$product->update_meta_data('_wps_sfw_product', 'yes');
$product->update_meta_data('wps_sfw_subscription_number', '1');
$product->update_meta_data('wps_sfw_subscription_interval', 'month');
$product->update_meta_data('wps_sfw_subscription_expiry_number', '');
$product->update_meta_data('wps_sfw_subscription_expiry_interval', 'month');
$product->update_meta_data('wps_sfw_subscription_initial_signup_price', '');
$product->update_meta_data('wps_sfw_subscription_free_trial_number', '');
$product->update_meta_data('wps_sfw_subscription_free_trial_interval', 'day');
$id = $product->save();

// waas-host: a base plan; keep a role already chosen in wp-admin, else the first role the API offers.
$store_product = new StoreProduct($id);
$store_product->store_is_waas_product(true);
$store_product->store_type(StoreProduct::PRODUCT_TYPE_BASE_PRODUCT);
$roles = array_keys((array) get_option('waas-host-roles-options', []));
if ((string) $store_product->get_role() === '' && $roles) {
    $store_product->update_role((string) $roles[0]);
}

echo 'id: ' . $id . ', price: ' . $product->get_regular_price() . ', subscription: ' . $product->get_meta('_wps_sfw_product')
    . ' every ' . $product->get_meta('wps_sfw_subscription_number') . ' ' . $product->get_meta('wps_sfw_subscription_interval') . "\n";
echo 'waas product: ' . ($store_product->is_store_product() ? 'yes' : 'no') . ', role: ' . ($store_product->get_role() ?: '(none)') . ', roles available: ' . implode(',', $roles) . "\n";
echo 'url: ' . get_permalink($id) . "\n";

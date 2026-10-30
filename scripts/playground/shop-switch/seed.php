<?php
// Seeds a WooCommerce shop for the shop switch test (shop-switch.mjs): 10 products (8 simple, a variable Jacket with
// 3 sizes and a variable Beanie with 2 colours), gallery images, a tag, 2 coupons, 3 customers, 8 orders (shipping,
// coupons, a partial and a full refund, guests, and Carol ordering with another billing email), 2 tax rates and a
// shipping zone with flat rate and free shipping. The runner sets $GLOBALS['shop_test_hpos'] to 'yes' or 'no'.
require_once '/wordpress/wp-load.php';
$hpos = ($GLOBALS['shop_test_hpos'] ?? 'no') === 'yes' ? 'yes' : 'no';
$log = [];
WC_Install::install();
$ds = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class);
if (!$ds->check_orders_table_exists()) { $ds->create_database_tables(); }
update_option('woocommerce_custom_orders_table_enabled', $hpos);
update_option('woocommerce_custom_orders_table_data_sync_enabled', 'no');
update_option('woocommerce_currency', 'USD');
update_option('woocommerce_calc_taxes', 'yes');
update_option('woocommerce_prices_include_tax', 'no');
update_option('woocommerce_tax_based_on', 'shipping');
update_option('woocommerce_default_country', 'US:CA');
update_option('woocommerce_store_address', '1 Market St');
update_option('woocommerce_store_city', 'San Francisco');
update_option('woocommerce_store_postcode', '94105');

// Tax rates
WC_Tax::_insert_tax_rate(['tax_rate_country'=>'US','tax_rate_state'=>'CA','tax_rate'=>'7.2500','tax_rate_name'=>'CA Sales Tax','tax_rate_priority'=>1,'tax_rate_compound'=>0,'tax_rate_shipping'=>1,'tax_rate_order'=>0,'tax_rate_class'=>'']);
WC_Tax::_insert_tax_rate(['tax_rate_country'=>'GB','tax_rate_state'=>'','tax_rate'=>'20.0000','tax_rate_name'=>'VAT','tax_rate_priority'=>1,'tax_rate_compound'=>0,'tax_rate_shipping'=>1,'tax_rate_order'=>1,'tax_rate_class'=>'']);

// Shipping zone
$zone = new WC_Shipping_Zone();
$zone->set_zone_name('United States');
$zone->set_zone_order(1);
$zone->add_location('US', 'country');
$zone->save();
$iid = $zone->add_shipping_method('flat_rate');
update_option('woocommerce_flat_rate_' . $iid . '_settings', ['title'=>'Flat rate','tax_status'=>'taxable','cost'=>'10.00']);
$zone->add_shipping_method('free_shipping');

function seed_image($name, $rgb) {
    $up = wp_upload_dir();
    wp_mkdir_p($up['path']);
    $file = $up['path'] . '/' . $name . '.png';
    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor(200, 200);
        imagefill($im, 0, 0, imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]));
        imagepng($im, $file);
    } else {
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }
    $id = wp_insert_attachment(['post_mime_type'=>'image/png','post_title'=>$name,'post_status'=>'inherit'], $file);
    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));
    return $id;
}

$catA = wp_insert_term('Apparel', 'product_cat')['term_id'];
$catB = wp_insert_term('Accessories', 'product_cat')['term_id'];
$tag  = wp_insert_term('summer', 'product_tag')['term_id'];

$products = [];
$simple = [
    ['T-Shirt', 'TS-001', '20.00', '', $catA, true, 2],
    ['Hoodie', 'HD-001', '45.00', '39.00', $catA, true, 1],
    ['Cap', 'CP-001', '15.00', '', $catB, true, 0],
    ['Socks', 'SK-001', '8.00', '', $catA, false, 0],
    ['Tote Bag', 'TB-001', '12.00', '', $catB, true, 0],
    ['Mug', 'MG-001', '10.00', '', $catB, false, 0],
    ['Sticker Pack', 'ST-001', '5.00', '', $catB, false, 0],
    ['Poster', 'PS-001', '18.00', '', $catB, true, 1],
];
$c = 0;
foreach ($simple as [$name, $sku, $reg, $sale, $cat, $tagged, $gal]) {
    $p = new WC_Product_Simple();
    $p->set_name($name); $p->set_sku($sku); $p->set_regular_price($reg);
    if ($sale) { $p->set_sale_price($sale); }
    $p->set_category_ids([$cat]);
    if ($tagged) { $p->set_tag_ids([$tag]); }
    $p->set_status('publish');
    $p->set_image_id(seed_image(sanitize_title($name), [50 + $c * 20, 100, 150]));
    $g = [];
    for ($i = 0; $i < $gal; $i++) { $g[] = seed_image(sanitize_title($name) . '-g' . $i, [200, 50 + $i * 40, 80]); }
    $p->set_gallery_image_ids($g);
    $p->save();
    $products[$sku] = $p->get_id();
    $c++;
}

function seed_variable($name, $sku, $attrName, $options, $prices, $cat, $gal) {
    $p = new WC_Product_Variable();
    $p->set_name($name); $p->set_sku($sku); $p->set_category_ids([$cat]); $p->set_status('publish');
    $a = new WC_Product_Attribute();
    $a->set_name($attrName); $a->set_options($options); $a->set_visible(true); $a->set_variation(true);
    $p->set_attributes([$a]);
    $p->set_image_id(seed_image(sanitize_title($name), [20, 180, 90]));
    $g = [];
    for ($i = 0; $i < $gal; $i++) { $g[] = seed_image(sanitize_title($name) . '-g' . $i, [90, 20, 180]); }
    $p->set_gallery_image_ids($g);
    $p->save();
    $vars = [];
    foreach ($options as $i => $opt) {
        $v = new WC_Product_Variation();
        $v->set_parent_id($p->get_id());
        $v->set_attributes([sanitize_title($attrName) => $opt]);
        $v->set_sku($sku . '-' . strtoupper($opt));
        $v->set_regular_price($prices[$i]);
        $v->set_status('publish');
        $v->save();
        $vars[$opt] = $v->get_id();
    }
    WC_Product_Variable::sync($p->get_id());
    return [$p->get_id(), $vars];
}
[$jacketId, $jacketVars] = seed_variable('Jacket', 'JK-001', 'Size', ['S', 'M', 'L'], ['80.00', '85.00', '90.00'], $catA, 2);
[$beanieId, $beanieVars] = seed_variable('Beanie', 'BN-001', 'Color', ['Red', 'Blue'], ['14.00', '16.00'], $catB, 0);

// Coupons
$cp = new WC_Coupon();
$cp->set_code('save10'); $cp->set_discount_type('percent'); $cp->set_amount(10); $cp->save();
$cp2 = new WC_Coupon();
$cp2->set_code('fiveoff'); $cp2->set_discount_type('fixed_cart'); $cp2->set_amount(5); $cp2->set_usage_limit(100); $cp2->save();

// Customers
$custSpec = [
    ['alice@example.com', 'Alice', 'Anders', ['address_1'=>'100 Pine St','city'=>'Los Angeles','state'=>'CA','postcode'=>'90001','country'=>'US']],
    ['bob@example.com', 'Bob', 'Baker', ['address_1'=>'200 Oak Ave','city'=>'San Diego','state'=>'CA','postcode'=>'92101','country'=>'US']],
    ['carol@example.com', 'Carol', 'Chen', ['address_1'=>'300 Elm Rd','city'=>'New York','state'=>'NY','postcode'=>'10001','country'=>'US']],
];
$cust = [];
foreach ($custSpec as [$email, $fn, $ln, $addr]) {
    $cu = new WC_Customer();
    $cu->set_email($email); $cu->set_username(strtok($email, '@')); $cu->set_password(wp_generate_password());
    $cu->set_first_name($fn); $cu->set_last_name($ln);
    foreach (['billing', 'shipping'] as $t) {
        foreach ($addr as $k => $v) { $cu->{"set_{$t}_{$k}"}($v); }
        $cu->{"set_{$t}_first_name"}($fn); $cu->{"set_{$t}_last_name"}($ln);
    }
    $cu->set_billing_email($email); $cu->set_billing_phone('555-0100');
    $cu->save();
    $cust[] = ['id'=>$cu->get_id(), 'email'=>$email, 'fn'=>$fn, 'ln'=>$ln, 'addr'=>$addr];
}

function seed_order($c, $lines, $status, $opts = []) {
    $o = wc_create_order(['customer_id' => $c['id']]);
    foreach ($lines as [$pid, $qty]) { $o->add_product(wc_get_product($pid), $qty); }
    $email = $opts['email'] ?? $c['email'];
    foreach (['billing', 'shipping'] as $t) {
        foreach ($c['addr'] as $k => $v) { $o->{"set_{$t}_{$k}"}($v); }
        $o->{"set_{$t}_first_name"}($c['fn']); $o->{"set_{$t}_last_name"}($c['ln']);
    }
    $o->set_billing_email($email);
    $o->set_payment_method('bacs'); $o->set_payment_method_title('Direct bank transfer');
    if (!empty($opts['shipping'])) {
        $s = new WC_Order_Item_Shipping();
        $s->set_method_title('Flat rate'); $s->set_method_id('flat_rate'); $s->set_total($opts['shipping']);
        $o->add_item($s);
    }
    $o->calculate_totals(true);
    if (!empty($opts['coupon'])) { $o->apply_coupon($opts['coupon']); }
    $o->set_date_paid(time());
    $o->save();
    $o->update_status($status);
    if (!empty($opts['refund'])) {
        $o = wc_get_order($o->get_id());
        $amt = $opts['refund'] === 'full' ? $o->get_total() : $opts['refund'];
        $r = wc_create_refund(['order_id'=>$o->get_id(), 'amount'=>$amt, 'reason'=>'Test refund', 'refund_payment'=>false, 'restock_items'=>false]);
        if (is_wp_error($r)) { $GLOBALS['seed_errors'][] = 'refund ' . $r->get_error_message(); }
    }
    return $o->get_id();
}
$guest1 = ['id'=>0, 'email'=>'guest1@example.com', 'fn'=>'Gina', 'ln'=>'Guest', 'addr'=>['address_1'=>'9 Bay Rd','city'=>'Oakland','state'=>'CA','postcode'=>'94601','country'=>'US']];
$guest2 = ['id'=>0, 'email'=>'guest2@example.com', 'fn'=>'Gary', 'ln'=>'Guest', 'addr'=>['address_1'=>'5 Lake Dr','city'=>'Austin','state'=>'TX','postcode'=>'73301','country'=>'US']];
$orders = [];
$orders['o1_alice_completed_shipping'] = seed_order($cust[0], [[$products['TS-001'], 2], [$products['CP-001'], 1]], 'completed', ['shipping'=>'10.00']);
$orders['o2_alice_processing_variation'] = seed_order($cust[0], [[$jacketVars['M'], 1]], 'processing');
$orders['o3_bob_completed_coupon_pct'] = seed_order($cust[1], [[$products['HD-001'], 1], [$products['MG-001'], 2]], 'completed', ['coupon'=>'save10']);
$orders['o4_bob_completed_partial_refund'] = seed_order($cust[1], [[$products['TB-001'], 2], [$beanieVars['Blue'], 1]], 'completed', ['refund'=>'12.00']);
$orders['o5_carol_full_refund'] = seed_order($cust[2], [[$products['PS-001'], 1]], 'completed', ['refund'=>'full']);
$orders['o6_carol_processing_other_billing_email'] = seed_order($cust[2], [[$products['SK-001'], 3], [$jacketVars['L'], 1]], 'processing', ['email'=>'carol.work@example.com']);
$orders['o7_guest1_completed_coupon_fixed'] = seed_order($guest1, [[$products['ST-001'], 4], [$beanieVars['Red'], 1]], 'completed', ['coupon'=>'fiveoff']);
$orders['o8_guest2_processing_shipping'] = seed_order($guest2, [[$products['HD-001'], 1]], 'processing', ['shipping'=>'10.00']);

$log['orders'] = $orders;
$log['customers'] = $cust;
$log['products'] = $products + ['JK-001'=>$jacketId, 'BN-001'=>$beanieId];
$log['hpos_enabled'] = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
$log['errors'] = $GLOBALS['seed_errors'] ?? [];
echo json_encode($log);

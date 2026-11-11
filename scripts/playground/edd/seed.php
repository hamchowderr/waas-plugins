<?php
// Seeds an Easy Digital Downloads 3 shop for the EDD shop switch test (edd.mjs), through EDD's own functions: 3
// downloads (one with two price options), a 10% discount code, 3 customers (one who never ordered), 3 completed orders
// with their items and transactions, and 3 pages that show the shop with EDD's shortcodes and blocks.
require_once '/wordpress/wp-load.php';
wp_set_current_user(1);
$errors = [];
// EDD makes its tables on admin_init (each table's maybe_upgrade), which a script run never reaches: make them now.
foreach (EDD()->components as $component) {
    foreach (['table', 'meta'] as $part) {
        $table = $component->get_interface($part);
        if ($table instanceof \EDD\Database\Table && !$table->exists()) {
            $table->install();
        }
    }
}

$download = function (string $title, array $meta) {
    $id = wp_insert_post(['post_type' => 'download', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $title . ' for testing.']);
    foreach ($meta as $key => $value) {
        update_post_meta($id, $key, $value);
    }
    return $id;
};
$ebook = $download('Field Guide E-book', ['edd_price' => '10.00']);
$course = $download('Video Course', ['edd_price' => '49.00']);
$templates = $download('Template Pack', ['edd_price' => '20.00', '_variable_pricing' => 1, 'edd_variable_prices' => [
    1 => ['index' => 1, 'name' => 'Single site', 'amount' => '20.00'],
    2 => ['index' => 2, 'name' => 'Unlimited sites', 'amount' => '60.00'],
]]);

$discount = edd_add_adjustment(['name' => 'Ten off', 'code' => 'SAVE10', 'status' => 'active', 'type' => 'discount', 'scope' => 'global', 'amount_type' => 'percent', 'amount' => 10]);
$discount || $errors[] = 'discount';

$user = wp_insert_user(['user_login' => 'alice', 'user_email' => 'alice@anypress.example', 'user_pass' => wp_generate_password(), 'first_name' => 'Alice', 'last_name' => 'Ng']);
$alice = edd_add_customer(['email' => 'alice@anypress.example', 'name' => 'Alice Ng', 'user_id' => is_wp_error($user) ? 0 : $user]);
$bob = edd_add_customer(['email' => 'bob@anypress.example', 'name' => 'Bob Ruiz']);
$carol = edd_add_customer(['email' => 'carol@anypress.example', 'name' => 'Carol Diaz']);
($alice && $bob && $carol) || $errors[] = 'customers';

$order = function (int $customer, string $email, array $items, float $discount_amount = 0) use (&$errors) {
    $subtotal = array_sum(array_column($items, 2));
    $total = $subtotal - $discount_amount;
    $customer_row = edd_get_customer($customer);
    $id = edd_add_order([
        'status' => 'complete', 'type' => 'sale', 'customer_id' => $customer, 'user_id' => (int) ($customer_row->user_id ?? 0), 'email' => $email,
        'gateway' => 'manual', 'mode' => 'live', 'currency' => 'USD', 'payment_key' => md5($email . microtime()),
        'subtotal' => $subtotal, 'discount' => $discount_amount, 'tax' => 0, 'total' => $total,
        'date_completed' => current_time('mysql', true),
    ]);
    if (!$id) {
        $errors[] = 'order for ' . $email;
        return 0;
    }
    foreach ($items as $index => [$product, $price_id, $amount, $name]) {
        $share = $subtotal > 0 ? round($discount_amount * $amount / $subtotal, 2) : 0;
        edd_add_order_item(['order_id' => $id, 'product_id' => $product, 'product_name' => $name, 'price_id' => $price_id, 'cart_index' => $index,
            'status' => 'complete', 'quantity' => 1, 'amount' => $amount, 'subtotal' => $amount, 'discount' => $share, 'tax' => 0, 'total' => $amount - $share]);
    }
    edd_add_order_transaction(['object_id' => $id, 'object_type' => 'order', 'transaction_id' => 'manual-' . $id, 'gateway' => 'manual', 'status' => 'complete', 'total' => $total]);
    edd_update_order($id, ['order_number' => (string) $id]);
    return $id;
};
$orders = [
    $order($alice, 'alice@anypress.example', [[$ebook, null, 10.0, 'Field Guide E-book'], [$course, null, 49.0, 'Video Course']]),
    $order($bob, 'bob@anypress.example', [[$templates, 2, 60.0, 'Template Pack - Unlimited sites']], 6.0),
    $order($alice, 'alice@anypress.example', [[$templates, 1, 20.0, 'Template Pack - Single site']]),
];
foreach ([$alice, $bob] as $c) {
    (new EDD_Customer($c))->recalculate_stats();
}

$page = fn (string $title, string $content) => wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content]);
$pages = [
    'store' => $page('Store', "<!-- wp:paragraph --><p>Everything we sell.</p><!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[downloads columns=\"3\"]\n<!-- /wp:shortcode -->\n\n<!-- wp:shortcode -->\n[purchase_link id=\"{$ebook}\" text=\"Buy the e-book\"]\n<!-- /wp:shortcode -->"),
    'account' => $page('My Account', "<!-- wp:shortcode -->\n[purchase_history]\n<!-- /wp:shortcode -->\n\n<!-- wp:shortcode -->\n[download_history]\n<!-- /wp:shortcode -->\n\n<!-- wp:shortcode -->\n[edd_profile_editor]\n<!-- /wp:shortcode -->"),
    'course' => $page('The Course', "<!-- wp:paragraph --><p>Learn it all.</p><!-- /wp:paragraph -->\n\n<!-- wp:edd/buy-button {\"download_id\":{$course}} /-->\n\n<!-- wp:edd/checkout /-->"),
    'plain' => $page('About', '<!-- wp:paragraph --><p>No shop here.</p><!-- /wp:paragraph -->'),
];

echo json_encode([
    'downloads' => ['ebook' => $ebook, 'course' => $course, 'templates' => $templates],
    'orders' => $orders, 'customers' => [$alice, $bob, $carol], 'pages' => $pages,
    'checkout_page' => (int) edd_get_option('purchase_page'),
    'errors' => $errors,
]);

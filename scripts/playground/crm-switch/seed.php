<?php
// Seeds the sources for the CRM switch test (crm-switch.mjs). The runner sets $GLOBALS['crm_test_hpos'] to 'yes' or 'no'.
//
// WooCommerce: Mug, Cap and Hoodie; registered buyers Alice (Mug and Cap) and Carol (Hoodie, ordered with her work
// email); guests Gus (Mug, Mailchimp for WooCommerce opt-in on the order), Gina (Cap, processing), Rita (Hoodie,
// fully refunded), Pam (Mug, still pending payment: not a buyer), Dave (Cap; unsubscribed in MailPoet) and Erin
// (Mug; already a FluentCRM contact).
// MailPoet: lists Weekly and Offers (and its own automatic lists); Mia subscribed (Weekly, Offers), Uma unconfirmed
// (Weekly), Bob bounced (Weekly), Dave unsubscribed (unsubscribed from Weekly), Ivy inactive, Tom trashed, Bea
// subscribed (but unsubscribed in FluentCRM).
// Newsletter plugin: list Members; Nell confirmed (Members), Mia confirmed (Members), Sam unsubscribed (Members),
// Tess temporary (an unfinished sign-up).
// FluentCRM already has: Erin (subscribed, tags "Mug: Interested" and VIP, no last name), Bea (unsubscribed) and
// the list Weekly.
require_once '/wordpress/wp-load.php';
wp_set_current_user(1);
$hpos = ($GLOBALS['crm_test_hpos'] ?? 'no') === 'yes' ? 'yes' : 'no';
$errors = [];
global $wpdb;

WC_Install::install();
$ds = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class);
if (!$ds->check_orders_table_exists()) { $ds->create_database_tables(); }
update_option('woocommerce_custom_orders_table_enabled', $hpos);
update_option('woocommerce_custom_orders_table_data_sync_enabled', 'no');
update_option('woocommerce_currency', 'USD');

$products = [];
foreach (['Mug' => '10.00', 'Cap' => '15.00', 'Hoodie' => '40.00'] as $name => $price) {
    $p = new WC_Product_Simple();
    $p->set_name($name); $p->set_regular_price($price); $p->set_status('publish'); $p->save();
    $products[$name] = $p->get_id();
}

function crm_seed_user($email, $first, $last) {
    $c = new WC_Customer();
    $c->set_email($email); $c->set_username(strtok($email, '@')); $c->set_password(wp_generate_password());
    $c->set_first_name($first); $c->set_last_name($last);
    $c->save();
    return $c->get_id();
}
$alice = crm_seed_user('alice@example.com', 'Alice', 'Anders');
$carol = crm_seed_user('carol@example.com', 'Carol', 'Chen');

function crm_seed_order($customer, $email, $first, $last, $product, $status, $opts = []) {
    $o = wc_create_order(['customer_id' => $customer]);
    foreach ((array) $product as $pid) { $o->add_product(wc_get_product($pid), 1); }
    $o->set_billing_email($email); $o->set_billing_first_name($first); $o->set_billing_last_name($last);
    $o->set_billing_phone($opts['phone'] ?? '555-0100');
    $o->set_billing_address_1($opts['address'] ?? '1 Main St'); $o->set_billing_city($opts['city'] ?? 'Springfield');
    $o->set_billing_postcode('12345'); $o->set_billing_country('US'); $o->set_billing_state('IL');
    if (isset($opts['optin'])) { $o->update_meta_data('mailchimp_woocommerce_is_subscribed', $opts['optin']); }
    $o->calculate_totals(false);
    $o->save();
    $o->update_status($status);
    if (!empty($opts['refund'])) {
        $o = wc_get_order($o->get_id());
        $r = wc_create_refund(['order_id' => $o->get_id(), 'amount' => $o->get_total(), 'reason' => 'Test', 'refund_payment' => false, 'restock_items' => false]);
        if (is_wp_error($r)) { $GLOBALS['crm_seed_errors'][] = 'refund ' . $r->get_error_message(); }
    }
    return $o->get_id();
}
$orders = [
    'alice' => crm_seed_order($alice, 'alice@example.com', 'Alice', 'Anders', [$products['Mug'], $products['Cap']], 'completed', ['city' => 'Chicago']),
    'carol' => crm_seed_order($carol, 'carol.work@example.com', 'Carol', 'Chen', $products['Hoodie'], 'processing'),
    'gus' => crm_seed_order(0, 'gus@example.com', 'Gus', 'Guest', $products['Mug'], 'completed', ['optin' => '1']),
    'gina' => crm_seed_order(0, 'gina@example.com', 'Gina', 'Gale', $products['Cap'], 'processing'),
    'rita' => crm_seed_order(0, 'rita@example.com', 'Rita', 'Refund', $products['Hoodie'], 'completed', ['refund' => true]),
    'pam' => crm_seed_order(0, 'pam@example.com', 'Pam', 'Pending', $products['Mug'], 'pending'),
    'dave' => crm_seed_order(0, 'dave@example.com', 'Dave', 'Dunn', $products['Cap'], 'completed'),
    'erin' => crm_seed_order(0, 'erin@example.com', 'Erin', 'Ellis', $products['Mug'], 'completed', ['phone' => '555-0199']),
];

// MailPoet: its tables, with only the seeded people in them (MailPoet adds WordPress users on its own; those rows
// are taken out so the test knows exactly what is there).
$mp = $wpdb->prefix . 'mailpoet_';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $mp . 'subscribers')) !== $mp . 'subscribers') {
    $errors[] = 'MailPoet tables missing';
} else {
    $wpdb->query("DELETE FROM {$mp}subscriber_segment");
    $wpdb->query("DELETE FROM {$mp}subscribers");
    $now = current_time('mysql', true);
    $segment = function ($name) use ($wpdb, $mp, $now) {
        $wpdb->insert($mp . 'segments', ['name' => $name, 'type' => 'default', 'description' => '', 'created_at' => $now, 'updated_at' => $now]);
        return (int) $wpdb->insert_id;
    };
    $weekly = $segment('Weekly');
    $offers = $segment('Offers');
    $people = [
        ['mia@example.com', 'Mia', 'Moss', 'subscribed', [$weekly => 'subscribed', $offers => 'subscribed'], null],
        ['uma@example.com', 'Uma', '', 'unconfirmed', [$weekly => 'subscribed'], null],
        ['bob@example.com', 'Bob', 'Bounce', 'bounced', [$weekly => 'subscribed'], null],
        ['dave@example.com', 'David', 'Dunn', 'unsubscribed', [$weekly => 'unsubscribed'], null],
        ['ivy@example.com', 'Ivy', '', 'inactive', [], null],
        ['tom@example.com', 'Tom', '', 'subscribed', [$weekly => 'subscribed'], $now],
        ['bea@example.com', 'Bea', 'Back', 'subscribed', [$weekly => 'subscribed'], null],
    ];
    foreach ($people as $i => [$email, $first, $last, $status, $lists, $deleted]) {
        $wpdb->insert($mp . 'subscribers', ['email' => $email, 'first_name' => $first, 'last_name' => $last, 'status' => $status, 'source' => 'form',
            'subscribed_ip' => '10.0.0.' . ($i + 1), 'created_at' => '2025-0' . ($i + 1) . '-01 12:00:00', 'updated_at' => $now, 'deleted_at' => $deleted,
            'unsubscribe_token' => substr(md5($email), 0, 15), 'link_token' => md5($email . 'link')]);
        $id = (int) $wpdb->insert_id;
        foreach ($lists as $list => $list_status) {
            $wpdb->insert($mp . 'subscriber_segment', ['subscriber_id' => $id, 'segment_id' => $list, 'status' => $list_status, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}

// Newsletter plugin.
$nl = $wpdb->prefix . 'newsletter';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $nl)) !== $nl) {
    $errors[] = 'Newsletter table missing';
} else {
    $wpdb->query("DELETE FROM {$nl}");
    update_option('newsletter_lists', array_merge((array) get_option('newsletter_lists', []), ['list_1' => 'Members']));
    foreach ([['nell@example.com', 'Nell', 'North', 'C', 1], ['mia@example.com', 'Mia', '', 'C', 1], ['sam@example.com', 'Sam', '', 'U', 1], ['tess@example.com', 'Tess', '', 'T', 1]] as [$email, $first, $last, $status, $list]) {
        $wpdb->insert($nl, ['email' => $email, 'name' => $first, 'surname' => $last, 'status' => $status, 'list_1' => $list, 'token' => md5($email), 'country' => 'IT']);
    }
}

// FluentCRM's own contacts before the switch.
$tags = FluentCrmApi('tags')->importBulk([['title' => 'Mug: Interested', 'slug' => 'mug-interested'], ['title' => 'VIP', 'slug' => 'vip']]);
$lists = FluentCrmApi('lists')->importBulk([['title' => 'Weekly', 'slug' => 'weekly']]);
$erin = FluentCrmApi('contacts')->createOrUpdate(['email' => 'erin@example.com', 'first_name' => 'Erin', 'status' => 'subscribed', 'tags' => array_map(fn ($t) => $t->id, $tags)]);
$bea = FluentCrmApi('contacts')->createOrUpdate(['email' => 'bea@example.com', 'first_name' => 'Bea', 'status' => 'unsubscribed']);
if (!$erin || !$bea) { $errors[] = 'FluentCRM contacts not created'; }

echo json_encode([
    'orders' => $orders,
    'users' => ['alice' => $alice, 'carol' => $carol],
    'hpos_enabled' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
    'mailpoet' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$mp}subscribers"),
    'newsletter' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$nl}"),
    'fluentcrm' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers"),
    'errors' => array_merge($errors, $GLOBALS['crm_seed_errors'] ?? []),
]);

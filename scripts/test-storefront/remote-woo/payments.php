<?php
// Payments in test mode: Woo's Stripe gateway with Stripe test keys from STRIPE_TEST_PUBLISHABLE_KEY /
// STRIPE_TEST_SECRET_KEY in the container's environment (locked Coolify variables), or left off for the owner when
// they are absent; and Cash on delivery for by-hand tests (waas-test-cod.php lets WP Swings accept it). Prints no secrets.
$stripe = (array) get_option('woocommerce_stripe_settings', []);
$stripe['testmode'] = 'yes';
$publishable = (string) getenv('STRIPE_TEST_PUBLISHABLE_KEY');
$secret = (string) getenv('STRIPE_TEST_SECRET_KEY');
if (strpos($publishable, 'pk_test_') === 0 && strpos($secret, 'sk_test_') === 0) {
    $stripe['test_publishable_key'] = $publishable;
    $stripe['test_secret_key'] = $secret;
    $stripe['enabled'] = 'yes';
    $stripeStatus = 'test keys set';
} elseif ($publishable !== '' || $secret !== '') {
    $stripe['enabled'] = 'no';
    $stripeStatus = 'the keys in the environment are not Stripe test keys; left off';
} else {
    $stripe['enabled'] = $stripe['enabled'] ?? 'no';
    $stripeStatus = 'no keys in the environment; left for the owner';
}
update_option('woocommerce_stripe_settings', $stripe);

$cod = (array) get_option('woocommerce_cod_settings', []);
$cod = array_merge($cod, [
    'enabled' => 'yes', 'title' => 'Pay later (test)',
    'description' => 'Test only: nothing is charged. An admin marks the order paid.',
    'enable_for_virtual' => 'yes', 'enable_for_methods' => [],
]);
update_option('woocommerce_cod_settings', $cod);

echo 'stripe: ' . $stripeStatus . '; enabled=' . $stripe['enabled'] . ' testmode=' . $stripe['testmode'] . "\n";
echo 'cash on delivery: enabled=' . $cod['enabled'] . ', accepted for subscriptions: '
    . (has_filter('wps_sfw_supported_payment_gateway_for_woocommerce', 'waas_test_cod_for_subscriptions') ? 'yes' : 'no') . "\n";

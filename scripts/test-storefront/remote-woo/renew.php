<?php
// Makes a WP Swings subscription's renewal due now; the wp-cron task then creates the renewal order (and, with
// Stripe, charges it) within a few minutes. Usage: wp eval-file renew.php <subscription id>
$id = (int) ($args[0] ?? 0);
$subscription = $id ? wc_get_order($id) : null;
if (!$subscription || $subscription->get_type() !== 'wps_subscriptions') {
    echo "no WP Swings subscription $id\n";
    return;
}
// WP Swings' own meta writer, which picks HPOS or post meta like the plugin does.
wps_sfw_update_meta_data($id, 'wps_next_payment_date', current_time('timestamp') - 60);
$subscription = wc_get_order($id);
echo "subscription $id: status " . $subscription->get_meta('wps_subscription_status') . ', payment method ' . $subscription->get_payment_method()
    . ", renewal due now\n";

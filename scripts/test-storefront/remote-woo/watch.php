<?php
// Read-only: the latest WP Swings subscriptions and what waas-host holds for each, one line each. No customer data.
//   sh /opt/wpcli/x/setup.sh watch
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\TenantsSubscriptionManager;

$utc = fn ($t) => $t ? gmdate('Y-m-d H:i', (int) $t) . 'Z' : '-';
$offset = (int) round((float) get_option('gmt_offset', 0) * 3600);
$commerce = Commerce::adapter();
$ids = wc_get_orders(['type' => 'wps_subscriptions', 'limit' => 5, 'orderby' => 'ID', 'order' => 'DESC', 'return' => 'ids']);
if (!$ids) {
    echo "no subscriptions\n";
}
foreach ($ids as $id) {
    $sub = wc_get_order($id);
    $parent = wc_get_order((int) ($sub->get_parent_id() ?: $sub->get_meta('wps_parent_order')));
    $next = (int) $sub->get_meta('wps_next_payment_date');
    $meta = fn ($key) => $commerce ? $commerce->get_subscription_meta($id, $key) : '';
    echo "sub $id: status=" . ($sub->get_meta('wps_subscription_status') ?: '-')
        . ' pay=' . $sub->get_payment_method()
        . ' parent=' . ($parent ? $parent->get_id() . ':' . $parent->get_status() : '-')
        . ' next_payment=' . ($next ? $utc($next - $offset) : '-')
        . ' | store=' . ($meta(Tenant::TENANT_EXTERNAL_ID_META) ? 'yes' : 'no')
        . ' state=' . ($meta(Tenant::TENANT_STATE) ?: '-')
        . ' suspend_at=' . $utc($meta(Tenant::TENANT_SUSPEND_AT_META))
        . ' delete_at=' . $utc($meta(Tenant::TENANT_DELETE_AT_META))
        . ' held=' . ($meta('_waas_store_suspended') ?: '-')
        . ' cron_suspend=' . $utc(wp_next_scheduled(TenantsSubscriptionManager::SUSPEND_AT_PERIOD_END_HOOK, [$id]))
        . "\n";
}

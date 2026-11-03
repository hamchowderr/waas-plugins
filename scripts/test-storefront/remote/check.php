<?php
// Prints the anypress-host API reachability and production version, without secrets.
$http = new \AnyPressHost\Core\HttpService(new \AnyPressHost\Core\ConfigService());
$svc = new \AnyPressHost\Core\ProvisioningApi($http);
try {
    $ok = $svc->test_reachability();
    $svc->update_is_reachable($ok);
    echo 'reachable: ' . ($ok ? 'yes' : 'no') . "\n";
    $v = $svc->get_production_version();
    echo 'production version: ' . json_encode($v) . "\n";
} catch (\Throwable $e) {
    echo 'error: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
echo 'adapter: ' . (class_exists('\AnyPressHost\Commerce\Commerce') ? get_class(\AnyPressHost\Commerce\Commerce::adapter()) : 'n/a') . "\n";

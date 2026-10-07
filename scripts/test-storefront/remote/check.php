<?php
// Prints the waas-host API reachability and production version, without secrets.
$http = new \WaaSHost\Core\HttpService(new \WaaSHost\Core\ConfigService());
$svc = new \WaaSHost\Core\WPCSService($http);
try {
    $ok = $svc->test_reachability();
    $svc->update_is_reachable($ok);
    echo 'reachable: ' . ($ok ? 'yes' : 'no') . "\n";
    $v = $svc->get_production_version();
    echo 'production version: ' . json_encode($v) . "\n";
} catch (\Throwable $e) {
    echo 'error: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
echo 'adapter: ' . (class_exists('\WaaSHost\Commerce\Commerce') ? get_class(\WaaSHost\Commerce\Commerce::adapter()) : 'n/a') . "\n";

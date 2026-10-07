<?php
// Fetches the roles the production version offers (waas-host normally gets them when the API calls back), so the
// product step can give the test product one.
$wpcs = new \WaaSHost\Core\WPCSService(new \WaaSHost\Core\HttpService(new \WaaSHost\Core\ConfigService()));
try {
    (new \WaaSHost\Api\RolesController($wpcs))->update_tenant_roles_list();
    echo 'roles: ' . implode(',', array_keys((array) get_option(\WaaSHost\Features\PluginBootstrap::ROLES_WP_OPTION, []))) . "\n";
} catch (\Throwable $e) {
    echo 'roles not refreshed: ' . $e->getMessage() . "\n";
}

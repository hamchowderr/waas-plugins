<?php
// Fetches the roles the production version offers (anypress-host normally gets them when the API calls back), so the
// product step can give the test product one.
$api = new \AnyPressHost\Core\ProvisioningApi(new \AnyPressHost\Core\HttpService(new \AnyPressHost\Core\ConfigService()));
try {
    (new \AnyPressHost\Api\RolesController($api))->update_tenant_roles_list();
    echo 'roles: ' . implode(',', array_keys((array) get_option(\AnyPressHost\Features\PluginBootstrap::ROLES_WP_OPTION, []))) . "\n";
} catch (\Throwable $e) {
    echo 'roles not refreshed: ' . $e->getMessage() . "\n";
}

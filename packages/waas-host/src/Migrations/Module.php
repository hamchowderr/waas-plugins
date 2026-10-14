<?php

namespace WaaSHost\Migrations;

use WaaSHost\Core\PrivateKeyStore;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\StoreProduct;

// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

class Module
{
    const PLUGIN_VERSION_OPTION = 'wpcs-plugin-version';

    private ProvisioningApi $provisioningApi;
    public function __construct(ProvisioningApi $provisioningApi)
    {
        $this->provisioningApi = $provisioningApi;

        if ($this->get_db_version() !== WAAS_HOST_VERSION) {
            $this->update_api_reachability();
            // Once WooCommerce and the cart's subscription plugin have loaded.
            add_action('init', [StoreProduct::class, 'migrate_legacy_meta'], 20);
            add_action('init', [PrivateKeyStore::class, 'migrate_legacy_keys'], 20);
            $this->update_version();
        }
    }

    public function get_db_version()
    {
        return get_option(self::PLUGIN_VERSION_OPTION, '0.0.0');
    }

    public function update_version()
    {
        update_option(self::PLUGIN_VERSION_OPTION, WAAS_HOST_VERSION);
    }

    public function update_api_reachability()
    {
        $can_reach = false;
        try {
            $this->provisioningApi->test_reachability();
            $can_reach = true;
        } catch (\Exception $e) {
        }

        $this->provisioningApi->update_is_reachable($can_reach);
    }
}

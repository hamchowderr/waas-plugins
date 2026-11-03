<?php

namespace AnyPressHost\Migrations;

use AnyPressHost\Core\ProvisioningApi;

// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

class Module
{
    const PLUGIN_VERSION_OPTION = 'anypress_host_db_version';

    private ProvisioningApi $provisioningApi;
    public function __construct(ProvisioningApi $provisioningApi)
    {
        $this->provisioningApi = $provisioningApi;

        if ($this->get_db_version() !== ANYPRESS_HOST_VERSION) {
            $this->update_api_reachability();
            $this->update_version();
        }
    }

    public function get_db_version()
    {
        return get_option(self::PLUGIN_VERSION_OPTION, '0.0.0');
    }

    public function update_version()
    {
        update_option(self::PLUGIN_VERSION_OPTION, ANYPRESS_HOST_VERSION);
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

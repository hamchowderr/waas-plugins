<?php

namespace WaaSHost\Core;

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
    exit;

class ConfigService
{
    public function get_api_key()
    {
        $value = defined('WPCS_API_KEY') ? WPCS_API_KEY : get_option('wpcs_credentials_api_key_setting');
        return apply_filters('wpcs_settings_api_key', $value);
    }

    public function get_api_secret()
    {
        $value = defined('WPCS_API_SECRET') ? WPCS_API_SECRET : get_option('wpcs_credentials_api_secret_setting');
        return apply_filters('wpcs_settings_api_secret', $value);
    }

    public function get_api_region()
    {
        $value = defined('WPCS_API_REGION') ? WPCS_API_REGION : get_option('wpcs_credentials_region_setting');
        return apply_filters('wpcs_settings_api_region', $value);
    }

    /**
     * Base URL of the provisioning API, without a trailing slash.
     * WPCS_API_BASE_URL (constant) or the base URL setting wins; otherwise the
     * legacy WPCS host for the configured region. False when neither is set.
     */
    public function get_api_base_url()
    {
        $value = defined('WPCS_API_BASE_URL') ? WPCS_API_BASE_URL : get_option('wpcs_credentials_api_base_url_setting');
        if (empty($value)) {
            $region = $this->get_api_region();
            $value = empty($region) ? false : 'https://api.' . $region . '.wpcs.io';
        }

        $value = apply_filters('wpcs_settings_api_base_url', $value);
        return empty($value) ? false : untrailingslashit($value);
    }

    public function check_credentials()
    {
        $base_url_exists = $this->get_api_base_url() !== false;
        $key_exists = $this->get_api_key() !== false;
        $secret_exists = $this->get_api_secret() !== false;
        return $base_url_exists && $key_exists && $secret_exists;
    }
}

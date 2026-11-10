<?php

namespace AnyPressHost\Updater;

use AnyPressHost\Core\ConfigService;

// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

/**
 * Updates for AnyPress Host from our own channel (waas-hw4; see Updater). Also hands the storefront's
 * API credentials to our other plugins' updaters (anypress-payments) through 'anypress_update_credentials'.
 */
class Module
{
    public static function init(ConfigService $config)
    {
        add_filter('anypress_update_credentials', function ($credentials) use ($config) {
            return is_array($credentials) ? $credentials : self::credentials($config);
        });

        $updater = new Updater([
            'slug' => ANYPRESS_HOST_SLUG,
            'name' => 'AnyPress Host',
            'basename' => ANYPRESS_HOST_BASENAME,
            'version' => ANYPRESS_HOST_VERSION,
            'update_uri' => ANYPRESS_HOST_UPDATE_URI,
        ]);
        $updater->register();
        return $updater;
    }

    /** The provisioning API's base URL, key and secret, or null when any is missing. */
    public static function credentials(ConfigService $config)
    {
        $base_url = $config->get_api_base_url();
        $key = $config->get_api_key();
        $secret = $config->get_api_secret();
        if (empty($base_url) || empty($key) || empty($secret))
        {
            return null;
        }
        return ['base_url' => $base_url, 'key' => $key, 'secret' => $secret];
    }
}

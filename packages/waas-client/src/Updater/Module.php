<?php

namespace WaaSClient\Updater;

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
    exit;

class Module
{
    private static $remote_data = null;

    /**
     * Updates come only from our own channel. Until there is one, no update is ever checked or offered,
     * so WordPress can't replace this fork with the upstream (Daxez/waas-wpcs) plugin. Stores get new
     * plugin versions through template versions and the storefront through deploys.
     */
    public static function info_url()
    {
        return defined('WAAS_UPDATE_INFO_URL') ? WAAS_UPDATE_INFO_URL : null;
    }

    public static function init()
    {
        if(!self::info_url())
        {
            return;
        }

        add_filter( 'update_plugins_'.WPCS_WAAS_CLIENT_UPDATE_URI, [__CLASS__, 'maybe_show_update_available'], 10, 3 );
        add_filter( 'plugins_api', [__CLASS__, 'modify_plugin_details'], 10, 3 );
    }

    public static function maybe_show_update_available($update, array $plugin_data, string $plugin_file)
    {
        // only check this plugin
        if($plugin_file !== WPCS_WAAS_CLIENT_BASENAME)
        {
            return $update;
        }

        // already done update check elsewhere
        if (!empty($update))
        {
            return $update;
        }

        $response = self::get_remote_data();
        if(is_wp_error($response))
        {
            return $update;
        }

        $body = $response;
        $latest_version = $body->version;
        
        // Is our version higher than the remote one? -1 if not, meaning an update is available.
        if(version_compare(WPCS_WAAS_CLIENT_VERSION, $latest_version) >= 0)
        {
            return $update;
        }

        return (array) $body;
    }

    public static function get_remote_data()
    {
        if(!self::$remote_data)
        {
            if(!self::info_url())
            {
                self::$remote_data = new \WP_Error('waas_updater_disabled', 'No update channel is configured.');
                return self::$remote_data;
            }

            $response = wp_remote_get(self::info_url());

            if(is_wp_error($response))
            {
                // Cache the failure so one page load doesn't retry, and never read 'body' off a WP_Error.
                self::$remote_data = $response;
                return self::$remote_data;
            }

            self::$remote_data = json_decode($response['body']);
            self::$remote_data->sections = (array) self::$remote_data->sections;
        }

        return self::$remote_data;
    }

    public static function modify_plugin_details( $result, $action = null, $args = null )
    {
		if( $action !== 'plugin_information' )
        {
            return $result;
        }

        if($args->slug !== WPCS_WAAS_CLIENT_SLUG)
        {
            return $result;
        }
		
        $response = self::get_remote_data();
        if(is_wp_error($response))
        {
            return $result;
        }

        return $response;
    }
}

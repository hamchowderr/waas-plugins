<?php

namespace WaaSHost\Assets;

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
    exit;

class Module
{
    public static function init()
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_confirm_cancel_button']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_waas_product_data_visibility']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_waas_settings_home_styles']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_waas_settings_styles']);
    }

    public static function enqueue_confirm_cancel_button()
    {
        // is_account_page() is WooCommerce's; without WooCommerce there is no account page to add the script to.
        if(function_exists('is_account_page') && \is_account_page())
        {
            $script_name = 'sub-confirm-cancel-button.js';
            $src = plugin_dir_url( __FILE__ ) . "/js/$script_name";
            $version = gmdate("ymd-Gis", (int) filemtime( plugin_dir_path( __FILE__ ) . "js/$script_name" ));
            wp_enqueue_script('confirm-cancel-button', $src, ['jquery'], $version, true);
        }
    }

    public static function enqueue_waas_product_data_visibility()
    {
        $script_name = 'waas-product-data-visibility.js';
        $src = plugin_dir_url( __FILE__ ) . "/js/$script_name";
        $version = gmdate("ymd-Gis", (int) filemtime( plugin_dir_path( __FILE__ ) . "js/$script_name" ));
        wp_enqueue_script('waas-product-data-visibility', $src, ['jquery'], $version, true);
    }

    public static function enqueue_waas_settings_home_styles($hook)
    {
        // Only load on the actual home settings page
        if($hook !== 'toplevel_page_waas-admin') {
            return;
        }

        $style_name = 'waas-settings-home.css';
        $src = plugin_dir_url( __FILE__ ) . "/css/$style_name";
        $version = gmdate("ymd-Gis", (int) filemtime( plugin_dir_path( __FILE__ ) . "css/$style_name" ));
        wp_enqueue_style('waas-settings-home-style', $src, [], $version);
    }

    public static function enqueue_waas_settings_styles($hook)
    {
        // Only load on the actual home settings page
        if($hook !== 'waas_page_waas-admin-settings') {
            return;
        }

        $style_name = 'waas-settings.css';
        $src = plugin_dir_url( __FILE__ ) . "/css/$style_name";
        $version = gmdate("ymd-Gis", (int) filemtime( plugin_dir_path( __FILE__ ) . "css/$style_name" ));
        wp_enqueue_style('waas-settings-style', $src, [], $version);
    }
}

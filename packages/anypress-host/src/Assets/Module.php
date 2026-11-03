<?php

namespace AnyPressHost\Assets;

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
    exit;

class Module
{
    public static function init()
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_confirm_cancel_button']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_anypress_product_data_visibility']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_anypress_admin_styles']);
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

    public static function enqueue_anypress_product_data_visibility()
    {
        $script_name = 'anypress-product-data-visibility.js';
        $src = plugin_dir_url( __FILE__ ) . "/js/$script_name";
        $version = gmdate("ymd-Gis", (int) filemtime( plugin_dir_path( __FILE__ ) . "js/$script_name" ));
        wp_enqueue_script('anypress-product-data-visibility', $src, ['jquery'], $version, true);
    }

    /** The AnyPress look on the plugin's own pages (Overview and Settings), checked by page slug, not hook name. */
    public static function enqueue_anypress_admin_styles()
    {
        if (!\AnyPressHost\Features\AdminHome::is_plugin_page()) {
            return;
        }

        $style_name = 'anypress-admin.css';
        $src = plugin_dir_url( __FILE__ ) . "css/$style_name";
        $version = gmdate("ymd-Gis", (int) filemtime( plugin_dir_path( __FILE__ ) . "css/$style_name" ));
        wp_enqueue_style('anypress-admin-style', $src, [], $version);
    }
}

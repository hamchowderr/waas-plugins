<?php

/*
Plugin Name: AnyPress Move
Plugin URI: https://anypress.io/move
Description: Moves your site's forms, shop, contacts, help desk, affiliates, community, courses and members into the Fluent plugins, with a check first and an undo after. Under Tools, Move to Fluent.
Author: Otaku Solutions
Version: 1.2.0
Author URI: https://otakusolutions.io
Requires at least: 6.9
Requires PHP: 8.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: anypress-move
*/

if (!defined('ABSPATH')) {
    exit;
}

define('ANYPRESS_MOVE_VERSION', '1.2.0');

// AnyPress websites already have every switch in AnyPress Client; this plugin then stays out of the way.
add_action('plugins_loaded', static function () {
    if (defined('ANYPRESS_CLIENT_VERSION')) {
        return;
    }
    if (!is_readable(__DIR__ . '/vendor/autoload.php') || !is_dir(__DIR__ . '/lib')) {
        add_action('admin_notices', static function () {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>' . esc_html__('AnyPress Move is missing some of its files. Install it again from its download.', 'anypress-move') . '</p></div>';
            }
        });
        return;
    }
    require_once __DIR__ . '/vendor/autoload.php';
    \AnyPressMove\Plugin::start();
}, 20);

<?php
/*
Plugin Name: AnyPress Export
Plugin URI: https://github.com/hamchowderr/waas-plugins
Description: Moves this website to AnyPress. Paste the import code AnyPress gave you under Tools → Move to AnyPress, and the plugin packs up your database and files and sends them over. Your site here keeps working the whole time.
Author: Otaku Solutions
Version: 1.0.3
Author URI: https://otakusolutions.io
Requires at least: 5.6
Requires PHP: 7.4
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: anypress-export
*/

defined('ABSPATH') || exit;

define('ANYPRESS_EXPORT_VERSION', '1.0.3');

require_once __DIR__ . '/vendor/autoload.php';

AnyPressExport\Plugin::boot(__FILE__);

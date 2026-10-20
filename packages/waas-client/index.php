<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once(ABSPATH . '/wp-admin/includes/plugin.php');

/*
Plugin Name: WaaS Client
Plugin URI: https://github.com/hamchowderr/waas-plugins
Description: Runs on each WaaS store: one-click login from the storefront, turns plugins on or off to match the store's plan (roles), and lets the AnyPress connector edit the site's design and content.
Author: Otaku Solutions
Version: 2.3.1
Author URI: https://otakusolutions.io
Update URI: wpcs-waas-client
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: waas-client
*/

define( 'WAAS_CLIENT_SLUG', 'wpcs-waas-client' );
define( 'WAAS_CLIENT_BASENAME', plugin_basename( __FILE__ ) );
define( 'WAAS_CLIENT_UPDATE_URI', 'wpcs-waas-client' );
define( 'WAAS_CLIENT_VERSION', '2.3.1' );
define( 'WAAS_CLIENT_TEXTDOMAIN', 'waas-client' );

use WaaSClient\Api\SingleSignOnController;
use WaaSClient\Api\SiteAbilities;
use WaaSClient\Api\SiteController;
use WaaSClient\Core\DecryptionService;
use WaaSClient\Core\HttpService;
use WaaSClient\Features\AdminRolesSettings;
use WaaSClient\Features\PluginBootstrap;
use WaaSClient\Features\RolesManager;
use WaaSClient\Features\AdminTenantSettings;
use WaaSClient\Features\CustomerPlanPage;
use WaaSClient\Features\ConnectPage;

if (!wp_doing_ajax()) {
    define("WAAS_PLUGIN_DIR_URI", plugin_dir_url(__FILE__));
    define("WAAS_PLUGIN_DIR", plugin_dir_path(__FILE__));

    define('WAAS_MAIN_HOST_URL', get_option('waas_host_website_url'));

    // Controllers to list for APIs
    $host_http_service = new HttpService(WAAS_MAIN_HOST_URL . '/wp-json/waas-host/v1');
    $decryptionService = new DecryptionService();

    // Managers to list for Events
    new RolesManager();

    // Plugin Boostrap
    PluginBootstrap::init();

    new SingleSignOnController($decryptionService);

    // Design and content for the AnyPress connector; answers only requests signed with the store's site key.
    // The same actions as WordPress abilities, for the signed-in user (WordPress's MCP Adapter, AI agents on the site).
    new SiteAbilities(new SiteController());
    // "Sign in with your website": the page where a store owner allows an AI app to edit this website.
    new ConnectPage();

    // No self-update: a store's waas-client comes with its template version, and the store gets a
    // newer one by moving to a newer version (waas-hw4). Update URI keeps wordpress.org out.

    if (getenv("WPCS_IS_TENANT") !== 'true') {
        new AdminTenantSettings();
        new AdminRolesSettings($host_http_service);
    } else {
        new CustomerPlanPage();
    }
}

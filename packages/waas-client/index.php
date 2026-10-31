<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once(ABSPATH . '/wp-admin/includes/plugin.php');

/*
Plugin Name: WaaS Client
Plugin URI: https://github.com/hamchowderr/waas-plugins
Description: Runs on each WaaS store: one-click login from the storefront, turns plugins on or off to match the store's plan (roles), and lets the AnyPress connector edit the site's design and content.
Author: Otaku Solutions
Version: 2.8.0
Author URI: https://otakusolutions.io
Update URI: wpcs-waas-client
Requires at least: 6.9
Requires PHP: 8.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: waas-client
*/

define( 'WAAS_CLIENT_SLUG', 'wpcs-waas-client' );
define( 'WAAS_CLIENT_BASENAME', plugin_basename( __FILE__ ) );
define( 'WAAS_CLIENT_UPDATE_URI', 'wpcs-waas-client' );
define( 'WAAS_CLIENT_VERSION', '2.8.0' );
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
use WaaSClient\Features\McpServer;
use WaaSClient\Features\EditorAssistant;
use WaaSClient\Crm\CrmAbilities;
use WaaSClient\Crm\CrmCommand;
use WaaSClient\Crm\CrmSwitch;
use WaaSClient\Forms\ConditionalConfirmations;
use WaaSClient\Forms\FormsAbilities;
use WaaSClient\Forms\FormsCommand;
use WaaSClient\Forms\FormsSwitch;
use WaaSClient\Shop\ShopAbilities;
use WaaSClient\Shop\ShopCommand;
use WaaSClient\Shop\ShopSwitch;

// A converted form's conditional confirmations while Fluent Forms Pro is off. Forms are sent through admin-ajax,
// so this is set up on every request; it only does any work when a converted form is sent.
new ConditionalConfirmations();

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
    // The same abilities as an MCP server, through WordPress's MCP Adapter, while the owner has it on (Your plan page).
    new McpServer();
    // The website assistant beside the page editor and the Site Editor, opened from the admin bar too.
    new EditorAssistant();

    // Moving a site's forms to Fluent Forms (Contact Form 7, WPForms, Ninja Forms): `wp waas forms switch` and
    // the check-forms and switch-forms abilities.
    new FormsAbilities(new FormsSwitch());
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('waas forms', FormsCommand::class);
    }

    // Moving a WooCommerce shop to FluentCart with the FluentCart Migrator add-on, and what it leaves out:
    // `wp waas shop switch` and the check-shop and switch-shop abilities.
    new ShopAbilities(new ShopSwitch());
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('waas shop', ShopCommand::class);
    }

    // Bringing WooCommerce buyers (guests too), MailPoet and Newsletter subscribers into FluentCRM:
    // `wp waas crm switch` and the check-crm and switch-crm abilities.
    new CrmAbilities(new CrmSwitch());
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('waas crm', CrmCommand::class);
    }

    // No self-update: a store's waas-client comes with its template version, and the store gets a
    // newer one by moving to a newer version (waas-hw4). Update URI keeps wordpress.org out.

    if (!PluginBootstrap::is_store()) {
        new AdminTenantSettings();
        new AdminRolesSettings($host_http_service);
    } else {
        new CustomerPlanPage();
    }
}

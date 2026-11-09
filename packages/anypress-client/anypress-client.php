<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once(ABSPATH . '/wp-admin/includes/plugin.php');

/*
Plugin Name: AnyPress Client
Plugin URI: https://github.com/hamchowderr/waas-plugins
Description: Runs on each AnyPress website: one-click login from the storefront, turns plugins on or off to match the store's plan (roles), and lets the AnyPress connector edit the site's design and content.
Author: Otaku Solutions
Version: 2.16.1
Author URI: https://otakusolutions.io
Update URI: anypress-client
Requires at least: 6.9
Requires PHP: 8.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: anypress-client
*/

define( 'ANYPRESS_CLIENT_SLUG', 'anypress-client' );
define( 'ANYPRESS_CLIENT_BASENAME', plugin_basename( __FILE__ ) );
define( 'ANYPRESS_CLIENT_UPDATE_URI', 'anypress-client' );
define( 'ANYPRESS_CLIENT_VERSION', '2.16.1' );
define( 'ANYPRESS_CLIENT_TEXTDOMAIN', 'anypress-client' );

use AnyPressClient\Api\AdminLogin;
use AnyPressClient\Api\SingleSignOnController;
use AnyPressClient\Api\SiteAbilities;
use AnyPressClient\Api\PhoneController;
use AnyPressClient\Api\SiteController;
use AnyPressClient\Core\Brand;
use AnyPressClient\Core\DecryptionService;
use AnyPressClient\Core\HttpService;
use AnyPressClient\Features\AdminRolesSettings;
use AnyPressClient\Features\PluginBootstrap;
use AnyPressClient\Features\RolesManager;
use AnyPressClient\Features\AdminTenantSettings;
use AnyPressClient\Features\CustomerPlanPage;
use AnyPressClient\Features\ConnectPage;
use AnyPressClient\Features\McpServer;
use AnyPressClient\Features\EditorAssistant;
use AnyPressClient\Crm\CrmAbilities;
use AnyPressClient\Crm\CrmCommand;
use AnyPressClient\Crm\CrmSwitch;
use AnyPressClient\Forms\ConditionalConfirmations;
use AnyPressClient\Forms\FormsAbilities;
use AnyPressClient\Forms\FormsCommand;
use AnyPressClient\Forms\FormsSwitch;
use AnyPressClient\Moving\AffiliateImport;
use AnyPressClient\Moving\CommunityImport;
use AnyPressClient\Moving\ImportAbilities;
use AnyPressClient\Moving\ImportCommand;
use AnyPressClient\Moving\SupportImport;
use AnyPressClient\Shop\ShopAbilities;
use AnyPressClient\Shop\ShopCommand;
use AnyPressClient\Shop\ShopSwitch;
use AnyPressClient\Crm\HighLevelLink;

// A converted form's conditional confirmations while Fluent Forms Pro is off. Forms are sent through admin-ajax,
// so this is set up on every request; it only does any work when a converted form is sent.
new ConditionalConfirmations();

// A HighLevel agency's site: WP Fusion connected to the site's own sub-account with a token from the AnyPress API.
// On every request (admin-ajax too), since WP Fusion reads the token wherever it sends a contact.
new HighLevelLink();

if (!wp_doing_ajax()) {
    define("ANYPRESS_PLUGIN_DIR_URI", plugin_dir_url(__FILE__));
    define("ANYPRESS_PLUGIN_DIR", plugin_dir_path(__FILE__));

    define('ANYPRESS_MAIN_HOST_URL', get_option('anypress_host_website_url'));

    // Controllers to list for APIs
    $host_http_service = new HttpService(ANYPRESS_MAIN_HOST_URL . '/wp-json/anypress-host/v1');
    $decryptionService = new DecryptionService();

    // Managers to list for Events
    new RolesManager();

    // Plugin Boostrap
    PluginBootstrap::init();
    // On a reseller's website the plugin goes by the reseller's brand (anypress_brand_name, set by the AnyPress API).
    Brand::init();

    new SingleSignOnController($decryptionService);
    // "Log in to WordPress" from the AnyPress dashboard: a one-time link the API signs with the store's site key.
    new AdminLogin();

    // Design and content for the AnyPress connector; answers only requests signed with the store's site key.
    // The same actions as WordPress abilities, for the signed-in user (WordPress's MCP Adapter, AI agents on the site).
    $siteController = new SiteController();
    new SiteAbilities($siteController);
    // The phone receptionist: booking types, open times, phone bookings and callers in FluentCRM. Signed like the site routes.
    new PhoneController($siteController);
    // "Sign in with your website": the page where a store owner allows an AI app to edit this website.
    new ConnectPage();
    // The same abilities as an MCP server, through WordPress's MCP Adapter, while the owner has it on (Your plan page).
    new McpServer();
    // The website assistant beside the page editor and the Site Editor, opened from the admin bar too.
    new EditorAssistant();

    // Moving a site's forms to Fluent Forms (Contact Form 7, WPForms, Ninja Forms): `wp anypress forms switch` and
    // the check-forms and switch-forms abilities.
    new FormsAbilities(new FormsSwitch());
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('anypress forms', FormsCommand::class);
    }

    // Moving a WooCommerce shop to FluentCart with the FluentCart Migrator add-on, and what it leaves out:
    // `wp anypress shop switch` and the check-shop and switch-shop abilities.
    new ShopAbilities(new ShopSwitch());
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('anypress shop', ShopCommand::class);
    }

    // Bringing WooCommerce buyers (guests too), MailPoet and Newsletter subscribers into FluentCRM:
    // `wp anypress crm switch` and the check-crm and switch-crm abilities.
    new CrmAbilities(new CrmSwitch());
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('anypress crm', CrmCommand::class);
    }

    // Help desk, affiliate and community data into Fluent Support, FluentAffiliate and FluentCommunity with their own
    // importers: `wp anypress support|affiliate|community switch` and the check-* and switch-* abilities.
    $imports = [new SupportImport(), new AffiliateImport(), new CommunityImport()];
    new ImportAbilities(...$imports);
    if (defined('WP_CLI') && WP_CLI) {
        foreach ($imports as $import) {
            \WP_CLI::add_command('anypress ' . $import->key(), new ImportCommand($import));
        }
    }

    // No self-update: a store's anypress-client comes with its template version, and the store gets a
    // newer one by moving to a newer version (waas-hw4). Update URI keeps wordpress.org out.

    if (!PluginBootstrap::is_store()) {
        new AdminTenantSettings();
        new AdminRolesSettings($host_http_service);
    } else {
        new CustomerPlanPage();
    }
}

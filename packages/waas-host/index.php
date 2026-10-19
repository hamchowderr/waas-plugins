<?php

use WaaSHost\Api\AccountClientsCommand;
use WaaSHost\Api\AccountController;
use WaaSHost\Api\AccountSignIn;
use WaaSHost\Api\AccountTokens;
use WaaSHost\Api\RolesController;
use WaaSHost\Api\SingleLogin;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Commerce\FluentCart\FluentCartAdapter;
use WaaSHost\Commerce\WooCommerce\WooCommerceAdapter;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\HttpService;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Features\NameConflicts;
use WaaSHost\Features\StoreJobs;
use WaaSHost\Features\TenantsAddOnSubscriptionManager;
use WaaSHost\Features\TenantsSubscriptionManager;
use WaaSHost\Features\AdminSettings;
use WaaSHost\Features\AdminHome;
use WaaSHost\Features\AdminNotices;
use WaaSHost\Features\Notifications;
use WaaSHost\Features\TenantStatusService;
use WaaSHost\Features\WebsiteSettings;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * @package WaaSHost
 * @version 2.2.7
 */
/*
Plugin Name: WaaS Host
Plugin URI: https://github.com/hamchowderr/waas-plugins
Description: Sells WaaS stores from your storefront: creates, suspends and restores each customer's store through the WaaS provisioning API, with FluentCart or WooCommerce (and WooCommerce Subscriptions) for billing.
Author: Otaku Solutions
Version: 2.2.7
Author URI: https://otakusolutions.io
Update URI: wpcs-waas-host
Requires at least: 6.4
Requires PHP: 8.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: waas-host
*/

define( 'WAAS_HOST_SLUG', 'wpcs-waas-host' );
define( 'WAAS_HOST_BASENAME', plugin_basename( __FILE__ ) );
define( 'WAAS_HOST_UPDATE_URI', 'wpcs-waas-host' );
define( 'WAAS_HOST_VERSION', '2.2.7' );
define( 'WAAS_HOST_TEXTDOMAIN', 'waas-host' );

// Controllers to list for APIs
$configService = new ConfigService();
$http_service = new HttpService($configService);
$provisioningApi = new ProvisioningApi($http_service);
new RolesController($provisioningApi);

// Managers to list for Events

// UI
SingleLogin::init();
// Store API calls run as background jobs, never inside the cart's hooks
$storeJobs = new StoreJobs();
$nameConflicts = new NameConflicts($provisioningApi, $storeJobs);
new TenantsSubscriptionManager($provisioningApi, $storeJobs, $nameConflicts);
new TenantsAddOnSubscriptionManager($provisioningApi, $storeJobs);

// Cart: the first active one registered here wins; the wpcs_commerce_adapter filter can override
Commerce::register(new FluentCartAdapter());
Commerce::register(new WooCommerceAdapter());
Commerce::boot($provisioningApi);

new TenantStatusService($provisioningApi);
// The customer's websites as a REST API (waas-host/v1/account), with token sign-in for front ends on other domains
$accountTokens = new AccountTokens();
new AccountController(new WebsiteSettings($provisioningApi), $nameConflicts, $configService, $accountTokens);
new AccountSignIn($accountTokens);
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('waas-host account-client', AccountClientsCommand::class);
}
AdminNotices::init($configService);
new AdminHome($configService);
new AdminSettings($provisioningApi, $configService);
WaaSHost\Assets\Module::init();

WaaSHost\Updater\Module::init($configService);
new WaaSHost\Migrations\Module($provisioningApi);
Notifications::init();


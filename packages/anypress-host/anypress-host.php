<?php

if (!defined('ABSPATH')) {
    exit;
}

use AnyPressHost\Api\AccountClientsCommand;
use AnyPressHost\Api\AccountController;
use AnyPressHost\Api\AccountSignIn;
use AnyPressHost\Api\AccountTokens;
use AnyPressHost\Api\RolesController;
use AnyPressHost\Api\AssistantSignIn;
use AnyPressHost\Api\AdminLogin;
use AnyPressHost\Api\SingleLogin;
use AnyPressHost\Api\WebhookReceiver;
use AnyPressHost\Commerce\Commerce;
use AnyPressHost\Commerce\FluentCart\FluentCartAdapter;
use AnyPressHost\Commerce\WooCommerce\WooCommerceAdapter;
use AnyPressHost\Core\ConfigService;
use AnyPressHost\Core\HttpService;
use AnyPressHost\Core\ProvisioningApi;
use AnyPressHost\Features\NameConflicts;
use AnyPressHost\Features\RunSoon;
use AnyPressHost\Features\StoreJobs;
use AnyPressHost\Features\TenantsAddOnSubscriptionManager;
use AnyPressHost\Features\TenantsSubscriptionManager;
use AnyPressHost\Features\AdminSettings;
use AnyPressHost\Features\AdminHome;
use AnyPressHost\Features\AdminNotices;
use AnyPressHost\Features\Notifications;
use AnyPressHost\Features\TenantStatusService;
use AnyPressHost\Features\Webhooks;
use AnyPressHost\Features\WebsiteSettings;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * @package AnyPressHost
 * @version 2.11.0
 */
/*
Plugin Name: AnyPress Host
Plugin URI: https://github.com/hamchowderr/waas-plugins
Description: Sells AnyPress websites from your storefront: creates, suspends and restores each customer's website through the AnyPress API, with FluentCart or WooCommerce (and WooCommerce Subscriptions) for billing.
Author: Otaku Solutions
Version: 2.11.0
Author URI: https://otakusolutions.io
Update URI: anypress-host
Requires at least: 6.4
Requires PHP: 8.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: anypress-host
*/

define( 'ANYPRESS_HOST_SLUG', 'anypress-host' );
define( 'ANYPRESS_HOST_BASENAME', plugin_basename( __FILE__ ) );
define( 'ANYPRESS_HOST_UPDATE_URI', 'anypress-host' );
define( 'ANYPRESS_HOST_VERSION', '2.11.0' );
define( 'ANYPRESS_HOST_TEXTDOMAIN', 'anypress-host' );

// Settings and data saved before the AnyPress names (2.11.0) move to them before anything reads them
AnyPressHost\Migrations\OldNames::maybe_run();

// Controllers to list for APIs
$configService = new ConfigService();
$http_service = new HttpService($configService);
$provisioningApi = new ProvisioningApi($http_service);
new RolesController($provisioningApi);
new AssistantSignIn($configService, $provisioningApi);

// Managers to list for Events

// UI
SingleLogin::init();
// "Log in to WordPress" from the AnyPress dashboard: a one-time link the API signs with this site's key.
AdminLogin::init();
// Store API calls run as background jobs, never inside the cart's hooks
// Background work starts when the request that asked for it ends, not on the next cron run
new RunSoon();
$storeJobs = new StoreJobs();
$nameConflicts = new NameConflicts($provisioningApi, $storeJobs);
new TenantsSubscriptionManager($provisioningApi, $storeJobs, $nameConflicts);
new TenantsAddOnSubscriptionManager($provisioningApi, $storeJobs);

// Cart: the first active one registered here wins; the anypress_commerce_adapter filter can override
Commerce::register(new FluentCartAdapter());
Commerce::register(new WooCommerceAdapter());
Commerce::boot($provisioningApi);

$tenantStatusService = new TenantStatusService($provisioningApi);
// The API's signed events (anypress-host/v1/webhook) act at once; the status poll stays on as a backstop
new WebhookReceiver($tenantStatusService);
// The customer's websites as a REST API (anypress-host/v1/account), with token sign-in for front ends on other domains
$accountTokens = new AccountTokens();
new AccountController(new WebsiteSettings($provisioningApi), $nameConflicts, $configService, $accountTokens);
new AccountSignIn($accountTokens);
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('anypress-host account-client', AccountClientsCommand::class);
}
AdminNotices::init($configService);
new AdminHome($configService);
new AdminSettings($provisioningApi, $configService);
new Webhooks($provisioningApi, $configService);
AnyPressHost\Assets\Module::init();

AnyPressHost\Updater\Module::init($configService);
new AnyPressHost\Migrations\Module($provisioningApi);
Notifications::init();


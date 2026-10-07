<?php

use WaaSHost\Api\RolesController;
use WaaSHost\Api\SingleLogin;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Commerce\FluentCart\FluentCartAdapter;
use WaaSHost\Commerce\WooCommerce\WooCommerceAdapter;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\HttpService;
use WaaSHost\Core\WPCSService;
use WaaSHost\Features\StoreJobs;
use WaaSHost\Features\TenantsAddOnSubscriptionManager;
use WaaSHost\Features\TenantsSubscriptionManager;
use WaaSHost\Features\AdminWpcsSettings;
use WaaSHost\Features\AdminWpcsHome;
use WaaSHost\Features\AdminNotices;
use WaaSHost\Features\Notifications;
use WaaSHost\Features\WPCSTenantStatusService;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * @package WaaSHost
 * @version 2.2.7
 */
/*
Plugin Name: WaaS Host
Plugin URI: https://github.com/hamchowderr/waas-plugins
Description: This plugin is used to create tenants on WPCS.io with support of WordPress, FluentCart, WooCommerce, WooCommerce Subscriptions and Self-service Dashboard for WooCommerce Subscriptions.
Author: WPCS
Version: 2.2.7
Author URI: https://wpcs.io
Update URI: wpcs-waas-host
Requires at least: 6.4
Requires PHP: 8.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: wpcs-waas-host-textdomain
*/

define( 'WPCS_WAAS_HOST_SLUG', 'wpcs-waas-host' );
define( 'WPCS_WAAS_HOST_BASENAME', plugin_basename( __FILE__ ) );
define( 'WPCS_WAAS_HOST_UPDATE_URI', 'wpcs-waas-host' );
define( 'WPCS_WAAS_HOST_VERSION', '2.2.7' );
define( 'WPCS_WAAS_HOST_TEXTDOMAIN', 'wpcs-waas-host-textdomain' );

// Controllers to list for APIs
$wpcsConfigService = new ConfigService();
$wpcs_http_service = new HttpService($wpcsConfigService);
$wpcsService = new WPCSService($wpcs_http_service);
new RolesController($wpcsService);

// Managers to list for Events

// UI
SingleLogin::init();
// Store API calls run as background jobs, never inside the cart's hooks
$storeJobs = new StoreJobs();
new TenantsSubscriptionManager($wpcsService, $storeJobs);
new TenantsAddOnSubscriptionManager($wpcsService, $storeJobs);

// Cart: the first active one registered here wins; the wpcs_commerce_adapter filter can override
Commerce::register(new FluentCartAdapter());
Commerce::register(new WooCommerceAdapter());
Commerce::boot($wpcsService);

new WPCSTenantStatusService($wpcsService);
AdminNotices::init($wpcsConfigService);
new AdminWpcsHome($wpcsConfigService);
new AdminWpcsSettings($wpcsService, $wpcsConfigService);
WaaSHost\Assets\Module::init();

WaaSHost\Updater\Module::init();
new WaaSHost\Migrations\Module($wpcsService);
Notifications::init();


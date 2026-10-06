<?php

use WaaSHost\Api\RolesController;
use WaaSHost\Api\SingleLogin;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Commerce\WooCommerce\WooCommerceAdapter;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\HttpService;
use WaaSHost\Core\WPCSService;
use WaaSHost\Features\TenantsAddOnSubscriptionManager;
use WaaSHost\Features\TenantsSubscriptionManager;
use WaaSHost\Features\AdminWpcsSettings;
use WaaSHost\Features\AdminWpcsHome;
use WaaSHost\Features\AdminNotices;
use WaaSHost\Features\Notifications;
use WaaSHost\Features\WPCSTenantStatusService;

require_once 'vendor/autoload.php';

/**
 * @package WaaSHost
 * @version 2.2.7
 */
/*
Plugin Name: WaaS Host
Plugin URI: https://github.com/Daxez/waas-wpcs
Description: This plugin is used to create tenants on WPCS.io with support of WordPress, WooCommerce, WooCommerce Subscriptions and Self-service Dashboard for WooCommerce Subscriptions.
Author: WPCS
Version: 2.2.7
Author URI: https://wpcs.io
Update URI: wpcs-waas-host
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
new TenantsSubscriptionManager($wpcsService);
new TenantsAddOnSubscriptionManager($wpcsService);

// Cart: the first active one registered here (WooCommerce today; FluentCart next)
Commerce::register(new WooCommerceAdapter());
Commerce::boot($wpcsService);

new WPCSTenantStatusService($wpcsService);
AdminNotices::init();
new AdminWpcsHome($wpcsConfigService);
new AdminWpcsSettings($wpcsService, $wpcsConfigService);
WaaSHost\Assets\Module::init();

WaaSHost\Updater\Module::init();
new WaaSHost\Migrations\Module($wpcsService);
Notifications::init();


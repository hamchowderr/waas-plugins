<?php
/**
 * Plugin Name: WaaS Payments
 * Plugin URI: https://github.com/hamchowderr/waas-plugins
 * Description: Card payments for FluentCart through the store's payment gateway, including subscriptions charged automatically on renewal (FluentCart store-managed subscriptions). First gateway: Authorize.Net.
 * Version: 0.1.0
 * Author: Otaku Solutions
 * Author URI: https://otakusolutions.io
 * Update URI: waas-payments
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: fluent-cart
 * Text Domain: waas-payments
 * License: GPLv2 or later
 */

defined('ABSPATH') || exit;

define('WAAS_PAYMENTS_VERSION', '0.1.0');
define('WAAS_PAYMENTS_URL', plugin_dir_url(__FILE__));
define('WAAS_PAYMENTS_BASENAME', plugin_basename(__FILE__));

require_once __DIR__ . '/vendor/autoload.php';

// Updates from our own channel, with the storefront's API credentials from waas-host (waas-hw4).
(new \WaaSPayments\Updater\Updater([
    'slug' => 'waas-payments',
    'name' => 'WaaS Payments',
    'basename' => WAAS_PAYMENTS_BASENAME,
    'version' => WAAS_PAYMENTS_VERSION,
    'update_uri' => 'waas-payments',
]))->register();

// Settings link on the Plugins screen: the gateway's settings live in FluentCart's payment settings.
add_filter('plugin_action_links_' . WAAS_PAYMENTS_BASENAME, function ($links) {
    $url = admin_url('admin.php?page=fluent-cart#/settings/payments/' . \WaaSPayments\Gateway\AuthorizeNetGateway::ROUTE);
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'waas-payments') . '</a>');

    return $links;
});

// FluentCart fires this on init after registering its own gateways
// (app/Hooks/Handlers/GlobalPaymentHandler.php:36). GatewayManager::register calls boot().
add_action('fluent_cart/register_payment_methods', function ($args) {
    $args['gatewayManager']->register(
        \WaaSPayments\Gateway\AuthorizeNetGateway::ROUTE,
        new \WaaSPayments\Gateway\AuthorizeNetGateway()
    );
});

<?php
/**
 * Plugin Name: WaaS Payments
 * Description: Card payments for FluentCart through the store's payment gateway. One-time payments only. First gateway: Authorize.Net.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: fluent-cart
 * Text Domain: waas-payments
 * License: GPLv2 or later
 */

defined('ABSPATH') || exit;

define('WAAS_PAYMENTS_VERSION', '0.1.0');
define('WAAS_PAYMENTS_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/vendor/autoload.php';

// FluentCart fires this on init after registering its own gateways
// (app/Hooks/Handlers/GlobalPaymentHandler.php:36). GatewayManager::register calls boot().
add_action('fluent_cart/register_payment_methods', function ($args) {
    $args['gatewayManager']->register(
        \WaaSPayments\Gateway\AuthorizeNetGateway::ROUTE,
        new \WaaSPayments\Gateway\AuthorizeNetGateway()
    );
});

<?php
/**
 * Plugin Name: AnyPress Payments
 * Plugin URI: https://github.com/hamchowderr/waas-plugins
 * Description: Card payments for FluentCart through the store's payment gateway, including subscriptions charged automatically on renewal (FluentCart store-managed subscriptions). First gateway: Authorize.Net.
 * Version: 0.2.0-beta.5
 * Author: Otaku Solutions
 * Author URI: https://otakusolutions.io
 * Update URI: anypress-payments
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: fluent-cart
 * Text Domain: anypress-payments
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

defined('ABSPATH') || exit;

define('ANYPRESS_PAYMENTS_VERSION', '0.2.0-beta.5');
define('ANYPRESS_PAYMENTS_URL', plugin_dir_url(__FILE__));
define('ANYPRESS_PAYMENTS_BASENAME', plugin_basename(__FILE__));

require_once __DIR__ . '/vendor/autoload.php';

// Updates from our own channel, with the storefront's API credentials from anypress-host (waas-hw4).
(new \AnyPressPayments\Updater\Updater([
    'slug' => 'anypress-payments',
    'name' => 'AnyPress Payments',
    'basename' => ANYPRESS_PAYMENTS_BASENAME,
    'version' => ANYPRESS_PAYMENTS_VERSION,
    'update_uri' => 'anypress-payments',
]))->register();

// Settings link on the Plugins screen: the gateway's settings live in FluentCart's payment settings.
add_filter('plugin_action_links_' . ANYPRESS_PAYMENTS_BASENAME, function ($links) {
    $url = admin_url('admin.php?page=fluent-cart#/settings/payments/' . \AnyPressPayments\Gateway\AuthorizeNetGateway::ROUTE);
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'anypress-payments') . '</a>');

    return $links;
});

// FluentCart fires this on init after registering its own gateways
// (app/Hooks/Handlers/GlobalPaymentHandler.php:36). GatewayManager::register calls boot().
add_action('fluent_cart/register_payment_methods', function ($args) {
    $args['gatewayManager']->register(
        \AnyPressPayments\Gateway\AuthorizeNetGateway::ROUTE,
        new \AnyPressPayments\Gateway\AuthorizeNetGateway()
    );
});

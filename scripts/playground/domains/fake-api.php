<?php
/**
 * Plugin Name: Fake AnyPress API (domains check)
 * Answers the AnyPress API's domain routes for the domains check, and records what the Host plugin sent.
 */
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'https://api.example.test') !== 0) {
        return $pre;
    }
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    $calls = get_option('fake_api_calls', []);
    $calls[] = ['method' => $args['method'] ?? 'GET', 'url' => $url, 'body' => isset($args['body']) ? json_decode((string) $args['body'], true) : null];
    update_option('fake_api_calls', $calls, false);
    $body = new stdClass();
    if ($path === '/v1/tenants/domains/search') {
        $body = ['results' => [
            ['domainName' => 'mybakery.com', 'available' => true, 'priceCents' => 1500, 'renewalPriceCents' => 1700, 'reason' => null],
            ['domainName' => 'mybakery.shop', 'available' => true, 'priceCents' => 900, 'renewalPriceCents' => 4200, 'reason' => null],
            ['domainName' => 'mybakery.net', 'available' => false, 'priceCents' => null, 'renewalPriceCents' => null, 'reason' => 'This domain is taken'],
        ]];
    } elseif ($path === '/v1/tenants/domains/buy') {
        $body = ['domainName' => 'mybakery.com', 'status' => 'registering', 'paidBy' => 'storefront'];
    } elseif ($path === '/v1/tenants/domains/bought/renew') {
        $body = ['domainName' => 'mybakery.com', 'status' => 'active'];
    }
    return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);

// SQLite has no MySQL locks; FluentCart's rate limiter takes one per instant checkout. Playground only.
if (isset($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'get_driver')) {
    $pdo = $GLOBALS['wpdb']->get_driver()->get_connection()->get_pdo();
    $pdo->sqliteCreateFunction('GET_LOCK', static fn ($name, $timeout) => 1, 2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK', static fn ($name) => 1, 1);
}

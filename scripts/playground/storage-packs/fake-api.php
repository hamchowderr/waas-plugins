<?php
/**
 * Plugin Name: Fake AnyPress API (storage packs check)
 * Answers the AnyPress API's storage-packs route for the storage packs check, and records what the Host plugin sent.
 */
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'https://api.example.test') !== 0) {
        return $pre;
    }
    $calls = get_option('fake_api_calls', []);
    $body = isset($args['body']) ? json_decode((string) $args['body'], true) : null;
    $calls[] = ['method' => $args['method'] ?? 'GET', 'url' => $url, 'body' => $body];
    update_option('fake_api_calls', $calls, false);
    if (get_option('fake_api_down')) {
        return ['headers' => [], 'body' => '{"error":"down"}', 'response' => ['code' => 503, 'message' => 'Unavailable'], 'cookies' => [], 'filename' => null];
    }
    $packs = (int) ($body['packs'] ?? 0);
    $answer = ['tenantId' => 't-1', 'externalId' => 'ext-bakery', 'storagePacks' => $packs, 'storagePacksGb' => $packs * 25];
    return ['headers' => [], 'body' => wp_json_encode($answer), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);

// SQLite has no MySQL locks; FluentCart's rate limiter takes one per instant checkout. Playground only.
if (isset($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'get_driver')) {
    $pdo = $GLOBALS['wpdb']->get_driver()->get_connection()->get_pdo();
    $pdo->sqliteCreateFunction('GET_LOCK', static fn ($name, $timeout) => 1, 2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK', static fn ($name) => 1, 1);
}

<?php
/**
 * Minimal stand-ins for the WordPress functions the Core classes use, so they
 * can run under PHPUnit without a WordPress install. HTTP goes out over cURL.
 */

define('ABSPATH', __DIR__ . '/');

require __DIR__ . '/../vendor/autoload.php';

class WP_Error
{
    private string $message;

    public function __construct($code = '', $message = '')
    {
        $this->message = $message;
    }

    public function get_error_message()
    {
        return $this->message;
    }
}

final class WpStub
{
    public static array $options = [];
    public static array $filters = [];
    /** Extra headers added to every outgoing request (e.g. x-mock-response-name). */
    public static array $extra_headers = [];
    /** Every outgoing request, for assertions: [method, url, headers, body]. */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$options = [];
        self::$filters = [];
        self::$extra_headers = [];
        self::$requests = [];
    }
}

function get_option($name, $default = false)
{
    return array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
}

function update_option($name, $value)
{
    WpStub::$options[$name] = $value;
    return true;
}

function apply_filters($hook, $value, ...$args)
{
    foreach (WpStub::$filters[$hook] ?? [] as $callback) {
        $value = $callback($value, ...$args);
    }
    return $value;
}

function untrailingslashit($value)
{
    return rtrim($value, '/\\');
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code($response)
{
    return is_wp_error($response) ? '' : $response['response']['code'];
}

function wp_remote_retrieve_body($response)
{
    return is_wp_error($response) ? '' : $response['body'];
}

function wp_remote_get($url, $args = [])
{
    return wp_stub_request($args['method'] ?? 'GET', $url, $args);
}

function wp_remote_post($url, $args = [])
{
    return wp_stub_request($args['method'] ?? 'POST', $url, $args);
}

function wp_stub_request($method, $url, $args)
{
    $headers = array_merge($args['headers'] ?? [], WpStub::$extra_headers);
    WpStub::$requests[] = [$method, $url, $headers, $args['body'] ?? null];

    $curl = curl_init($url);
    $header_lines = [];
    foreach ($headers as $key => $value) {
        $header_lines[] = $key . ': ' . $value;
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $header_lines,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        // WordPress defaults to 5 s; the plugin passes 20 s for writes.
        CURLOPT_TIMEOUT => $args['timeout'] ?? 5,
    ]);
    if (isset($args['body'])) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $args['body']);
    }

    $body = curl_exec($curl);
    if ($body === false) {
        $error = new WP_Error('http_request_failed', curl_error($curl));
        curl_close($curl);
        return $error;
    }

    $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return ['body' => $body, 'response' => ['code' => $code]];
}

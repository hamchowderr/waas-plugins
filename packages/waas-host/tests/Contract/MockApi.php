<?php

namespace Tests\Contract;

use Brain\Monkey\Functions;

/**
 * Routes WordPress's HTTP functions to cURL so the plugin's real API client talks to the provisioning API (by
 * default the public Postman mock, see phpunit.xml.dist). The mock picks a response by the x-mock-response-name
 * header, named "<request>: <example>".
 */
trait MockApi
{
    /** Extra headers added to every outgoing request (e.g. x-mock-response-name). */
    private array $extra_headers = [];
    /** Every outgoing request: [method, url, headers, body]. */
    private array $requests = [];
    private array $options = [];

    private function use_mock_api(array $options = []): void
    {
        if (!class_exists('WP_Error')) {
            eval('class WP_Error { private $message; public function __construct($code = "", $message = "") { $this->message = $message; } public function get_error_message() { return $this->message; } }');
        }

        $this->extra_headers = [];
        $this->requests = [];
        $this->options = array_merge([
            'wpcs_credentials_api_base_url_setting' => getenv('WAAS_TEST_API_BASE_URL'),
            'wpcs_credentials_api_key_setting' => 'test-key',
            'wpcs_credentials_api_secret_setting' => 'test-secret',
        ], $options);

        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('untrailingslashit')->alias(fn ($value) => rtrim($value, '/\\'));
        Functions\when('is_wp_error')->alias(fn ($thing) => $thing instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn ($r) => $r instanceof \WP_Error ? '' : $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn ($r) => $r instanceof \WP_Error ? '' : $r['body']);
        Functions\when('wp_remote_get')->alias(fn ($url, $args = []) => $this->request($args['method'] ?? 'GET', $url, $args));
        Functions\when('wp_remote_post')->alias(fn ($url, $args = []) => $this->request($args['method'] ?? 'POST', $url, $args));
        Functions\when('wp_remote_head')->alias(fn ($url, $args = []) => $this->request('HEAD', $url, $args));
    }

    private function mock_example(string $name): void
    {
        $this->extra_headers['x-mock-response-name'] = $name;
    }

    private function request(string $method, string $url, array $args)
    {
        $headers = array_merge($args['headers'] ?? [], $this->extra_headers);
        $this->requests[] = [$method, $url, $headers, $args['body'] ?? null];

        $curl = curl_init($url);
        $header_lines = [];
        foreach ($headers as $key => $value) {
            $header_lines[] = $key . ': ' . $value;
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            // These tests check request and response shapes, not timeouts: give the public mock room for a
            // cold start instead of WordPress's 5 s default (the plugin passes 20 s for writes).
            CURLOPT_TIMEOUT => max($args['timeout'] ?? 5, 30),
        ]);
        if (isset($args['body'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $args['body']);
        }

        $body = curl_exec($curl);
        if ($body === false) {
            $error = new \WP_Error('http_request_failed', curl_error($curl));
            curl_close($curl);
            return $error;
        }

        $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['body' => $body, 'response' => ['code' => $code]];
    }
}

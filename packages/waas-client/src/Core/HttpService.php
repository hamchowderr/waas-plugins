<?php

namespace WaaSClient\Core;

use Exception;

class HttpService
{
    private string $base_uri;

    public function __construct($base_uri)
    {
        $this->base_uri = $base_uri;
    }

    /**
     * Decoded response body, whatever the HTTP status. Kept for existing callers; use request() when the
     * status matters.
     *
     * @throws Exception when the request itself fails (DNS, TLS, timeout).
     */
    public function get($uri)
    {
        $response = wp_remote_get($this->base_uri . $uri);

        if (is_wp_error($response)) {
            throw new Exception("Failed");
        }

        return json_decode($response['body']);
    }

    /**
     * GET that never throws and keeps the status.
     *
     * @return array{code: int, body: mixed, error: ?string} code is 0 and error is set when no HTTP answer came back.
     */
    public function request(string $uri, array $args = []): array
    {
        $response = wp_remote_get($this->base_uri . $uri, $args + ['timeout' => 10]);

        if (is_wp_error($response)) {
            return ['code' => 0, 'body' => null, 'error' => $response->get_error_message()];
        }

        return [
            'code' => (int) wp_remote_retrieve_response_code($response),
            'body' => json_decode((string) wp_remote_retrieve_body($response), true),
            'error' => null,
        ];
    }
}

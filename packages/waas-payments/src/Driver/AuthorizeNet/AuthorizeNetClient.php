<?php

namespace WaaSPayments\Driver\AuthorizeNet;

/**
 * Sends one request to Authorize.Net's JSON API.
 *
 * Authorize.Net's official PHP SDK (authorizenet/authorizenet) is not used: it posts with raw
 * curl (lib/net/authorize/util/HttpClient.php:68), which skips WordPress's HTTP API and the
 * site's proxy and certificate settings, and it autoloads 278 classes by classmap under the
 * shared net\authorize namespace (not scoped to this plugin), which collide with any other
 * plugin on the site that bundles a different SDK version. The calls this plugin makes are
 * small.
 *
 * The JSON API is a translation of the XML schema, so keys must follow the schema's element
 * order (AnetApiSchema.xsd in the SDK repo); the builders in AuthorizeNetDriver do.
 */
class AuthorizeNetClient
{
    // Endpoints from the SDK: lib/net/authorize/api/constants/ANetEnvironment.php:7-8.
    const SANDBOX_URL = 'https://apitest.authorize.net/xml/v1/request.api';
    const PRODUCTION_URL = 'https://api2.authorize.net/xml/v1/request.api';
    // Webhooks REST API base URLs.
    const SANDBOX_REST_URL = 'https://apitest.authorize.net/rest/v1/';
    const PRODUCTION_REST_URL = 'https://api.authorize.net/rest/v1/';

    private $apiLoginId;
    private $transactionKey;
    private $sandbox;

    public function __construct(string $apiLoginId, string $transactionKey, bool $sandbox)
    {
        $this->apiLoginId = $apiLoginId;
        $this->transactionKey = $transactionKey;
        $this->sandbox = $sandbox;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    /**
     * @param string $requestName Root element, e.g. createTransactionRequest.
     * @param array $fields Everything after merchantAuthentication, in schema order.
     * @return array|\WP_Error Decoded response.
     */
    public function send(string $requestName, array $fields)
    {
        $payload = [
            $requestName => array_merge([
                'merchantAuthentication' => [
                    'name'           => $this->apiLoginId,
                    'transactionKey' => $this->transactionKey,
                ],
            ], $fields),
        ];

        $response = wp_remote_post($this->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
            'timeout' => 45,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        // Authorize.Net prefixes JSON responses with a UTF-8 byte order mark.
        $body = preg_replace('/^\xEF\xBB\xBF/', '', (string) wp_remote_retrieve_body($response));
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return new \WP_Error(
                'authorizenet_bad_response',
                sprintf('Authorize.Net returned an unreadable response (HTTP %s).', wp_remote_retrieve_response_code($response))
            );
        }

        return $decoded;
    }

    /**
     * One request to the Webhooks REST API, which uses HTTP Basic auth with the same credentials.
     *
     * @param string $path e.g. 'webhooks' or 'webhooks/{id}'
     * @return array|\WP_Error Decoded response; an error response decodes to {status, reason, message}.
     */
    public function rest(string $method, string $path, ?array $body = null)
    {
        $args = [
            'method'  => $method,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->apiLoginId . ':' . $this->transactionKey),
            ],
            'timeout' => 45,
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request(($this->sandbox ? self::SANDBOX_REST_URL : self::PRODUCTION_REST_URL) . $path, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $decoded = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string) wp_remote_retrieve_body($response)), true);

        if (!is_array($decoded)) {
            return new \WP_Error(
                'authorizenet_bad_response',
                sprintf('Authorize.Net returned an unreadable response (HTTP %s).', wp_remote_retrieve_response_code($response))
            );
        }

        return $decoded;
    }
}

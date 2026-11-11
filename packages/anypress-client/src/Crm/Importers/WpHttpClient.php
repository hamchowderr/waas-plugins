<?php

namespace AnyPressClient\Crm\Importers;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 HTTP client on the WordPress HTTP API, handed to the official Brevo and MailerLite SDKs so their
 * requests go through WordPress (its proxy settings, its certificate bundle, the pre_http_request filter) rather
 * than a second HTTP library.
 */
final class WpHttpClient implements ClientInterface
{
    private Psr17Factory $factory;

    public function __construct(?Psr17Factory $factory = null)
    {
        $this->factory = $factory ?? new Psr17Factory();
    }

    public function factory(): Psr17Factory
    {
        return $this->factory;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $body = (string) $request->getBody();
        $result = wp_safe_remote_request((string) $request->getUri(), [
            'method' => $request->getMethod(),
            'headers' => $headers,
            // The MailerLite SDK writes "[]" as the body of every GET: a GET carries no body.
            'body' => $body === '' || in_array($request->getMethod(), ['GET', 'HEAD'], true) ? null : $body,
            'timeout' => 30,
            'redirection' => 0,
        ]);
        if (is_wp_error($result)) {
            throw new WpHttpException($request, esc_html($result->get_error_message()));
        }

        $response = $this->factory->createResponse((int) wp_remote_retrieve_response_code($result), (string) wp_remote_retrieve_response_message($result));
        foreach (wp_remote_retrieve_headers($result) as $name => $value) {
            $response = $response->withHeader((string) $name, $value);
        }

        return $response->withBody($this->factory->createStream((string) wp_remote_retrieve_body($result)));
    }
}

<?php

namespace AnyPressClient\Crm\Importers;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** The request never got an answer (DNS, connection, timeout). */
final class WpHttpException extends \RuntimeException implements NetworkExceptionInterface
{
    private RequestInterface $request;

    /** The exception for a request WordPress could not send, with its (already escaped) reason. */
    public static function failed(RequestInterface $request, string $message): self
    {
        $error = new self($message);
        $error->request = $request;

        return $error;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}

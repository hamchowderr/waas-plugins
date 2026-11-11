<?php

namespace AnyPressClient\Crm\Importers;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** The request never got an answer (DNS, connection, timeout). */
final class WpHttpException extends \RuntimeException implements NetworkExceptionInterface
{
    private RequestInterface $request;

    public function __construct(RequestInterface $request, string $message)
    {
        parent::__construct($message);
        $this->request = $request;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}

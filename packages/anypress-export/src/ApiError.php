<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

/** AnyPress answered with an error. The message is for the person; `status` is the HTTP status (0 when it never answered). */
class ApiError extends \RuntimeException
{
    /** @var int */
    public $status;

    public function __construct(string $message, int $status = 0)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

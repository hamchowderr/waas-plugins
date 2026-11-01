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

    /** True when AnyPress was briefly out of reach (no answer, or a gateway error while it restarts), so asking again later makes sense. */
    public function passing(): bool
    {
        return in_array($this->status, [0, 429, 502, 503, 504], true);
    }
}

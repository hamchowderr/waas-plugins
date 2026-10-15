<?php

namespace WaaSHost\Features;

use Exception;
use RuntimeException;

/** A website change that was refused. The message can be shown to the customer as it is. */
class WebsiteSettingsError extends RuntimeException
{
    /** The customer's input was not valid; nothing was sent to the API. */
    public const INVALID_INPUT = 'invalid_input';
    /** The API refused the change (it said why) or could not be reached. */
    public const API = 'api';

    private string $kind;
    /** HTTP status for the account API: 400 for a refusal the customer can fix, 502 when the API failed. */
    private int $status;

    private function __construct(string $message, string $kind, int $status, ?Exception $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->kind = $kind;
        $this->status = $status;
    }

    public static function invalid_input(string $message): self
    {
        return new self($message, self::INVALID_INPUT, 400);
    }

    public static function from_api(Exception $e): self
    {
        $message = WebsiteSettings::api_error_message($e);
        $refused = $message !== WebsiteSettings::GENERIC_ERROR_MESSAGE;

        return new self($message, self::API, $refused ? 400 : 502, $e);
    }

    public function is_invalid_input(): bool
    {
        return $this->kind === self::INVALID_INPUT;
    }

    public function get_status(): int
    {
        return $this->status;
    }
}

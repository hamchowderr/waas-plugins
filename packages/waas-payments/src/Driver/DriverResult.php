<?php

namespace WaaSPayments\Driver;

/**
 * What a driver call returned. Messages are safe to show the merchant; they never
 * contain card data.
 */
class DriverResult
{
    /** @var bool */
    public $success;

    /** @var string The gateway's ID for the charge, refund or void. */
    public $transactionId;

    /** @var string */
    public $message;

    /** @var string The gateway's own result or error code. */
    public $code;

    /** @var array Extra fields: card_last4, card_brand, auth_code, avs_result, cvv_result, action, ... */
    public $data;

    private function __construct(bool $success, string $transactionId, string $message, string $code, array $data)
    {
        $this->success = $success;
        $this->transactionId = $transactionId;
        $this->message = $message;
        $this->code = $code;
        $this->data = $data;
    }

    public static function success(string $transactionId, string $message = '', array $data = [], string $code = ''): self
    {
        return new self(true, $transactionId, $message, $code, $data);
    }

    public static function failure(string $message, string $code = '', array $data = [], string $transactionId = ''): self
    {
        return new self(false, $transactionId, $message, $code, $data);
    }
}

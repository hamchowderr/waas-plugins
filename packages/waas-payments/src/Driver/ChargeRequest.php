<?php

namespace WaaSPayments\Driver;

class ChargeRequest
{
    /** @var int Amount in cents. */
    public $amount;

    /** @var string Token type the gateway gave the browser (Authorize.Net: COMMON.ACCEPT.INAPP.PAYMENT). */
    public $tokenDescriptor;

    /** @var string The single-use token itself. */
    public $tokenValue;

    /** @var string Order number shown in the gateway's dashboard. */
    public $invoiceNumber;

    /** @var string */
    public $description;

    /** @var string */
    public $customerEmail;

    /** @var string */
    public $customerId;

    /** @var string */
    public $customerIp;

    public function __construct(int $amount, string $tokenDescriptor, string $tokenValue, array $details = [])
    {
        $this->amount = $amount;
        $this->tokenDescriptor = $tokenDescriptor;
        $this->tokenValue = $tokenValue;
        $this->invoiceNumber = (string) ($details['invoice_number'] ?? '');
        $this->description = (string) ($details['description'] ?? '');
        $this->customerEmail = (string) ($details['customer_email'] ?? '');
        $this->customerId = (string) ($details['customer_id'] ?? '');
        $this->customerIp = (string) ($details['customer_ip'] ?? '');
    }
}

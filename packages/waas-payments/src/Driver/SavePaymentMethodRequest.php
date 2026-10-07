<?php

namespace WaaSPayments\Driver;

/** Save a card token at the gateway without charging it. */
class SavePaymentMethodRequest
{
    /** @var string */
    public $tokenDescriptor;

    /** @var string */
    public $tokenValue;

    /** @var string Existing gateway customer to add the card to; empty creates one. */
    public $customerRef;

    /** @var string */
    public $customerId;

    /** @var string */
    public $customerEmail;

    /**
     * @var array Billing address: first_name, last_name, address, city, state, zip, country.
     * With a complete address the gateway checks the card with a $0 authorization.
     */
    public $billing;

    public function __construct(string $tokenDescriptor, string $tokenValue, string $customerRef = '', array $details = [])
    {
        $this->tokenDescriptor = $tokenDescriptor;
        $this->tokenValue = $tokenValue;
        $this->customerRef = $customerRef;
        $this->customerId = (string) ($details['customer_id'] ?? '');
        $this->customerEmail = (string) ($details['customer_email'] ?? '');
        $this->billing = (array) ($details['billing'] ?? []);
    }
}

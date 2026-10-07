<?php

namespace WaaSPayments\Driver;

/** A charge of a saved card, made without the customer (a subscription renewal). */
class SavedChargeRequest
{
    /** @var int Amount in cents. */
    public $amount;

    /** @var string The gateway's customer (Authorize.Net: customer profile ID). */
    public $customerRef;

    /** @var string The saved card (Authorize.Net: payment profile ID). */
    public $methodRef;

    /** @var string */
    public $invoiceNumber;

    /** @var string */
    public $description;

    /** @var string Card network's ID for the charge that saved the card, sent with merchant-initiated charges. */
    public $originalNetworkTransactionId;

    public function __construct(int $amount, string $customerRef, string $methodRef, array $details = [])
    {
        $this->amount = $amount;
        $this->customerRef = $customerRef;
        $this->methodRef = $methodRef;
        $this->invoiceNumber = (string) ($details['invoice_number'] ?? '');
        $this->description = (string) ($details['description'] ?? '');
        $this->originalNetworkTransactionId = (string) ($details['original_network_transaction_id'] ?? '');
    }
}

<?php

namespace WaaSPayments\Driver;

class RefundRequest
{
    /** @var int Amount to refund, in cents. */
    public $amount;

    /** @var int What the original charge captured, in cents. */
    public $chargedAmount;

    /** @var string The gateway's ID for the original charge. */
    public $chargeId;

    /** @var string Last four digits of the card, which some gateways require on a refund. */
    public $cardLast4;

    public function __construct(int $amount, int $chargedAmount, string $chargeId, string $cardLast4 = '')
    {
        $this->amount = $amount;
        $this->chargedAmount = $chargedAmount;
        $this->chargeId = $chargeId;
        $this->cardLast4 = $cardLast4;
    }
}

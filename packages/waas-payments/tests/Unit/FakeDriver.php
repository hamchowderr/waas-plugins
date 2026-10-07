<?php

namespace Tests\Unit;

use WaaSPayments\Driver\ChargeRequest;
use WaaSPayments\Driver\DriverResult;
use WaaSPayments\Driver\GatewayDriver;
use WaaSPayments\Driver\RefundRequest;

/** Records what the gateway asked for and answers with preset results. */
class FakeDriver implements GatewayDriver
{
    public array $charges = [];
    public array $refunds = [];
    public DriverResult $chargeResult;
    public DriverResult $refundResult;

    public function __construct()
    {
        $this->chargeResult = DriverResult::success('2149186954', 'This transaction has been approved.', [
            'card_last4' => '1111',
            'card_brand' => 'Visa',
            'auth_code'  => 'HW617E',
            'avs_result' => 'Y',
            'cvv_result' => 'P',
        ], '1');
        $this->refundResult = DriverResult::success('2149186960');
    }

    public function charge(ChargeRequest $request): DriverResult
    {
        $this->charges[] = $request;
        return $this->chargeResult;
    }

    public function refund(RefundRequest $request): DriverResult
    {
        $this->refunds[] = $request;
        return $this->refundResult;
    }

    public function verifyCredentials(): DriverResult
    {
        return DriverResult::success('', '', ['public_client_key' => 'client-key']);
    }
}

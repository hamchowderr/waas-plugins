<?php

namespace Tests\Unit;

use WaaSPayments\Driver\ChargeRequest;
use WaaSPayments\Driver\DriverResult;
use WaaSPayments\Driver\RecurringGatewayDriver;
use WaaSPayments\Driver\RefundRequest;
use WaaSPayments\Driver\SavedChargeRequest;
use WaaSPayments\Driver\SavePaymentMethodRequest;

/** Records what the gateway asked for and answers with preset results. */
class FakeDriver implements RecurringGatewayDriver
{
    public array $charges = [];
    public array $refunds = [];
    public array $savedCharges = [];
    public array $savedMethods = [];
    public array $deleted = [];
    public array $statusChecks = [];
    public array $lookups = [];
    public DriverResult $chargeResult;
    public DriverResult $refundResult;
    public DriverResult $savedChargeResult;
    public DriverResult $saveResult;
    public DriverResult $statusResult;
    public DriverResult $findResult;

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
        $this->savedChargeResult = DriverResult::success('80060612550', 'This transaction has been approved.', [
            'card_last4' => '1111',
            'card_brand' => 'Visa',
            'auth_code'  => 'ABC123',
        ], '1');
        $this->saveResult = DriverResult::success('', '', [
            'customer_ref' => '528040951',
            'method_ref'   => '540022574',
            'card_last4'   => '0012',
            'card_brand'   => 'Discover',
        ]);
        $this->statusResult = DriverResult::success('80060612550', 'capturedPendingSettlement', [
            'state'      => self::STATE_PAID,
            'card_last4' => '1111',
            'card_brand' => 'Visa',
        ]);
        $this->findResult = DriverResult::failure('Authorize.Net has no charge for this invoice.', 'not_found');
    }

    /** A charge that also saved the card (what charge() returns with save_payment_method). */
    public function chargeSavesCard(string $customerRef = '528040951', string $methodRef = '540022572'): void
    {
        $this->chargeResult->data += [
            'customer_ref'     => $customerRef,
            'method_ref'       => $methodRef,
            'network_trans_id' => 'NETWORK-ID',
        ];
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

    public function chargeSaved(SavedChargeRequest $request): DriverResult
    {
        $this->savedCharges[] = $request;
        return $this->savedChargeResult;
    }

    public function savePaymentMethod(SavePaymentMethodRequest $request): DriverResult
    {
        $this->savedMethods[] = $request;
        return $this->saveResult;
    }

    public function saveFromCharge(string $transactionId, string $customerRef): DriverResult
    {
        return $this->saveResult;
    }

    public function deleteSavedPaymentMethod(string $customerRef, string $methodRef): DriverResult
    {
        $this->deleted[] = [$customerRef, $methodRef];
        return DriverResult::success('');
    }

    public function chargeStatus(string $transactionId): DriverResult
    {
        $this->statusChecks[] = $transactionId;
        return $this->statusResult;
    }

    public function findSavedCharge(string $customerRef, string $invoiceNumber, int $amount): DriverResult
    {
        $this->lookups[] = [$customerRef, $invoiceNumber, $amount];
        return $this->findResult;
    }
}

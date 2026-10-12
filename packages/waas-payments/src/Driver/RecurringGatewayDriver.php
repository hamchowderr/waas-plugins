<?php

namespace WaaSPayments\Driver;

/**
 * A driver that can save a card at the gateway and charge it later without the customer,
 * for subscription renewals. The saved card is identified by two opaque gateway references
 * (Authorize.Net CIM: customer profile ID and payment profile ID); card numbers never reach
 * this site.
 */
interface RecurringGatewayDriver extends GatewayDriver
{
    // chargeStatus() / findSavedCharge() states.
    const STATE_PAID = 'paid';
    const STATE_PENDING = 'pending';
    const STATE_FAILED = 'failed';

    /** Charge a saved card. */
    public function chargeSaved(SavedChargeRequest $request): DriverResult;

    /**
     * Save a card token without charging it (card update, or a subscription with nothing
     * to pay today). data: customer_ref, method_ref, card_last4, card_brand.
     */
    public function savePaymentMethod(SavePaymentMethodRequest $request): DriverResult;

    /**
     * Save the card of an approved charge, made without asking the gateway to save it.
     * data: customer_ref, method_ref.
     */
    public function saveFromCharge(string $transactionId, string $customerRef): DriverResult;

    public function deleteSavedPaymentMethod(string $customerRef, string $methodRef): DriverResult;

    /**
     * Where a charge (or refund) stands now. data: state (STATE_*), transaction_status,
     * transaction_type, amount, auth_amount, card_last4, card_brand, auth_code, avs_result, cvv_result,
     * ref_trans_id, invoice_number, customer_id, customer_email, customer_ref, network_trans_id.
     */
    public function chargeStatus(string $transactionId): DriverResult;

    /**
     * Finds a charge of a saved card by its invoice number and amount, for a renewal whose
     * request got no answer. Failure with code 'not_found' when the gateway has no such charge.
     */
    public function findSavedCharge(string $customerRef, string $invoiceNumber, int $amount): DriverResult;
}

<?php

namespace Tests\Unit;

use Brain\Monkey\Functions;
use WaaSPayments\Driver\AuthorizeNet\AuthorizeNetClient;
use WaaSPayments\Driver\AuthorizeNet\AuthorizeNetDriver;
use WaaSPayments\Driver\ChargeRequest;
use WaaSPayments\Driver\RecurringGatewayDriver;
use WaaSPayments\Driver\SavedChargeRequest;
use WaaSPayments\Driver\SavePaymentMethodRequest;

/** The driver's Customer Information Manager (CIM) calls, against recorded sandbox responses. */
class AuthorizeNetSavedCardsTest extends TestCase
{
    private array $sent = [];

    private function driver(): AuthorizeNetDriver
    {
        return new AuthorizeNetDriver(new AuthorizeNetClient('login-id', 'txn-key', true));
    }

    /** Answers wp_remote_post with fixtures (or WP_Errors) in order and records the request bodies. */
    private function respond(array $responses): void
    {
        $responses = array_map(fn ($r) => $r instanceof \WP_Error ? $r : self::fixture($r), $responses);
        Functions\expect('wp_remote_post')
            ->times(count($responses))
            ->andReturnUsing(function ($url, $args) use (&$responses) {
                $this->sent[] = json_decode($args['body'], true);
                return array_shift($responses);
            });
    }

    private function savingCharge(string $customerRef = ''): ChargeRequest
    {
        return new ChargeRequest(2500, 'COMMON.ACCEPT.INAPP.PAYMENT', 'nonce-value', [
            'invoice_number'      => '1001',
            'customer_email'      => 'buyer@example.com',
            'customer_id'         => '42',
            'save_payment_method' => true,
            'customer_ref'        => $customerRef,
        ]);
    }

    public function test_a_new_customers_card_is_saved_by_the_charge_itself(): void
    {
        $this->respond(['charge-approved-profile-created']);

        $result = $this->driver()->charge($this->savingCharge());

        $transaction = $this->sent[0]['createTransactionRequest']['transactionRequest'];
        $this->assertSame(['createProfile' => true], $transaction['profile']);
        $this->assertSame(['isFirstRecurringPayment' => true], $transaction['processingOptions']);
        $this->assertSame(
            ['transactionType', 'amount', 'payment', 'profile', 'order', 'customer', 'processingOptions'],
            array_keys($transaction),
            'schema order'
        );

        $this->assertTrue($result->success);
        $this->assertSame('528040951', $result->data['customer_ref']);
        $this->assertSame('540022572', $result->data['method_ref']);
        $this->assertNotSame('', $result->data['network_trans_id']);
        $this->assertArrayNotHasKey('save_error', $result->data);
    }

    public function test_a_returning_customer_gets_the_card_added_to_their_existing_profile(): void
    {
        // createProfile collides with the customer's profile (E00039): the charge still goes
        // through, and the card is saved from the transaction into the existing profile.
        $this->respond(['charge-approved-profile-duplicate', 'profile-from-transaction']);

        $result = $this->driver()->charge($this->savingCharge());

        $this->assertSame([
            'transId'           => '80060612549',
            'customerProfileId' => '528040951',
        ], array_diff_key($this->sent[1]['createCustomerProfileFromTransactionRequest'], ['merchantAuthentication' => 1]));
        $this->assertTrue($result->success);
        $this->assertSame('528040951', $result->data['customer_ref']);
        $this->assertSame('540022573', $result->data['method_ref']);
    }

    public function test_a_known_profile_skips_create_profile_and_saves_from_the_transaction(): void
    {
        $this->respond(['charge-approved', 'profile-from-transaction']);

        $result = $this->driver()->charge($this->savingCharge('528040951'));

        $this->assertArrayNotHasKey('profile', $this->sent[0]['createTransactionRequest']['transactionRequest']);
        $this->assertSame('528040951', $this->sent[1]['createCustomerProfileFromTransactionRequest']['customerProfileId']);
        $this->assertSame('540022573', $result->data['method_ref']);
    }

    public function test_a_failure_to_save_still_reports_the_successful_charge(): void
    {
        $this->respond(['charge-approved', 'record-not-found']);

        $result = $this->driver()->charge($this->savingCharge('528040951'));

        $this->assertTrue($result->success, 'the customer was charged');
        $this->assertSame('The record cannot be found.', $result->data['save_error']);
        $this->assertArrayNotHasKey('method_ref', $result->data);
    }

    public function test_a_declined_saving_charge_saves_nothing(): void
    {
        $this->respond(['charge-declined']);

        $result = $this->driver()->charge($this->savingCharge());

        $this->assertFalse($result->success);
        $this->assertCount(1, $this->sent);
    }

    public function test_a_plain_charge_is_unchanged(): void
    {
        $this->respond(['charge-approved']);

        $this->driver()->charge(new ChargeRequest(2500, 'COMMON.ACCEPT.INAPP.PAYMENT', 'nonce-value'));

        $transaction = $this->sent[0]['createTransactionRequest']['transactionRequest'];
        $this->assertArrayNotHasKey('profile', $transaction);
        $this->assertArrayNotHasKey('processingOptions', $transaction);
    }

    public function test_a_renewal_charges_the_payment_profile_as_a_merchant_initiated_charge(): void
    {
        $this->respond(['saved-charge-approved']);

        $result = $this->driver()->chargeSaved(new SavedChargeRequest(5886, '528040951', '540022572', [
            'invoice_number'                  => '1003',
            'description'                     => 'Peptide Test Store #1003',
            'original_network_transaction_id' => 'NETWORK-ID',
        ]));

        $this->assertSame([
            'transactionType'           => 'authCaptureTransaction',
            'amount'                    => '58.86',
            'profile'                   => ['customerProfileId' => '528040951', 'paymentProfile' => ['paymentProfileId' => '540022572']],
            'order'                     => ['invoiceNumber' => '1003', 'description' => 'Peptide Test Store #1003'],
            'processingOptions'         => ['isSubsequentAuth' => true, 'isStoredCredentials' => true],
            'subsequentAuthInformation' => ['originalNetworkTransId' => 'NETWORK-ID'],
        ], $this->sent[0]['createTransactionRequest']['transactionRequest']);

        $this->assertTrue($result->success);
        $this->assertSame('80060612550', $result->transactionId);
        $this->assertSame('1111', $result->data['card_last4']);
    }

    public function test_a_declined_renewal_carries_the_bank_message(): void
    {
        $this->respond(['saved-charge-declined']);

        $result = $this->driver()->chargeSaved(new SavedChargeRequest(7814, '528040951', '540022574'));

        $this->assertFalse($result->success);
        $this->assertSame('2', $result->code);
        $this->assertSame('This transaction has been declined.', $result->message);
        $this->assertArrayNotHasKey('subsequentAuthInformation', $this->sent[0]['createTransactionRequest']['transactionRequest']);
    }

    public function test_an_unanswered_renewal_is_an_http_error(): void
    {
        $this->respond([new \WP_Error('http_request_failed', 'cURL error 28: timed out')]);

        $result = $this->driver()->chargeSaved(new SavedChargeRequest(100, '1', '2'));

        $this->assertFalse($result->success);
        $this->assertSame('http_error', $result->code);
    }

    public function test_saving_a_card_with_a_full_address_validates_it_live(): void
    {
        $this->respond(['payment-profile-created']);

        $result = $this->driver()->savePaymentMethod(new SavePaymentMethodRequest('COMMON.ACCEPT.INAPP.PAYMENT', 'nonce', '528040951', [
            'billing' => ['first_name' => 'Test', 'last_name' => 'Buyer', 'address' => '1 Main St', 'city' => 'Bellevue', 'state' => 'WA', 'zip' => '46282', 'country' => 'US'],
        ]));

        $request = $this->sent[0]['createCustomerPaymentProfileRequest'];
        $this->assertSame('liveMode', $request['validationMode']);
        $this->assertSame(['billTo', 'payment'], array_keys($request['paymentProfile']));
        $this->assertSame(['merchantAuthentication', 'customerProfileId', 'paymentProfile', 'validationMode'], array_keys($request));

        $this->assertTrue($result->success);
        $this->assertSame('540022574', $result->data['method_ref']);
        $this->assertSame('0012', $result->data['card_last4']);
        $this->assertSame('Discover', $result->data['card_brand']);
    }

    public function test_saving_a_card_without_a_full_address_validates_its_format_only(): void
    {
        $this->respond(['payment-profile-created']);

        $this->driver()->savePaymentMethod(new SavePaymentMethodRequest('COMMON.ACCEPT.INAPP.PAYMENT', 'nonce', '528040951', [
            'billing' => ['first_name' => 'Test', 'zip' => '98004'],
        ]));

        $request = $this->sent[0]['createCustomerPaymentProfileRequest'];
        $this->assertSame('testMode', $request['validationMode']);
        $this->assertSame(['firstName' => 'Test', 'zip' => '98004'], $request['paymentProfile']['billTo']);
    }

    public function test_saving_a_card_that_is_already_saved_returns_the_existing_payment_profile(): void
    {
        $this->respond(['payment-profile-duplicate']);

        $result = $this->driver()->savePaymentMethod(new SavePaymentMethodRequest('COMMON.ACCEPT.INAPP.PAYMENT', 'nonce', '528040951'));

        $this->assertTrue($result->success);
        $this->assertSame('540022574', $result->data['method_ref']);
    }

    public function test_a_card_that_fails_validation_is_not_saved(): void
    {
        $this->respond(['payment-profile-validation-failed']);

        $result = $this->driver()->savePaymentMethod(new SavePaymentMethodRequest('COMMON.ACCEPT.INAPP.PAYMENT', 'nonce', '528040951'));

        $this->assertFalse($result->success);
        $this->assertSame('There is one or more missing or invalid required fields.', $result->message);
    }

    public function test_saving_without_a_profile_creates_the_customer(): void
    {
        $this->respond(['customer-profile-created']);

        $result = $this->driver()->savePaymentMethod(new SavePaymentMethodRequest('COMMON.ACCEPT.INAPP.PAYMENT', 'nonce', '', [
            'customer_id'    => '42',
            'customer_email' => 'buyer@example.com',
        ]));

        $request = $this->sent[0]['createCustomerProfileRequest'];
        $this->assertSame(['merchantCustomerId', 'email', 'paymentProfiles'], array_keys($request['profile']));
        $this->assertTrue($result->success);
        $this->assertSame('528040952', $result->data['customer_ref']);
        $this->assertSame('1111', $result->data['card_last4']);
    }

    public function test_saving_for_a_customer_who_already_has_a_profile_adds_the_card_to_it(): void
    {
        $this->respond(['customer-profile-duplicate', 'payment-profile-created']);

        $result = $this->driver()->savePaymentMethod(new SavePaymentMethodRequest('COMMON.ACCEPT.INAPP.PAYMENT', 'nonce', '', ['customer_id' => '42']));

        $this->assertSame('528040952', $this->sent[1]['createCustomerPaymentProfileRequest']['customerProfileId']);
        $this->assertTrue($result->success);
        $this->assertSame('528040952', $result->data['customer_ref']);
    }

    public function test_deleting_a_payment_profile_that_is_already_gone_succeeds(): void
    {
        $this->respond(['payment-profile-deleted', 'record-not-found']);

        $this->assertTrue($this->driver()->deleteSavedPaymentMethod('528040951', '540022574')->success);
        $this->assertTrue($this->driver()->deleteSavedPaymentMethod('528040951', '540022574')->success);
    }

    public function test_charge_status_reads_a_captured_charge_as_paid(): void
    {
        $this->respond(['transaction-details-captured']);

        $result = $this->driver()->chargeStatus('80060612550');

        $this->assertTrue($result->success);
        $this->assertSame(RecurringGatewayDriver::STATE_PAID, $result->data['state']);
        $this->assertSame(5886, $result->data['amount']);
        $this->assertSame('1111', $result->data['card_last4']);
    }

    public function test_charge_status_of_an_unknown_transaction_fails(): void
    {
        $this->respond(['record-not-found']);

        $result = $this->driver()->chargeStatus('1');

        $this->assertFalse($result->success);
        $this->assertSame('E00040', $result->code);
    }

    public function test_find_saved_charge_matches_invoice_number_and_amount(): void
    {
        $this->respond(['transaction-list-for-customer', 'transaction-list-for-customer', 'transaction-list-for-customer']);

        $paid = $this->driver()->findSavedCharge('528040951', '1003', 5886);
        $this->assertTrue($paid->success);
        $this->assertSame('80060612550', $paid->transactionId);
        $this->assertSame(RecurringGatewayDriver::STATE_PAID, $paid->data['state']);

        $declined = $this->driver()->findSavedCharge('528040951', '1004', 7814);
        $this->assertSame(RecurringGatewayDriver::STATE_FAILED, $declined->data['state']);

        $otherAmount = $this->driver()->findSavedCharge('528040951', '1003', 5887);
        $this->assertFalse($otherAmount->success);
        $this->assertSame('not_found', $otherAmount->code);
    }

    public function test_find_saved_charge_with_no_transactions_is_not_found(): void
    {
        $this->respond(['transaction-list-empty']);

        $result = $this->driver()->findSavedCharge('528040952', '1003', 5886);

        $this->assertFalse($result->success);
        $this->assertSame('not_found', $result->code);
    }
}

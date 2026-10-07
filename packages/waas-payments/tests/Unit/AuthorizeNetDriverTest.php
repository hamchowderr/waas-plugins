<?php

namespace Tests\Unit;

use Brain\Monkey\Functions;
use WaaSPayments\Driver\AuthorizeNet\AuthorizeNetClient;
use WaaSPayments\Driver\AuthorizeNet\AuthorizeNetDriver;
use WaaSPayments\Driver\ChargeRequest;
use WaaSPayments\Driver\RefundRequest;

class AuthorizeNetDriverTest extends TestCase
{
    private function driver(bool $sandbox = true): AuthorizeNetDriver
    {
        return new AuthorizeNetDriver(new AuthorizeNetClient('login-id', 'txn-key', $sandbox));
    }

    private function chargeRequest(): ChargeRequest
    {
        return new ChargeRequest(12345, 'COMMON.ACCEPT.INAPP.PAYMENT', 'nonce-value', [
            'invoice_number' => 'INV-1001',
            'description'    => 'Peptide Test Store #INV-1001',
            'customer_email' => 'buyer@example.com',
            'customer_id'    => '42',
            'customer_ip'    => '203.0.113.9',
        ]);
    }

    /** Captures the JSON body of each wp_remote_post call and answers with fixtures in order. */
    private function expectRequests(array $fixtures, ?string $url = null, array &$sent = []): void
    {
        $responses = array_map(fn ($name) => self::fixture($name), $fixtures);
        Functions\expect('wp_remote_post')
            ->times(count($fixtures))
            ->andReturnUsing(function ($postUrl, $args) use (&$responses, &$sent, $url) {
                if ($url !== null) {
                    $this->assertSame($url, $postUrl);
                }
                $this->assertSame('application/json', $args['headers']['Content-Type']);
                $sent[] = json_decode($args['body'], true);
                return array_shift($responses);
            });
    }

    public function test_charge_sends_an_auth_capture_with_the_token_in_schema_order(): void
    {
        $sent = [];
        $this->expectRequests(['charge-approved'], AuthorizeNetClient::SANDBOX_URL, $sent);

        $this->driver()->charge($this->chargeRequest());

        $request = $sent[0]['createTransactionRequest'];
        $this->assertSame(['name' => 'login-id', 'transactionKey' => 'txn-key'], $request['merchantAuthentication']);
        $this->assertSame([
            'transactionType' => 'authCaptureTransaction',
            'amount'          => '123.45',
            'payment'         => ['opaqueData' => ['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'nonce-value']],
            'order'           => ['invoiceNumber' => 'INV-1001', 'description' => 'Peptide Test Store #INV-1001'],
            'customer'        => ['id' => '42', 'email' => 'buyer@example.com'],
            'customerIP'      => '203.0.113.9',
        ], $request['transactionRequest']);
        // The JSON API is order-sensitive (it is converted to XML against AnetApiSchema.xsd).
        $this->assertSame(['merchantAuthentication', 'transactionRequest'], array_keys($request));
    }

    public function test_charge_omits_empty_optional_fields(): void
    {
        $sent = [];
        $this->expectRequests(['charge-approved'], null, $sent);

        $this->driver()->charge(new ChargeRequest(500, 'COMMON.ACCEPT.INAPP.PAYMENT', 'nonce'));

        $this->assertSame(['transactionType', 'amount', 'payment'], array_keys($sent[0]['createTransactionRequest']['transactionRequest']));
    }

    public function test_charge_trims_the_invoice_number_to_twenty_characters(): void
    {
        $sent = [];
        $this->expectRequests(['charge-approved'], null, $sent);

        $this->driver()->charge(new ChargeRequest(500, 'COMMON.ACCEPT.INAPP.PAYMENT', 'nonce', ['invoice_number' => str_repeat('9', 30)]));

        $this->assertSame(str_repeat('9', 20), $sent[0]['createTransactionRequest']['transactionRequest']['order']['invoiceNumber']);
    }

    public function test_live_mode_posts_to_production(): void
    {
        $this->expectRequests(['charge-approved'], AuthorizeNetClient::PRODUCTION_URL);

        $this->assertTrue($this->driver(false)->charge($this->chargeRequest())->success);
    }

    public function test_an_approved_charge_returns_the_transaction_and_card_summary(): void
    {
        // charge-approved.json starts with a byte order mark, as Authorize.Net's responses do.
        $this->expectRequests(['charge-approved']);

        $result = $this->driver()->charge($this->chargeRequest());

        $this->assertTrue($result->success);
        $this->assertSame('80060594473', $result->transactionId);
        $this->assertSame('1111', $result->data['card_last4']);
        $this->assertSame('Visa', $result->data['card_brand']);
        $this->assertSame('JMSV1Q', $result->data['auth_code']);
        $this->assertSame('Y', $result->data['avs_result']);
        $this->assertSame('P', $result->data['cvv_result']);
    }

    public function test_a_declined_charge_returns_the_gateway_reason(): void
    {
        $this->expectRequests(['charge-declined']);

        $result = $this->driver()->charge($this->chargeRequest());

        $this->assertFalse($result->success);
        $this->assertSame('2', $result->code);
        $this->assertSame('This transaction has been declined.', $result->message);
    }

    public function test_a_charge_held_for_review_is_not_treated_as_paid(): void
    {
        $this->expectRequests(['charge-held-for-review']);

        $result = $this->driver()->charge($this->chargeRequest());

        $this->assertFalse($result->success);
        $this->assertSame('held_for_review', $result->code);
        $this->assertSame('2149186956', $result->transactionId);
    }

    public function test_rejected_credentials_surface_the_api_message(): void
    {
        $this->expectRequests(['auth-failed']);

        $result = $this->driver()->charge($this->chargeRequest());

        $this->assertFalse($result->success);
        $this->assertSame('E00007', $result->code);
        $this->assertStringContainsString('User authentication failed', $result->message);
    }

    public function test_a_network_error_is_a_failure_not_an_exception(): void
    {
        Functions\expect('wp_remote_post')->once()->andReturn(new \WP_Error('http_request_failed', 'cURL error 28: timed out'));

        $result = $this->driver()->charge($this->chargeRequest());

        $this->assertFalse($result->success);
        $this->assertSame('http_error', $result->code);
        $this->assertStringContainsString('timed out', $result->message);
    }

    public function test_an_unreadable_body_is_a_failure(): void
    {
        Functions\expect('wp_remote_post')->once()->andReturn(['response' => ['code' => 502], 'body' => '<html>Bad gateway</html>']);

        $result = $this->driver()->charge($this->chargeRequest());

        $this->assertFalse($result->success);
        $this->assertSame('http_error', $result->code);
    }

    public function test_refund_sends_the_last_four_digits_and_original_transaction(): void
    {
        $sent = [];
        $this->expectRequests(['refund-approved'], null, $sent);

        $result = $this->driver()->refund(new RefundRequest(2500, 12345, '2149186954', '1111'));

        $this->assertTrue($result->success);
        $this->assertSame('2149186960', $result->transactionId);
        $this->assertSame([
            'transactionType' => 'refundTransaction',
            'amount'          => '25.00',
            'payment'         => ['creditCard' => ['cardNumber' => '1111', 'expirationDate' => 'XXXX']],
            'refTransId'      => '2149186954',
        ], $sent[0]['createTransactionRequest']['transactionRequest']);
    }

    public function test_a_full_refund_of_an_unsettled_charge_voids_it(): void
    {
        $sent = [];
        $this->expectRequests(['refund-not-settled', 'void-approved'], null, $sent);

        $result = $this->driver()->refund(new RefundRequest(12345, 12345, '2149186954', '1111'));

        $this->assertTrue($result->success);
        $this->assertSame('void', $result->data['action']);
        $this->assertSame(
            ['transactionType' => 'voidTransaction', 'refTransId' => '2149186954'],
            $sent[1]['createTransactionRequest']['transactionRequest']
        );
    }

    public function test_a_partial_refund_of_an_unsettled_charge_is_refused_without_voiding(): void
    {
        $this->expectRequests(['refund-not-settled']);

        $result = $this->driver()->refund(new RefundRequest(2500, 12345, '2149186954', '1111'));

        $this->assertFalse($result->success);
        $this->assertSame('54', $result->code);
        $this->assertStringContainsString('has not settled yet', $result->message);
    }

    public function test_refund_without_a_charge_id_does_not_call_the_gateway(): void
    {
        Functions\expect('wp_remote_post')->never();

        $this->assertFalse($this->driver()->refund(new RefundRequest(100, 100, ''))->success);
    }

    public function test_verify_credentials_returns_the_public_client_key(): void
    {
        $sent = [];
        $this->expectRequests(['merchant-details'], null, $sent);

        $result = $this->driver()->verifyCredentials();

        $this->assertTrue($result->success);
        $this->assertSame('9aptUW2DxG7rfEZ8VBpR4Hu2B8n7Bq6Q8cUbT4CJ6jXmbzWvZ5d6WrP8a3Vgkm4U', $result->data['public_client_key']);
        $this->assertSame(['getMerchantDetailsRequest'], array_keys($sent[0]));
    }

    public function test_verify_credentials_explains_a_missing_public_client_key(): void
    {
        $this->expectRequests(['merchant-details-no-client-key']);

        $result = $this->driver()->verifyCredentials();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Manage Public Client Key', $result->message);
    }

    public function test_verify_credentials_reports_bad_credentials(): void
    {
        $this->expectRequests(['auth-failed']);

        $result = $this->driver()->verifyCredentials();

        $this->assertFalse($result->success);
        $this->assertSame('E00007', $result->code);
    }
}

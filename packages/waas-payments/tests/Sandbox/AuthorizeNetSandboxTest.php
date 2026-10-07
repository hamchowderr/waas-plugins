<?php

namespace Tests\Sandbox;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSPayments\Driver\AuthorizeNet\AuthorizeNetClient;
use WaaSPayments\Driver\AuthorizeNet\AuthorizeNetDriver;
use WaaSPayments\Driver\ChargeRequest;
use WaaSPayments\Driver\RecurringGatewayDriver;
use WaaSPayments\Driver\RefundRequest;
use WaaSPayments\Driver\SavedChargeRequest;
use WaaSPayments\Driver\SavePaymentMethodRequest;

/**
 * Runs the driver against the real Authorize.Net sandbox. Skipped unless
 * AUTHORIZENET_SANDBOX_LOGIN_ID and AUTHORIZENET_SANDBOX_TRANSACTION_KEY (or the
 * AUTHNET_SANDBOX_* names) are set (a free sandbox account from developer.authorize.net),
 * and apitest.authorize.net must
 * be reachable. Set AUTHORIZENET_PRINT_RESPONSES=1 to print the raw bodies, to refresh
 * tests/fixtures/authorizenet.
 *
 * @group sandbox
 */
class AuthorizeNetSandboxTest extends TestCase
{
    private string $loginId;
    private string $transactionKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginId = (string) (getenv('AUTHORIZENET_SANDBOX_LOGIN_ID') ?: getenv('AUTHNET_SANDBOX_LOGIN_ID'));
        $this->transactionKey = (string) (getenv('AUTHORIZENET_SANDBOX_TRANSACTION_KEY') ?: getenv('AUTHNET_SANDBOX_TRANSACTION_KEY'));
        if ($this->loginId === '' || $this->transactionKey === '') {
            $this->markTestSkipped('Set AUTHORIZENET_SANDBOX_LOGIN_ID and AUTHORIZENET_SANDBOX_TRANSACTION_KEY to run against the sandbox.');
        }

        // A plain HTTP POST in place of WordPress's, through HTTPS_PROXY when one is set
        // (PHP streams ignore it otherwise).
        Functions\when('wp_remote_post')->alias(function ($url, $args) {
            $http = [
                'method'        => 'POST',
                'header'        => 'Content-Type: application/json',
                'content'       => $args['body'],
                'timeout'       => $args['timeout'],
                'ignore_errors' => true,
            ];
            $proxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy');
            if ($proxy) {
                $http['proxy'] = preg_replace('#^https?://#', 'tcp://', $proxy);
                $http['request_fulluri'] = false;
            }
            $ssl = [];
            if (getenv('SSL_CERT_FILE')) {
                $ssl['cafile'] = getenv('SSL_CERT_FILE');
            }
            $context = stream_context_create(['http' => $http, 'ssl' => $ssl]);
            $body = @file_get_contents($url, false, $context);
            if ($body === false) {
                return new \WP_Error('http_request_failed', 'Could not reach ' . $url);
            }
            if (getenv('AUTHORIZENET_PRINT_RESPONSES')) {
                fwrite(STDERR, "\n" . $body . "\n");
            }
            preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $status);
            return ['response' => ['code' => (int) ($status[1] ?? 0)], 'body' => $body];
        });
    }

    /**
     * A token for Authorize.Net's test Visa, made server-side with securePaymentContainerRequest.
     * In production the browser makes the token with Accept.js and the server never sees a card;
     * this is only for driving the sandbox from a test.
     */
    private function sandboxToken(string $zip = '98004', string $card = '4111111111111111'): array
    {
        $client = new AuthorizeNetClient($this->loginId, $this->transactionKey, true);
        $response = $client->send('securePaymentContainerRequest', [
            'data' => [
                'type'  => 'TOKEN',
                'id'    => uniqid('waas-test-', true),
                'token' => ['cardNumber' => $card, 'expirationDate' => '12' . (date('Y') + 2), 'cardCode' => '123', 'zip' => $zip],
            ],
        ]);
        $this->assertIsArray($response, 'apitest.authorize.net is not reachable');
        $this->assertSame('Ok', $response['messages']['resultCode'], json_encode($response['messages']));

        return $response['opaqueData'];
    }

    public function test_credentials_charge_and_void_against_the_sandbox(): void
    {
        $driver = new AuthorizeNetDriver(new AuthorizeNetClient($this->loginId, $this->transactionKey, true));

        $verified = $driver->verifyCredentials();
        $this->assertTrue($verified->success, $verified->message);

        $token = $this->sandboxToken();
        // A random amount keeps Authorize.Net's duplicate-transaction check out of the way.
        $amount = random_int(100, 99999);
        $charge = $driver->charge(new ChargeRequest($amount, $token['dataDescriptor'], $token['dataValue'], [
            'invoice_number' => 'TEST-' . substr((string) time(), -8),
            'description'    => 'waas-payments sandbox test',
        ]));
        $this->assertTrue($charge->success, $charge->message);
        $this->assertSame('1111', $charge->data['card_last4']);

        // Sandbox charges settle once a day, so a full refund right away becomes a void.
        $refund = $driver->refund(new RefundRequest($amount, $amount, $charge->transactionId, $charge->data['card_last4']));
        $this->assertTrue($refund->success, $refund->message);
        $this->assertSame('void', $refund->data['action']);
    }

    /** Authorize.Net's testing guide: billing ZIP 46282 makes the sandbox decline a card. */
    public function test_a_declined_card_is_a_failure_with_the_bank_message(): void
    {
        $driver = new AuthorizeNetDriver(new AuthorizeNetClient($this->loginId, $this->transactionKey, true));

        $token = $this->sandboxToken('46282');
        $charge = $driver->charge(new ChargeRequest(random_int(100, 99999), $token['dataDescriptor'], $token['dataValue']));

        $this->assertFalse($charge->success);
        $this->assertSame('2', $charge->code);
        $this->assertSame('This transaction has been declined.', $charge->message);
        $this->assertNotSame('', $charge->transactionId);
    }

    /**
     * The subscription flow with saved cards (CIM): the first payment saves the card, a renewal
     * charges it, a second card goes onto the same customer profile, a card whose billing ZIP
     * is 46282 is declined on renewal, and the lookups that reconcile an unanswered renewal.
     */
    public function test_saved_cards_charge_renewals_against_the_sandbox(): void
    {
        $driver = new AuthorizeNetDriver(new AuthorizeNetClient($this->loginId, $this->transactionKey, true));
        $customerId = 'waas-' . random_int(100000, 999999);

        $token = $this->sandboxToken();
        $firstAmount = random_int(100, 99999);
        $first = $driver->charge(new ChargeRequest($firstAmount, $token['dataDescriptor'], $token['dataValue'], [
            'invoice_number'      => 'S' . substr((string) time(), -8),
            'customer_id'         => $customerId,
            'customer_email'      => $customerId . '@example.com',
            'save_payment_method' => true,
        ]));
        $this->assertTrue($first->success, $first->message);
        $this->assertArrayNotHasKey('save_error', $first->data);
        $customerRef = $first->data['customer_ref'];
        $this->assertNotSame('', $first->data['method_ref']);
        $this->assertNotSame('', $first->data['network_trans_id']);

        $invoice = 'R' . substr((string) time(), -8);
        $amount = random_int(100, 99999);
        $renewal = $driver->chargeSaved(new SavedChargeRequest($amount, $customerRef, $first->data['method_ref'], [
            'invoice_number'                  => $invoice,
            'original_network_transaction_id' => $first->data['network_trans_id'],
        ]));
        $this->assertTrue($renewal->success, $renewal->message);
        $this->assertSame('1111', $renewal->data['card_last4']);

        $status = $driver->chargeStatus($renewal->transactionId);
        $this->assertSame(RecurringGatewayDriver::STATE_PAID, $status->data['state'], $status->message);
        $this->assertSame($amount, $status->data['amount']);

        // The same customer pays again with another card: it joins their existing profile.
        $token = $this->sandboxToken('98004', '5424000000000015');
        $secondAmount = random_int(100, 99999);
        $second = $driver->charge(new ChargeRequest($secondAmount, $token['dataDescriptor'], $token['dataValue'], [
            'customer_id'         => $customerId,
            'customer_email'      => $customerId . '@example.com',
            'save_payment_method' => true,
        ]));
        $this->assertTrue($second->success, $second->message);
        $this->assertSame($customerRef, $second->data['customer_ref'] ?? null, $second->data['save_error'] ?? '');
        $this->assertNotSame($first->data['method_ref'], $second->data['method_ref']);

        // A card update with a full address is checked with a $0 authorization. ZIP 46282
        // passes that check but declines every charge: a card that later fails to renew.
        $token = $this->sandboxToken('46282', '6011000000000012');
        $declining = $driver->savePaymentMethod(new SavePaymentMethodRequest($token['dataDescriptor'], $token['dataValue'], $customerRef, [
            'billing' => ['first_name' => 'Test', 'last_name' => 'Buyer', 'address' => '1 Main St', 'city' => 'Bellevue', 'state' => 'WA', 'zip' => '46282', 'country' => 'US'],
        ]));
        $this->assertTrue($declining->success, $declining->message);
        $this->assertSame('0012', $declining->data['card_last4']);

        $declined = $driver->chargeSaved(new SavedChargeRequest(random_int(100, 99999), $customerRef, $declining->data['method_ref']));
        $this->assertFalse($declined->success);
        $this->assertSame('This transaction has been declined.', $declined->message);

        $this->assertTrue($driver->deleteSavedPaymentMethod($customerRef, $declining->data['method_ref'])->success);
        $this->assertTrue($driver->deleteSavedPaymentMethod($customerRef, $declining->data['method_ref'])->success, 'already deleted');

        // The customer's transaction list can lag a few seconds behind a charge.
        $found = null;
        for ($try = 0; $try < 6; $try++) {
            $found = $driver->findSavedCharge($customerRef, $invoice, $amount);
            if ($found->success) {
                break;
            }
            sleep(5);
        }
        $this->assertTrue($found->success, $found->message);
        $this->assertSame($renewal->transactionId, $found->transactionId);
        $this->assertSame(RecurringGatewayDriver::STATE_PAID, $found->data['state']);
        $this->assertSame('not_found', $driver->findSavedCharge($customerRef, $invoice, $amount + 1)->code);

        // Clean up: a full refund of an unsettled charge voids it, so nothing settles.
        foreach ([[$first, $firstAmount], [$renewal, $amount], [$second, $secondAmount]] as [$charge, $charged]) {
            $this->assertTrue($driver->refund(new RefundRequest($charged, $charged, $charge->transactionId, $charge->data['card_last4']))->success);
        }
    }
}

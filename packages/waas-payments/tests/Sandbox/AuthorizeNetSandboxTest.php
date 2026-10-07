<?php

namespace Tests\Sandbox;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSPayments\Driver\AuthorizeNet\AuthorizeNetClient;
use WaaSPayments\Driver\AuthorizeNet\AuthorizeNetDriver;
use WaaSPayments\Driver\ChargeRequest;
use WaaSPayments\Driver\RefundRequest;

/**
 * Runs the driver against the real Authorize.Net sandbox. Skipped unless
 * AUTHORIZENET_SANDBOX_LOGIN_ID and AUTHORIZENET_SANDBOX_TRANSACTION_KEY are set
 * (a free sandbox account from developer.authorize.net), and apitest.authorize.net must
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

        $this->loginId = (string) getenv('AUTHORIZENET_SANDBOX_LOGIN_ID');
        $this->transactionKey = (string) getenv('AUTHORIZENET_SANDBOX_TRANSACTION_KEY');
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
    private function sandboxToken(string $zip = '98004'): array
    {
        $client = new AuthorizeNetClient($this->loginId, $this->transactionKey, true);
        $response = $client->send('securePaymentContainerRequest', [
            'data' => [
                'type'  => 'TOKEN',
                'id'    => uniqid('waas-test-', true),
                'token' => ['cardNumber' => '4111111111111111', 'expirationDate' => '12' . (date('Y') + 2), 'cardCode' => '123', 'zip' => $zip],
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
}

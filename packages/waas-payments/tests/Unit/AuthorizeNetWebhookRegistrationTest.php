<?php

namespace Tests\Unit;

use Brain\Monkey\Functions;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use WaaSPayments\Driver\AuthorizeNet\AuthorizeNetClient;
use WaaSPayments\Driver\AuthorizeNet\AuthorizeNetDriver;
use WaaSPayments\Gateway\AuthorizeNetGateway;
use WaaSPayments\Gateway\Webhook;

/** Registering the store's webhook through Authorize.Net's Webhooks REST API. */
class AuthorizeNetWebhookRegistrationTest extends TestCase
{
    private const URL = 'https://store.test/wp-json/waas-payments/v1/authorizenet/webhook';

    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        BaseGatewaySettings::$allSettings = [];
        Functions\when('rest_url')->alias(fn ($path) => 'https://store.test/wp-json/' . $path);
    }

    /** Answers wp_remote_request with these bodies in order and records the requests. */
    private function rest(array $bodies): void
    {
        Functions\expect('wp_remote_request')
            ->times(count($bodies))
            ->andReturnUsing(function ($url, $args) use (&$bodies) {
                $this->requests[] = [$args['method'], $url, isset($args['body']) ? json_decode($args['body'], true) : null, $args['headers']['Authorization']];
                return ['response' => ['code' => 200], 'body' => json_encode(array_shift($bodies))];
            });
    }

    private function driver(): AuthorizeNetDriver
    {
        return new AuthorizeNetDriver(new AuthorizeNetClient('login-id', 'txn-key', true));
    }

    public function test_a_new_webhook_is_created_for_the_events_it_handles(): void
    {
        $this->rest([[], ['webhookId' => 'wh-1', 'status' => 'active', 'url' => self::URL]]);

        $result = $this->driver()->registerWebhook(self::URL, Webhook::EVENT_TYPES);

        $this->assertTrue($result->success);
        $this->assertSame('wh-1', $result->transactionId);
        [$method, $url, $body, $auth] = $this->requests[1];
        $this->assertSame('POST', $method);
        $this->assertSame(AuthorizeNetClient::SANDBOX_REST_URL . 'webhooks', $url);
        $this->assertSame(['name' => 'waas_payments', 'url' => self::URL, 'eventTypes' => Webhook::EVENT_TYPES, 'status' => 'active'], $body);
        $this->assertSame('Basic ' . base64_encode('login-id:txn-key'), $auth);
    }

    public function test_the_existing_webhook_for_this_url_is_updated_not_duplicated(): void
    {
        $this->rest([
            [
                ['webhookId' => 'other', 'url' => 'https://another-store.test/hook', 'status' => 'active'],
                ['webhookId' => 'wh-1', 'url' => self::URL, 'status' => 'inactive'],
            ],
            ['webhookId' => 'wh-1', 'status' => 'active', 'url' => self::URL],
        ]);

        $this->assertTrue($this->driver()->registerWebhook(self::URL, Webhook::EVENT_TYPES)->success);
        $this->assertSame(['PUT', AuthorizeNetClient::SANDBOX_REST_URL . 'webhooks/wh-1'], array_slice($this->requests[1], 0, 2));
    }

    public function test_an_account_without_a_signature_key_is_explained(): void
    {
        // What the sandbox answered on 2026-10-12 for an account with no Signature Key.
        $this->rest([[], [
            'status'        => 400,
            'reason'        => 'INVALID_DATA',
            'message'       => 'An error occurred while processing the request. Please note the correlation id for the request and contact Support for more details.',
            'correlationId' => '66b688da-88c6-4ae0-afa4-5d8dbf2acabe',
        ]]);

        $result = $this->driver()->registerWebhook(self::URL, Webhook::EVENT_TYPES);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Signature Key', $result->message);
    }

    public function test_saving_settings_with_a_signature_key_registers_the_webhook(): void
    {
        Functions\expect('wp_remote_post')->once()->andReturn(self::fixture('merchant-details'));
        $this->rest([[], ['webhookId' => 'wh-1', 'status' => 'active', 'url' => self::URL]]);

        $saved = AuthorizeNetGateway::beforeSettingsUpdate(
            ['api_login_id' => 'login-id', 'transaction_key' => 'secret-key', 'sandbox' => 'yes', 'signature_key' => ' SIGKEY ', 'webhook_info' => '<p>shown</p>'],
            []
        );

        $this->assertSame('enc:' . base64_encode('SIGKEY'), $saved['test_signature_key']);
        $this->assertStringStartsWith('Webhook registered with Authorize.Net', $saved['test_webhook_status']);
        $this->assertArrayNotHasKey('webhook_info', $saved);
    }

    public function test_saving_settings_without_a_signature_key_leaves_webhooks_alone(): void
    {
        Functions\expect('wp_remote_post')->once()->andReturn(self::fixture('merchant-details'));
        Functions\expect('wp_remote_request')->never();

        $saved = AuthorizeNetGateway::beforeSettingsUpdate(['api_login_id' => 'login-id', 'transaction_key' => 'secret-key'], []);

        $this->assertSame('', $saved['test_signature_key']);
    }

    public function test_plain_permalinks_are_reported_instead_of_registering(): void
    {
        Functions\when('rest_url')->alias(fn ($path) => 'https://store.test/?rest_route=/' . $path);
        Functions\expect('wp_remote_post')->once()->andReturn(self::fixture('merchant-details'));
        Functions\expect('wp_remote_request')->never();

        $saved = AuthorizeNetGateway::beforeSettingsUpdate(['api_login_id' => 'login-id', 'transaction_key' => 'secret-key', 'signature_key' => 'SIGKEY'], []);

        $this->assertStringContainsString('pretty permalinks', $saved['test_webhook_status']);
    }

    public function test_the_signature_key_is_read_back_decrypted(): void
    {
        BaseGatewaySettings::$allSettings[(new \WaaSPayments\Gateway\AuthorizeNetSettings())->methodHandler] = ['signature_key' => 'enc:' . base64_encode('SIGKEY')];

        $this->assertSame('SIGKEY', (new \WaaSPayments\Gateway\AuthorizeNetSettings())->getSignatureKey());
    }
}

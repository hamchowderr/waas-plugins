<?php

namespace Tests\Unit;

use Brain\Monkey\Functions;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use WaaSPayments\Gateway\AuthorizeNetGateway;
use WaaSPayments\Gateway\AuthorizeNetSettings;
use WaaSPayments\Gateway\Webhook;

/** Test and live credentials, chosen by the store's Order Mode like FluentCart's own gateways. */
class AuthorizeNetModesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BaseGatewaySettings::$allSettings = [];
        StoreSettings::$values = [];
        Functions\when('rest_url')->alias(fn ($path) => 'https://store.test/wp-json/' . $path);
    }

    protected function tearDown(): void
    {
        StoreSettings::$values = [];
        parent::tearDown();
    }

    private static function settings(array $stored): AuthorizeNetSettings
    {
        BaseGatewaySettings::$allSettings[(new AuthorizeNetSettings())->methodHandler] = $stored;

        return new AuthorizeNetSettings();
    }

    public function test_sandbox_keys_from_before_the_update_become_the_test_keys(): void
    {
        $settings = self::settings(['api_login_id' => 'sandbox-login', 'transaction_key' => 'enc:' . base64_encode('k'), 'public_client_key' => 'ck', 'sandbox' => 'yes']);

        $this->assertSame('sandbox-login', $settings->getApiLoginId('test'));
        $this->assertSame('k', $settings->getTransactionKey('test'));
        $this->assertSame('', $settings->getApiLoginId('live'));
        $this->assertNull($settings->get('api_login_id'));
    }

    public function test_live_keys_from_before_the_update_become_the_live_keys(): void
    {
        $settings = self::settings(['api_login_id' => 'live-login', 'sandbox' => 'no']);

        $this->assertSame('live-login', $settings->getApiLoginId('live'));
        $this->assertSame('', $settings->getApiLoginId('test'));
    }

    public function test_the_mode_is_the_stores_order_mode(): void
    {
        $settings = self::settings(['test_api_login_id' => 'sandbox-login', 'live_api_login_id' => 'live-login', 'sandbox' => 'yes']);

        StoreSettings::$values['order_mode'] = 'live';
        $this->assertSame('live', $settings->getMode());
        $this->assertFalse($settings->isSandbox());
        $this->assertSame('live-login', $settings->getApiLoginId());

        StoreSettings::$values['order_mode'] = 'test';
        $this->assertSame('sandbox-login', $settings->getApiLoginId());
    }

    public function test_checkout_uses_the_live_keys_in_live_mode(): void
    {
        StoreSettings::$values['order_mode'] = 'live';
        self::settings([
            'test_api_login_id' => 'sandbox-login', 'test_public_client_key' => 'sandbox-ck',
            'live_api_login_id' => 'live-login', 'live_public_client_key' => 'live-ck',
        ]);

        $config = (new AuthorizeNetGateway(fn () => new FakeDriver()))->checkoutConfig();

        $this->assertSame(['api_login_id' => 'live-login', 'public_client_key' => 'live-ck', 'sandbox' => false], $config['payment_args']);
    }

    public function test_live_mode_without_live_keys_turns_card_payments_off(): void
    {
        StoreSettings::$values['order_mode'] = 'live';
        self::settings(['test_api_login_id' => 'sandbox-login', 'test_public_client_key' => 'sandbox-ck']);

        $this->assertSame('failed', (new AuthorizeNetGateway(fn () => new FakeDriver()))->checkoutConfig()['status']);
    }

    public function test_a_sandbox_charge_is_refunded_with_the_test_keys_in_live_mode(): void
    {
        StoreSettings::$values['order_mode'] = 'live';
        self::settings([
            'test_api_login_id' => 'sandbox-login', 'test_transaction_key' => 'enc:' . base64_encode('sandbox-key'),
            'live_api_login_id' => 'live-login', 'live_transaction_key' => 'enc:' . base64_encode('live-key'),
        ]);
        Functions\expect('wp_remote_post')->once()->andReturnUsing(function ($url, $args) {
            $this->assertStringContainsString('apitest.authorize.net', $url);
            $auth = json_decode($args['body'], true)['createTransactionRequest']['merchantAuthentication'];
            $this->assertSame(['name' => 'sandbox-login', 'transactionKey' => 'sandbox-key'], $auth);
            return self::fixture('refund-approved');
        });

        $refundId = (new AuthorizeNetGateway())->processRefund(new OrderTransaction([
            'total' => 12345, 'vendor_charge_id' => '2149186954', 'card_last_4' => '1111', 'meta' => ['authorizenet_env' => 'sandbox'],
        ]), 2500, []);

        $this->assertIsString($refundId);
    }

    public function test_activating_in_live_mode_needs_the_live_keys(): void
    {
        StoreSettings::$values['order_mode'] = 'live';
        Functions\expect('wp_remote_post')->once()->andReturn(self::fixture('merchant-details'));

        $result = AuthorizeNetGateway::validateSettings(['test_api_login_id' => 'login-id', 'test_transaction_key' => 'secret-key']);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('live API Login ID', $result['message']);
    }

    public function test_saving_records_which_account_each_mode_is_connected_to(): void
    {
        Functions\expect('wp_remote_post')->twice()->andReturn(self::fixture('merchant-details'), self::fixture('auth-failed'));

        $saved = AuthorizeNetGateway::beforeSettingsUpdate([
            'test_api_login_id' => 'login-id', 'test_transaction_key' => 'secret-key',
            'live_api_login_id' => 'bad', 'live_transaction_key' => 'bad',
            'test_status' => '<p>shown</p>',
        ], []);

        $this->assertNotSame('', $saved['test_public_client_key']);
        $this->assertNotSame('', $saved['test_verified_at']);
        $this->assertSame('', $saved['test_error']);
        $this->assertSame('', $saved['live_public_client_key']);
        $this->assertStringContainsString('User authentication failed', $saved['live_error']);
        $this->assertArrayNotHasKey('test_status', $saved);
    }

    public function test_a_notification_from_the_account_not_in_use_is_acknowledged_and_ignored(): void
    {
        StoreSettings::$values['order_mode'] = 'live';
        self::settings(['test_signature_key' => 'enc:' . base64_encode('SANDBOXSIG'), 'live_signature_key' => 'enc:' . base64_encode('LIVESIG')]);
        $body = json_encode(['eventType' => 'net.authorize.payment.refund.created', 'payload' => ['entityName' => 'transaction', 'id' => '1']]);

        $result = (new Webhook(new AuthorizeNetGateway(fn () => new FakeDriver())))
            ->handle($body, 'sha512=' . strtoupper(hash_hmac('sha512', $body, 'SANDBOXSIG')));

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('Ignored', $result['message']);
        $this->assertGreaterThan(0, AuthorizeNetSettings::lastWebhookAt('test'));
        $this->assertSame(0, AuthorizeNetSettings::lastWebhookAt('live'));
    }
}

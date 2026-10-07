<?php

namespace Tests\Unit;

use Brain\Monkey\Functions;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use WaaSPayments\Driver\DriverResult;
use WaaSPayments\Gateway\AuthorizeNetGateway;
use WaaSPayments\Gateway\AuthorizeNetSettings;

class AuthorizeNetGatewayTest extends TestCase
{
    private FakeDriver $driver;
    private array $driverModes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new FakeDriver();
        BaseGatewaySettings::$allSettings = [];
    }

    private function gateway(array $settings = []): AuthorizeNetGateway
    {
        BaseGatewaySettings::$allSettings[(new AuthorizeNetSettings())->methodHandler] = $settings;

        return new AuthorizeNetGateway(function ($sandbox) {
            $this->driverModes[] = $sandbox;
            return $this->driver;
        });
    }

    public function test_it_offers_payments_and_refunds_but_not_subscriptions(): void
    {
        $gateway = $this->gateway();

        $this->assertTrue($gateway->has('payment'));
        $this->assertTrue($gateway->has('refund'));
        $this->assertFalse($gateway->has('subscriptions'));
        $this->assertNull($gateway->subscriptions);
    }

    public function test_placing_an_order_hands_the_transaction_to_the_card_form(): void
    {
        $result = $this->gateway()->makePaymentFromPaymentInstance(
            new PaymentInstance((object) ['id' => 7], (object) ['uuid' => 'trx-abc'])
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('waas_authorizenet', $result['nextAction']);
        $this->assertSame('custom', $result['actionName']);
        $this->assertSame('trx-abc', $result['data']['transaction_hash']);
        $this->assertSame([], $this->driver->charges, 'nothing is charged before the card form returns a token');
    }

    public function test_a_subscription_order_is_refused(): void
    {
        $result = $this->gateway()->makePaymentFromPaymentInstance(
            new PaymentInstance((object) ['id' => 7], (object) ['uuid' => 'trx-abc'], (object) ['collection_method' => 'manual'])
        );

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('waas_payments_no_subscriptions', $result->get_error_code());
    }

    public function test_checkout_config_holds_only_public_values(): void
    {
        $config = $this->gateway([
            'api_login_id'      => 'login-id',
            'transaction_key'   => 'enc:' . base64_encode('secret-key'),
            'public_client_key' => 'client-key',
            'sandbox'           => 'yes',
        ])->checkoutConfig();

        $this->assertSame('success', $config['status']);
        $this->assertSame(['api_login_id' => 'login-id', 'public_client_key' => 'client-key', 'sandbox' => true], $config['payment_args']);
        $this->assertStringNotContainsString('secret-key', json_encode($config));
    }

    public function test_checkout_config_fails_until_the_gateway_is_set_up(): void
    {
        $this->assertSame('failed', $this->gateway(['api_login_id' => 'login-id'])->checkoutConfig()['status']);
    }

    public function test_refund_goes_to_the_environment_that_took_the_charge(): void
    {
        $gateway = $this->gateway(['sandbox' => 'no']);
        $transaction = new OrderTransaction([
            'total'            => 12345,
            'vendor_charge_id' => '2149186954',
            'card_last_4'      => '1111',
            'meta'             => ['authorizenet_env' => 'sandbox'],
        ]);

        $refundId = $gateway->processRefund($transaction, 2500, []);

        $this->assertSame('2149186960', $refundId);
        $this->assertSame([true], $this->driverModes);
        $refund = $this->driver->refunds[0];
        $this->assertSame([2500, 12345, '2149186954', '1111'], [$refund->amount, $refund->chargedAmount, $refund->chargeId, $refund->cardLast4]);
    }

    public function test_a_failed_refund_is_returned_as_an_error(): void
    {
        $this->driver->refundResult = DriverResult::failure('This payment has not settled yet.', '54');
        $transaction = new OrderTransaction(['total' => 12345, 'vendor_charge_id' => '2149186954', 'card_last_4' => '1111', 'meta' => []]);

        $result = $this->gateway()->processRefund($transaction, 2500, []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('This payment has not settled yet.', $result->get_error_message());
    }

    /**
     * What FluentCart 1.7.0 has recorded by the time it calls processRefund
     * (app/Services/Payments/Refund.php:42-63): a refund transaction for the amount, and the
     * amount added to the charge's refunded_total.
     */
    private function chargeWithRecordedRefund(int $amount, int $alreadyRefunded = 0): OrderTransaction
    {
        $charge = new OrderTransaction([
            'order_id'         => 42,
            'transaction_type' => 'charge',
            'status'           => 'succeeded',
            'total'            => 5000,
            'vendor_charge_id' => '2149186954',
            'card_last_4'      => '1111',
            'meta'             => $alreadyRefunded ? ['refunded_total' => $alreadyRefunded] : [],
        ]);
        $charge->save();

        (new OrderTransaction([
            'order_id'         => 42,
            'transaction_type' => 'refund',
            'status'           => 'refunded',
            'total'            => $amount,
            'vendor_charge_id' => '',
            'meta'             => ['parent_id' => $charge->id, 'reason' => ''],
        ]))->save();

        $meta = $charge->meta;
        $meta['refunded_total'] = ($meta['refunded_total'] ?? 0) + $amount;
        $charge->meta = $meta;

        return $charge;
    }

    private static function refundRows(): array
    {
        return array_values(array_filter(OrderTransaction::$table, fn ($row) => $row->transaction_type === 'refund'));
    }

    public function test_a_refused_refund_is_taken_back_out_of_fluentcart(): void
    {
        $this->driver->refundResult = DriverResult::failure('This payment has not settled yet.', '54');
        $charge = $this->chargeWithRecordedRefund(1000);

        $result = $this->gateway()->processRefund($charge, 1000, []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('This payment has not settled yet. Nothing was refunded and the order is unchanged.', $result->get_error_message());
        $this->assertSame([], self::refundRows(), 'the refund transaction FluentCart created is gone');
        $this->assertSame(0, $charge->meta['refunded_total'], 'the charge shows nothing refunded');
    }

    public function test_a_refused_refund_keeps_earlier_refunds(): void
    {
        $this->driver->refundResult = DriverResult::failure('Declined.', '2');
        $charge = $this->chargeWithRecordedRefund(1000, 500);
        $earlier = new OrderTransaction([
            'order_id'         => 42,
            'transaction_type' => 'refund',
            'status'           => 'refunded',
            'total'            => 500,
            'vendor_charge_id' => '2149186960',
            'meta'             => ['parent_id' => $charge->id],
        ]);
        $earlier->save();

        $this->gateway()->processRefund($charge, 1000, []);

        $this->assertSame([$earlier], self::refundRows());
        $this->assertSame(500, $charge->meta['refunded_total']);
    }

    public function test_an_accepted_refund_stays_recorded(): void
    {
        $charge = $this->chargeWithRecordedRefund(1000);

        $this->assertSame('2149186960', $this->gateway()->processRefund($charge, 1000, []));

        $this->assertCount(1, self::refundRows());
        $this->assertSame(1000, $charge->meta['refunded_total']);
    }

    public function test_settings_are_checked_with_authorize_net_before_activating(): void
    {
        Functions\expect('wp_remote_post')->once()->andReturn(self::fixture('auth-failed'));

        $result = AuthorizeNetGateway::validateSettings(['api_login_id' => 'bad', 'transaction_key' => 'bad', 'sandbox' => 'yes']);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('User authentication failed', $result['message']);
    }

    public function test_settings_need_both_credentials(): void
    {
        Functions\expect('wp_remote_post')->never();

        $this->assertSame('failed', AuthorizeNetGateway::validateSettings(['api_login_id' => 'login-id'])['status']);
    }

    public function test_saving_encrypts_the_transaction_key_and_stores_the_public_client_key(): void
    {
        Functions\expect('wp_remote_post')->once()->andReturnUsing(function ($url, $args) {
            $this->assertSame('secret-key', json_decode($args['body'], true)['getMerchantDetailsRequest']['merchantAuthentication']['transactionKey']);
            return self::fixture('merchant-details');
        });

        $saved = AuthorizeNetGateway::beforeSettingsUpdate(
            ['api_login_id' => ' login-id ', 'transaction_key' => 'secret-key', 'sandbox' => 'yes'],
            []
        );

        $this->assertSame('login-id', $saved['api_login_id']);
        $this->assertSame('enc:' . base64_encode('secret-key'), $saved['transaction_key']);
        $this->assertSame('9aptUW2DxG7rfEZ8VBpR4Hu2B8n7Bq6Q8cUbT4CJ6jXmbzWvZ5d6WrP8a3Vgkm4U', $saved['public_client_key']);
    }

    public function test_saving_an_already_encrypted_key_does_not_encrypt_it_twice(): void
    {
        Functions\expect('wp_remote_post')->once()->andReturn(self::fixture('merchant-details'));
        $stored = 'enc:' . base64_encode('secret-key');

        $saved = AuthorizeNetGateway::beforeSettingsUpdate(['api_login_id' => 'login-id', 'transaction_key' => $stored], []);

        $this->assertSame($stored, $saved['transaction_key']);
    }

    public function test_transaction_links_open_the_matching_merchant_interface(): void
    {
        $gateway = $this->gateway();
        $sandboxCharge = new OrderTransaction(['vendor_charge_id' => '2149186954', 'meta' => ['authorizenet_env' => 'sandbox']]);
        $liveCharge = new OrderTransaction(['vendor_charge_id' => '2149186954', 'meta' => ['authorizenet_env' => 'production']]);

        $this->assertStringStartsWith('https://sandbox.authorize.net/', $gateway->getTransactionUrl('', ['transaction' => $sandboxCharge]));
        $this->assertStringStartsWith('https://account.authorize.net/', $gateway->getTransactionUrl('', ['transaction' => $liveCharge]));
        $this->assertStringEndsWith('transID=2149186954', $gateway->getTransactionUrl('', ['transaction' => $liveCharge]));
    }
}

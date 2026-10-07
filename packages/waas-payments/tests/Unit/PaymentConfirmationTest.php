<?php

namespace Tests\Unit;

use Brain\Monkey\Functions;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use WaaSPayments\Driver\DriverResult;
use WaaSPayments\Gateway\AuthorizeNetGateway;
use WaaSPayments\Gateway\AuthorizeNetSettings;
use WaaSPayments\Gateway\PaymentConfirmation;

class PaymentConfirmationTest extends TestCase
{
    private const TOKEN = 'COMMON.ACCEPT.INAPP.PAYMENT';

    private FakeDriver $driver;
    private PaymentConfirmation $confirmation;
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        StatusHelper::$synced = [];
        BaseGatewaySettings::$allSettings = [(new AuthorizeNetSettings())->methodHandler => ['sandbox' => 'yes']];

        $this->driver = new FakeDriver();
        $this->confirmation = new PaymentConfirmation(new AuthorizeNetGateway(fn () => $this->driver));

        Functions\when('fluent_cart_add_log')->alias(function ($title, $content, $status) {
            $this->logs[] = [$title, $content, $status];
        });
    }

    private function pendingTransaction(array $overrides = []): OrderTransaction
    {
        $order = (object) [
            'id'          => 7,
            'invoice_no'  => 'INV-1001',
            'email'       => 'buyer@example.com',
            'customer_id' => 42,
            'customer'    => (object) ['email' => 'buyer@example.com'],
        ];

        return new OrderTransaction(array_merge([
            'uuid'           => 'trx-abc',
            'status'         => 'pending',
            'total'          => 12345,
            'payment_method' => 'waas_authorizenet',
            'meta'           => [],
            'order'          => $order,
        ], $overrides));
    }

    public function test_a_token_is_charged_for_the_stored_amount_and_the_order_marked_paid(): void
    {
        $transaction = $this->pendingTransaction();

        $result = $this->confirmation->confirm($transaction, self::TOKEN, 'nonce-value', '203.0.113.9');

        $this->assertSame(200, $result['status']);
        $this->assertSame('success', $result['body']['status']);
        $this->assertSame('https://store.test/receipt/?trx_hash=trx-abc', $result['body']['redirect_url']);

        $charge = $this->driver->charges[0];
        $this->assertSame(12345, $charge->amount);
        $this->assertSame('nonce-value', $charge->tokenValue);
        $this->assertSame('INV-1001', $charge->invoiceNumber);
        $this->assertSame('Peptide Test Store #INV-1001', $charge->description);
        $this->assertSame('buyer@example.com', $charge->customerEmail);
        $this->assertSame('42', $charge->customerId);
        $this->assertSame('203.0.113.9', $charge->customerIp);

        $this->assertSame('succeeded', $transaction->status);
        $this->assertSame('2149186954', $transaction->vendor_charge_id);
        $this->assertSame('1111', $transaction->card_last_4);
        $this->assertSame('Visa', $transaction->card_brand);
        $this->assertSame('sandbox', $transaction->meta['authorizenet_env']);
        $this->assertSame('HW617E', $transaction->meta['auth_code']);
        $this->assertSame(1, $transaction->saved);
        $this->assertCount(1, StatusHelper::$synced);
        $this->assertSame($transaction, StatusHelper::$synced[0][1]);
    }

    public function test_a_new_order_without_an_invoice_number_is_sent_with_its_order_id(): void
    {
        $transaction = $this->pendingTransaction();
        $transaction->order->invoice_no = '';

        $this->confirmation->confirm($transaction, self::TOKEN, 'nonce-value');

        $this->assertSame('7', $this->driver->charges[0]->invoiceNumber);
        $this->assertSame('Peptide Test Store #7', $this->driver->charges[0]->description);
    }

    public function test_confirming_a_paid_transaction_again_does_not_charge_twice(): void
    {
        $transaction = $this->pendingTransaction(['status' => 'succeeded']);

        $result = $this->confirmation->confirm($transaction, self::TOKEN, 'another-nonce');

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $this->driver->charges);
    }

    public function test_a_declined_card_leaves_the_order_unpaid_and_logs_the_reason(): void
    {
        $this->driver->chargeResult = DriverResult::failure('This transaction has been declined.', '2', [], '2149186955');
        $transaction = $this->pendingTransaction();

        $result = $this->confirmation->confirm($transaction, self::TOKEN, 'nonce-value');

        $this->assertSame(422, $result['status']);
        $this->assertSame('This transaction has been declined.', $result['body']['message']);
        $this->assertSame('pending', $transaction->status);
        $this->assertSame(0, $transaction->saved);
        $this->assertSame([], StatusHelper::$synced);
        $this->assertSame('error', $this->logs[0][2]);
        $this->assertStringContainsString('2149186955', $this->logs[0][1]);
    }

    public function test_a_missing_or_foreign_token_is_refused_before_calling_the_gateway(): void
    {
        $this->assertSame(400, $this->confirmation->confirm($this->pendingTransaction(), self::TOKEN, '')['status']);
        $this->assertSame(400, $this->confirmation->confirm($this->pendingTransaction(), 'SOMETHING.ELSE', 'nonce')['status']);
        $this->assertSame([], $this->driver->charges);
    }

    public function test_an_unknown_transaction_is_not_found(): void
    {
        $this->assertSame(404, $this->confirmation->confirm(null, self::TOKEN, 'nonce')['status']);
    }

    public function test_a_refunded_transaction_cannot_be_paid_again(): void
    {
        $result = $this->confirmation->confirm($this->pendingTransaction(['status' => 'refunded']), self::TOKEN, 'nonce');

        $this->assertSame(409, $result['status']);
        $this->assertSame([], $this->driver->charges);
    }
}

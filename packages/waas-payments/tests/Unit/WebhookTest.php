<?php

namespace Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Modules\Subscriptions\Services\SystemChargeService;
use FluentCart\App\Services\Payments\Refund;
use WaaSPayments\Driver\DriverResult;
use WaaSPayments\Driver\RecurringGatewayDriver;
use WaaSPayments\Gateway\AuthorizeNetGateway;
use WaaSPayments\Gateway\AuthorizeNetSettings;
use WaaSPayments\Gateway\RenewalCharge;
use WaaSPayments\Gateway\Webhook;
use WaaSPayments\Gateway\WebhookSync;

/**
 * Authorize.Net webhooks: signed notifications that make FluentCart catch up with refunds,
 * voids and late-confirmed charges at Authorize.Net.
 */
class WebhookTest extends TestCase
{
    private const KEY = 'A1B2C3D4E5F60718293A4B5C6D7E8F90A1B2C3D4E5F60718293A4B5C6D7E8F90A1B2C3D4E5F60718293A4B5C6D7E8F90A1B2C3D4E5F60718293A4B5C6D7E8F90';

    private FakeDriver $driver;
    private AuthorizeNetGateway $gateway;
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        StatusHelper::$synced = [];
        SystemChargeService::$calls = [];
        Subscription::$table = [];
        Order::$table = [];
        Refund::$recorded = [];
        BaseGatewaySettings::$allSettings = [(new AuthorizeNetSettings())->methodHandler => [
            'sandbox'       => 'yes',
            'signature_key' => 'enc:' . base64_encode(self::KEY),
        ]];

        $this->driver = new FakeDriver();
        $this->gateway = new AuthorizeNetGateway(fn () => $this->driver);

        Functions\when('fluent_cart_add_log')->alias(function ($title, $content, $status) {
            $this->logs[] = [$title, $content, $status];
        });

        $GLOBALS['wpdb'] = new class {
            public function prepare($query, ...$args)
            {
                return $query;
            }

            public function get_var($query)
            {
                return '1';
            }

            public function query($query)
            {
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private static function body(string $eventType, string $transactionId): string
    {
        return json_encode([
            'notificationId' => 'd0e8e7fe-c3e7-4add-a480-27bc5ce28e30',
            'eventType'      => $eventType,
            'eventDate'      => '2026-10-12T19:30:00.000Z',
            'webhookId'      => '72a55c78-66e6-4b1e-a4d6-3f925c00561f',
            'payload'        => [
                'responseCode'  => 1,
                'authCode'      => 'LZ6I19',
                'avsResponse'   => 'Y',
                'authAmount'    => 49.00,
                'invoiceNumber' => '7',
                'entityName'    => 'transaction',
                'id'            => $transactionId,
            ],
        ]);
    }

    private static function sign(string $body, string $key = self::KEY): string
    {
        return 'sha512=' . strtoupper(hash_hmac('sha512', $body, $key));
    }

    private function deliver(string $eventType, string $transactionId): array
    {
        $body = self::body($eventType, $transactionId);

        return (new Webhook($this->gateway))->handle($body, self::sign($body));
    }

    private function order(array $overrides = []): Order
    {
        return new Order(array_merge([
            'id'          => 7,
            'invoice_no'  => '',
            // FluentCart orders have no email of their own; it's the customer's.
            'customer'    => (object) ['email' => 'Buyer@Example.com'],
            'customer_id' => 42,
        ], $overrides));
    }

    private function charge(array $overrides = []): OrderTransaction
    {
        $order = $overrides['order'] ?? $this->order();
        $transaction = new OrderTransaction(array_merge([
            'uuid'             => 'trx-abc',
            'order_id'         => $order->id,
            'order'            => $order,
            'status'           => 'succeeded',
            'transaction_type' => 'charge',
            'total'            => 4900,
            'payment_method'   => 'waas_authorizenet',
            'vendor_charge_id' => '80060612550',
            'meta'             => ['authorizenet_env' => 'sandbox'],
        ], $overrides));
        $transaction->save();

        return $transaction;
    }

    /** What getTransactionDetails reports, through FakeDriver::chargeStatus. */
    private function authorizeNetHas(string $transactionId, string $status, array $data = []): void
    {
        $states = [
            'capturedPendingSettlement' => RecurringGatewayDriver::STATE_PAID,
            'settledSuccessfully'       => RecurringGatewayDriver::STATE_PAID,
            'FDSPendingReview'          => RecurringGatewayDriver::STATE_PENDING,
        ];

        $this->driver->statusResult = DriverResult::success($transactionId, $status, array_merge([
            'state'              => $states[$status] ?? RecurringGatewayDriver::STATE_FAILED,
            'transaction_status' => $status,
            'transaction_type'   => 'authCaptureTransaction',
            'auth_amount'        => 4900,
            'card_last4'         => '1111',
            'card_brand'         => 'Visa',
            'auth_code'          => 'LZ6I19',
            'invoice_number'     => '7',
            'customer_id'        => '42',
            'customer_email'     => 'buyer@example.com',
            'customer_ref'       => '',
        ], $data));
    }

    public function test_a_signature_made_with_the_signature_key_is_accepted(): void
    {
        $body = self::body('net.authorize.payment.authcapture.created', '1');

        $this->assertTrue(Webhook::verifySignature($body, self::sign($body), self::KEY));
        $this->assertTrue(Webhook::verifySignature($body, strtolower(self::sign($body)), self::KEY), 'hex case does not matter');
        $this->assertFalse(Webhook::verifySignature($body . ' ', self::sign($body), self::KEY));
        $this->assertFalse(Webhook::verifySignature($body, self::sign($body, 'other-key'), self::KEY));
        $this->assertFalse(Webhook::verifySignature($body, '', self::KEY));
    }

    public function test_an_unsigned_or_badly_signed_notification_is_refused_without_a_lookup(): void
    {
        $body = self::body('net.authorize.payment.refund.created', '80060612600');

        $this->assertSame(401, (new Webhook($this->gateway))->handle($body, '')['status']);
        $this->assertSame(401, (new Webhook($this->gateway))->handle($body, self::sign($body, 'guess'))['status']);
        $this->assertSame([], $this->driver->statusChecks);
    }

    public function test_without_a_signature_key_nothing_is_accepted(): void
    {
        BaseGatewaySettings::$allSettings[(new AuthorizeNetSettings())->methodHandler]['signature_key'] = '';
        $gateway = new AuthorizeNetGateway(fn () => $this->driver);
        $body = self::body('net.authorize.payment.refund.created', '80060612600');

        $this->assertSame(401, (new Webhook($gateway))->handle($body, self::sign($body, ''))['status']);
        $this->assertSame([], $this->driver->statusChecks);
    }

    public function test_events_it_does_not_handle_are_acknowledged_and_ignored(): void
    {
        $result = $this->deliver('net.authorize.customer.created', '528040951');

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $this->driver->statusChecks);
    }

    public function test_a_refund_made_in_authorize_net_is_recorded_in_fluentcart(): void
    {
        $charge = $this->charge();
        $this->authorizeNetHas('80060612600', 'refundPendingSettlement', [
            'transaction_type' => 'refundTransaction',
            'ref_trans_id'     => '80060612550',
            'auth_amount'      => 1500,
        ]);

        $result = $this->deliver('net.authorize.payment.refund.created', '80060612600');

        $this->assertSame(200, $result['status']);
        $this->assertSame(['80060612600'], $this->driver->statusChecks, 'the refund is read back from Authorize.Net');
        $this->assertCount(1, Refund::$recorded);
        $this->assertSame('80060612600', Refund::$recorded[0]->vendor_charge_id);
        $this->assertSame(1500, Refund::$recorded[0]->total);
        $this->assertSame(1500, $charge->meta['refunded_total']);
        $this->assertSame('Authorize.Net refund recorded', $this->logs[0][0]);
    }

    public function test_the_same_refund_delivered_twice_is_recorded_once(): void
    {
        $this->charge();
        $this->authorizeNetHas('80060612600', 'refundPendingSettlement', [
            'transaction_type' => 'refundTransaction',
            'ref_trans_id'     => '80060612550',
            'auth_amount'      => 1500,
        ]);

        $this->deliver('net.authorize.payment.refund.created', '80060612600');
        $result = $this->deliver('net.authorize.payment.refund.created', '80060612600');

        $this->assertSame(200, $result['status']);
        $this->assertCount(1, Refund::$recorded);
    }

    public function test_a_refund_made_from_fluentcart_is_not_recorded_again(): void
    {
        $charge = $this->charge();
        // What FluentCart's own refund left behind (Refund::processRefund + processRefund's ID).
        (new OrderTransaction([
            'order_id' => 7, 'transaction_type' => 'refund', 'status' => 'refunded', 'total' => 1500,
            'vendor_charge_id' => '80060612600', 'meta' => ['parent_id' => $charge->id],
        ]))->save();
        $this->authorizeNetHas('80060612600', 'refundPendingSettlement', [
            'transaction_type' => 'refundTransaction',
            'ref_trans_id'     => '80060612550',
            'auth_amount'      => 1500,
        ]);

        $this->deliver('net.authorize.payment.refund.created', '80060612600');

        $this->assertSame([], Refund::$recorded);
    }

    public function test_a_refund_of_another_stores_charge_changes_nothing(): void
    {
        $this->charge();
        $this->authorizeNetHas('80060612600', 'refundPendingSettlement', [
            'transaction_type' => 'refundTransaction',
            'ref_trans_id'     => '99999999999',
            'auth_amount'      => 1500,
        ]);

        $result = $this->deliver('net.authorize.payment.refund.created', '80060612600');

        $this->assertSame(200, $result['status']);
        $this->assertSame([], Refund::$recorded);
    }

    public function test_a_void_made_in_authorize_net_refunds_the_rest_of_the_charge(): void
    {
        $charge = $this->charge();
        $this->authorizeNetHas('80060612550', 'voided');

        $this->deliver('net.authorize.payment.void.created', '80060612550');

        $this->assertCount(1, Refund::$recorded);
        $this->assertSame(4900, Refund::$recorded[0]->total);
        $this->assertSame('80060612550', Refund::$recorded[0]->vendor_charge_id);
        $this->assertSame('Voided in Authorize.Net', Refund::$recorded[0]->meta['reason']);
        $this->assertSame(4900, $charge->meta['refunded_total']);
    }

    public function test_a_void_fluentcart_made_itself_is_not_recorded_again(): void
    {
        $this->charge(['meta' => ['authorizenet_env' => 'sandbox', 'refunded_total' => 4900]]);
        $this->authorizeNetHas('80060612550', 'voided');

        $this->deliver('net.authorize.payment.void.created', '80060612550');

        $this->assertSame([], Refund::$recorded);
    }

    public function test_a_charge_whose_answer_was_lost_marks_the_order_paid(): void
    {
        $charge = $this->charge(['status' => 'pending', 'vendor_charge_id' => '', 'meta' => []]);
        $this->authorizeNetHas('80060612551', 'capturedPendingSettlement');

        $result = $this->deliver('net.authorize.payment.authcapture.created', '80060612551');

        $this->assertSame(200, $result['status']);
        $this->assertSame('succeeded', $charge->status);
        $this->assertSame('80060612551', $charge->vendor_charge_id);
        $this->assertSame('1111', $charge->card_last_4);
        $this->assertSame('sandbox', $charge->meta['authorizenet_env']);
        $this->assertCount(1, StatusHelper::$synced);
        $this->assertSame('Authorize.Net payment confirmed by webhook', end($this->logs)[0]);
    }

    public function test_a_held_checkout_charge_the_merchant_approves_marks_the_order_paid(): void
    {
        $charge = $this->charge(['status' => 'pending', 'vendor_charge_id' => '', 'meta' => []]);
        $this->authorizeNetHas('80060612551', 'capturedPendingSettlement');

        $this->deliver('net.authorize.payment.fraud.approved', '80060612551');

        $this->assertSame('succeeded', $charge->status);
    }

    public function test_the_same_order_number_at_another_store_is_left_alone(): void
    {
        $charge = $this->charge(['status' => 'pending', 'vendor_charge_id' => '', 'meta' => []]);
        // Same order number and amount, another store's customer.
        $this->authorizeNetHas('80060612551', 'capturedPendingSettlement', ['customer_email' => 'someone@else.test', 'customer_id' => '9']);

        $this->deliver('net.authorize.payment.authcapture.created', '80060612551');

        $this->assertSame('pending', $charge->status);
        $this->assertSame([], StatusHelper::$synced);
    }

    public function test_a_different_amount_is_not_this_orders_charge(): void
    {
        $charge = $this->charge(['status' => 'pending', 'vendor_charge_id' => '', 'meta' => []]);
        $this->authorizeNetHas('80060612551', 'capturedPendingSettlement', ['auth_amount' => 5000]);

        $this->deliver('net.authorize.payment.authcapture.created', '80060612551');

        $this->assertSame('pending', $charge->status);
    }

    public function test_a_second_charge_for_a_paid_order_is_flagged_not_recorded(): void
    {
        $charge = $this->charge();
        $this->authorizeNetHas('80060612551', 'capturedPendingSettlement');

        $this->deliver('net.authorize.payment.authcapture.created', '80060612551');

        $this->assertSame('80060612550', $charge->vendor_charge_id);
        $this->assertSame([], StatusHelper::$synced);
        $this->assertSame(['Second Authorize.Net charge for this order', 'warning'], [end($this->logs)[0], end($this->logs)[2]]);
    }

    public function test_the_charge_fluentcart_already_recorded_changes_nothing(): void
    {
        $this->charge();
        $this->authorizeNetHas('80060612550', 'capturedPendingSettlement');

        $this->deliver('net.authorize.payment.authcapture.created', '80060612550');

        $this->assertSame([], StatusHelper::$synced);
        $this->assertSame([], $this->logs);
    }

    public function test_a_late_first_payment_of_an_auto_charged_subscription_saves_the_card(): void
    {
        $subscription = new Subscription(['id' => 3]);
        $charge = $this->charge(['status' => 'pending', 'vendor_charge_id' => '', 'meta' => [], 'subscription_id' => 3]);
        $this->authorizeNetHas('80060612551', 'capturedPendingSettlement', ['network_trans_id' => 'NETWORK-ID']);
        $this->driver->saveResult = DriverResult::success('', '', ['customer_ref' => '528040951', 'method_ref' => '540022572']);

        $this->deliver('net.authorize.payment.authcapture.created', '80060612551');

        $this->assertSame('succeeded', $charge->status);
        $card = $subscription->meta['active_payment_method'];
        $this->assertSame('540022572', $card['vendor_method_id']);
        $this->assertSame('528040951', $card['customer_ref']);
        $this->assertSame('NETWORK-ID', $card['network_trans_id']);
        $this->assertSame('1111', $card['details']['last_4']);
    }

    public function test_a_late_first_payment_whose_card_cannot_be_saved_moves_renewals_to_invoices(): void
    {
        new Subscription(['id' => 3]);
        $this->charge(['status' => 'pending', 'vendor_charge_id' => '', 'meta' => [], 'subscription_id' => 3]);
        $this->authorizeNetHas('80060612551', 'capturedPendingSettlement');
        $this->driver->saveResult = DriverResult::failure('Customer profile creation failed.', 'E00100');

        $this->deliver('net.authorize.payment.authcapture.created', '80060612551');

        $this->assertSame('demoteToManual', SystemChargeService::$calls[0][0]);
    }

    public function test_an_approved_held_renewal_is_marked_paid(): void
    {
        $subscription = new Subscription(['id' => 3, 'vendor_customer_id' => '528040951', 'meta' => ['active_payment_method' => [
            'method' => 'waas_authorizenet', 'vendor_method_id' => '540022572', 'customer_ref' => '528040951', 'env' => 'sandbox',
        ]]]);
        $renewal = $this->order(['id' => 8, 'invoice_no' => 'INV-8']);
        $charge = $this->charge([
            'order' => $renewal, 'status' => 'pending', 'subscription_id' => $subscription->id,
            'vendor_charge_id' => '80060612552', 'meta' => [RenewalCharge::HELD_META => 1],
        ]);
        $this->authorizeNetHas('80060612552', 'capturedPendingSettlement', ['customer_ref' => '528040951', 'invoice_number' => 'INV-8']);

        $this->deliver('net.authorize.payment.fraud.approved', '80060612552');

        $this->assertSame('succeeded', $charge->status);
        $this->assertArrayNotHasKey(RenewalCharge::HELD_META, $charge->meta);
        $this->assertCount(1, StatusHelper::$synced);
    }

    public function test_an_unanswered_renewal_found_by_its_saved_card_is_marked_paid(): void
    {
        new Subscription(['id' => 3, 'meta' => ['active_payment_method' => [
            'method' => 'waas_authorizenet', 'vendor_method_id' => '540022572', 'customer_ref' => '528040951', 'env' => 'sandbox',
        ]]]);
        $charge = $this->charge([
            'order' => $this->order(['id' => 8, 'invoice_no' => 'INV-8']), 'status' => 'pending', 'subscription_id' => 3,
            'vendor_charge_id' => '', 'meta' => [RenewalCharge::IN_FLIGHT_META => ['invoice_number' => 'INV-8']],
        ]);
        $this->authorizeNetHas('80060612553', 'capturedPendingSettlement', ['customer_ref' => '528040951', 'invoice_number' => 'INV-8', 'customer_email' => '']);

        $this->deliver('net.authorize.payment.authcapture.created', '80060612553');

        $this->assertSame('succeeded', $charge->status);
        $this->assertSame('80060612553', $charge->vendor_charge_id);
    }

    public function test_a_renewal_charged_to_another_customers_card_is_not_matched(): void
    {
        new Subscription(['id' => 3, 'meta' => ['active_payment_method' => [
            'method' => 'waas_authorizenet', 'vendor_method_id' => '540022572', 'customer_ref' => '528040951', 'env' => 'sandbox',
        ]]]);
        $charge = $this->charge([
            'order' => $this->order(['id' => 8, 'invoice_no' => 'INV-8']), 'status' => 'pending', 'subscription_id' => 3,
            'vendor_charge_id' => '', 'meta' => [RenewalCharge::IN_FLIGHT_META => ['invoice_number' => 'INV-8']],
        ]);
        $this->authorizeNetHas('80060612553', 'capturedPendingSettlement', ['customer_ref' => '111', 'invoice_number' => 'INV-8']);

        $this->deliver('net.authorize.payment.authcapture.created', '80060612553');

        $this->assertSame('pending', $charge->status);
    }

    public function test_a_declined_held_renewal_runs_fluentcarts_renewal_check_now(): void
    {
        new Subscription(['id' => 3]);
        $this->charge([
            'order' => $this->order(['id' => 8, 'invoice_no' => 'INV-8']), 'status' => 'pending', 'subscription_id' => 3,
            'vendor_charge_id' => '80060612552', 'meta' => [RenewalCharge::HELD_META => 1],
        ]);
        $this->authorizeNetHas('80060612552', 'declined');
        Actions\expectDone(WebhookSync::RECONCILE_HOOK)->once()->with(8);

        $result = $this->deliver('net.authorize.payment.fraud.declined', '80060612552');

        $this->assertSame(200, $result['status']);
    }

    public function test_a_declined_held_checkout_charge_leaves_the_order_unpaid(): void
    {
        $charge = $this->charge(['status' => 'pending', 'vendor_charge_id' => '', 'meta' => []]);
        $this->authorizeNetHas('80060612551', 'declined');

        $this->deliver('net.authorize.payment.fraud.declined', '80060612551');

        $this->assertSame('pending', $charge->status);
        $this->assertSame('Authorize.Net charge not completed', end($this->logs)[0]);
    }

    public function test_a_charge_still_held_changes_nothing(): void
    {
        $charge = $this->charge(['status' => 'pending', 'vendor_charge_id' => '', 'meta' => []]);
        $this->authorizeNetHas('80060612551', 'FDSPendingReview');

        $this->deliver('net.authorize.payment.authcapture.created', '80060612551');

        $this->assertSame('pending', $charge->status);
        $this->assertSame([], $this->logs);
    }

    public function test_an_unknown_transaction_is_acknowledged(): void
    {
        $this->driver->statusResult = DriverResult::failure('The record cannot be found.', 'E00040');

        $this->assertSame(200, $this->deliver('net.authorize.payment.authcapture.created', '1')['status']);
    }

    public function test_when_authorize_net_cannot_be_reached_the_delivery_is_retried(): void
    {
        $this->driver->statusResult = DriverResult::failure('Could not reach Authorize.Net: timed out', 'http_error');

        $this->assertSame(503, $this->deliver('net.authorize.payment.refund.created', '80060612600')['status']);
    }
}

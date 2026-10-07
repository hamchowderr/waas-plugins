<?php

namespace Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Modules\Subscriptions\Services\SystemChargeService;
use FluentCart\App\Services\Payments\PaymentInstance;
use WaaSPayments\Driver\DriverResult;
use WaaSPayments\Driver\RecurringGatewayDriver;
use WaaSPayments\Gateway\AuthorizeNetGateway;
use WaaSPayments\Gateway\AuthorizeNetSettings;
use WaaSPayments\Gateway\CardUpdate;
use WaaSPayments\Gateway\PaymentConfirmation;
use WaaSPayments\Gateway\RenewalCharge;
use WaaSPayments\Gateway\SavedCard;

/**
 * Auto-charged ("system") subscriptions: the card is saved on the first payment, renewals are
 * charged to it when FluentCart's SystemChargeService asks, and the customer can replace it.
 */
class SubscriptionBillingTest extends TestCase
{
    private const TOKEN = 'COMMON.ACCEPT.INAPP.PAYMENT';

    private FakeDriver $driver;
    private AuthorizeNetGateway $gateway;
    private array $logs = [];
    private $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        StatusHelper::$synced = [];
        SystemChargeService::$calls = [];
        SystemChargeService::$chargeNowResult = ['status' => 'paid', 'message' => 'Paid.'];
        Subscription::$table = [];
        Order::$table = [];
        CustomerResource::$current = null;
        BaseGatewaySettings::$allSettings = [(new AuthorizeNetSettings())->methodHandler => ['sandbox' => 'yes']];

        $this->driver = new FakeDriver();
        $this->gateway = new AuthorizeNetGateway(fn () => $this->driver);

        Functions\when('fluent_cart_add_log')->alias(function ($title, $content, $status) {
            $this->logs[] = [$title, $content, $status];
        });

        // MySQL named locks (PaymentConfirmation::lock).
        $this->wpdb = new class {
            public $lockAvailable = true;
            public array $queries = [];

            public function prepare($query, ...$args)
            {
                return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $args);
            }

            public function get_var($query)
            {
                $this->queries[] = $query;
                return $this->lockAvailable ? '1' : '0';
            }

            public function query($query)
            {
                $this->queries[] = $query;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function order(array $overrides = []): object
    {
        return (object) array_merge([
            'id'              => 7,
            'invoice_no'      => '',
            'email'           => 'buyer@example.com',
            'customer_id'     => 42,
            'customer'        => (object) ['email' => 'buyer@example.com'],
            'billing_address' => (object) [
                'first_name' => 'Test', 'last_name' => 'Buyer', 'address_1' => '1 Main St',
                'city' => 'Bellevue', 'state' => 'WA', 'postcode' => '98004', 'country' => 'US',
            ],
        ], $overrides);
    }

    private function subscription(array $overrides = []): Subscription
    {
        return new Subscription(array_merge(['id' => 3, 'uuid' => 'sub-uuid', 'order' => $this->order()], $overrides));
    }

    private function withSavedCard(Subscription $subscription, string $env = 'sandbox'): Subscription
    {
        $subscription->vendor_customer_id = '528040951';
        $subscription->meta['active_payment_method'] = [
            'method'           => 'waas_authorizenet',
            'type'             => 'card',
            'vendor_method_id' => '540022572',
            'customer_ref'     => '528040951',
            'network_trans_id' => 'NETWORK-ID',
            'env'              => $env,
            'details'          => ['type' => 'card', 'brand' => 'Visa', 'last_4' => '1111', 'payment_method_id' => '540022572'],
        ];

        return $subscription;
    }

    private function transaction(array $overrides = []): OrderTransaction
    {
        $transaction = new OrderTransaction(array_merge([
            'uuid'            => 'trx-abc',
            'status'          => 'pending',
            'total'           => 4900,
            'payment_method'  => 'waas_authorizenet',
            'subscription_id' => 3,
            'meta'            => [],
            'order'           => $this->order(),
        ], $overrides));
        $transaction->save();
        $transaction->saved = 0;

        return $transaction;
    }

    private function renewal(Subscription $subscription, ?OrderTransaction $transaction = null): PaymentInstance
    {
        $order = $this->order(['id' => 8, 'invoice_no' => 'INV-8', 'type' => 'renewal']);
        $transaction = $transaction ?: $this->transaction(['order' => $order]);

        return new PaymentInstance($order, $transaction, $subscription);
    }

    // Features and checkout

    public function test_it_offers_auto_charged_subscriptions_and_card_updates_but_no_gateway_billed_ones(): void
    {
        $this->assertTrue($this->gateway->has('system_subscription'));
        $this->assertTrue($this->gateway->has('card_update'));
        $this->assertFalse($this->gateway->has('subscriptions'), 'no vendor subscriptions: Authorize.Net does not run the schedule');
        $this->assertTrue($this->gateway->supportsSetupWithoutCharge());
    }

    public function test_store_billed_subscriptions_reach_the_card_form_and_gateway_billed_ones_are_refused(): void
    {
        foreach (['system', 'manual'] as $method) {
            $result = $this->gateway->makePaymentFromPaymentInstance(
                new PaymentInstance((object) ['id' => 7], (object) ['uuid' => 'trx-abc'], (object) ['collection_method' => $method])
            );
            $this->assertSame('success', $result['status'], $method);
        }

        $refused = $this->gateway->makePaymentFromPaymentInstance(
            new PaymentInstance((object) ['id' => 7], (object) ['uuid' => 'trx-abc'], (object) ['collection_method' => 'automatic'])
        );
        $this->assertInstanceOf(\WP_Error::class, $refused);
        $this->assertSame('waas_payments_no_subscriptions', $refused->get_error_code());
    }

    // First payment

    public function test_the_first_payment_saves_the_card_for_renewals(): void
    {
        $subscription = $this->subscription();
        $this->driver->chargeSavesCard();
        $transaction = $this->transaction();

        $result = (new PaymentConfirmation($this->gateway))->confirm($transaction, self::TOKEN, 'nonce-value');

        $this->assertSame(200, $result['status']);
        $charge = $this->driver->charges[0];
        $this->assertTrue($charge->savePaymentMethod);
        $this->assertSame('', $charge->customerRef, 'a new customer gets a new profile');

        $this->assertSame('528040951', $subscription->vendor_customer_id);
        $this->assertSame([
            'method'           => 'waas_authorizenet',
            'type'             => 'card',
            'vendor_method_id' => '540022572',
            'customer_ref'     => '528040951',
            'network_trans_id' => 'NETWORK-ID',
            'env'              => 'sandbox',
            'details'          => ['type' => 'card', 'brand' => 'Visa', 'last_4' => '1111', 'payment_method_id' => '540022572'],
        ], $subscription->meta['active_payment_method']);
        $this->assertSame('Card saved for renewals', $subscription->logs[0][0]);

        $this->assertSame('succeeded', $transaction->status);
        $this->assertCount(1, StatusHelper::$synced, 'the order is synced after the card is saved, so activation sees it');
        $this->assertSame([], SystemChargeService::$calls);
    }

    public function test_a_manual_billing_subscription_saves_no_card(): void
    {
        $subscription = $this->subscription(['collection_method' => 'manual']);

        (new PaymentConfirmation($this->gateway))->confirm($this->transaction(), self::TOKEN, 'nonce-value');

        $this->assertFalse($this->driver->charges[0]->savePaymentMethod);
        $this->assertArrayNotHasKey('active_payment_method', $subscription->meta);
    }

    public function test_a_card_that_could_not_be_saved_moves_the_subscription_to_manual_invoices(): void
    {
        $subscription = $this->subscription();
        $this->driver->chargeResult->data['save_error'] = 'The record cannot be found.';
        $transaction = $this->transaction();

        $result = (new PaymentConfirmation($this->gateway))->confirm($transaction, self::TOKEN, 'nonce-value');

        $this->assertSame(200, $result['status'], 'the customer was charged');
        $this->assertSame('succeeded', $transaction->status);
        $this->assertSame('demoteToManual', SystemChargeService::$calls[0][0]);
        $this->assertStringContainsString('The record cannot be found.', SystemChargeService::$calls[0][2]);
    }

    public function test_paying_an_invoice_with_a_new_card_replaces_the_saved_one(): void
    {
        $subscription = $this->withSavedCard($this->subscription());
        $this->driver->chargeSavesCard('528040951', '540022580');
        $transaction = $this->transaction();

        (new PaymentConfirmation($this->gateway))->confirm($transaction, self::TOKEN, 'nonce-value');

        $this->assertSame('528040951', $this->driver->charges[0]->customerRef, 'added to the existing profile');
        $this->assertSame('540022580', $subscription->meta['active_payment_method']['vendor_method_id']);
        $this->assertSame([['528040951', '540022572']], $this->driver->deleted, 'the old card is removed at Authorize.Net');
        $this->assertSame(['clearFailureState', 3], SystemChargeService::$calls[0]);
    }

    public function test_paying_an_invoice_with_a_card_that_could_not_be_saved_keeps_the_saved_one(): void
    {
        $subscription = $this->withSavedCard($this->subscription());
        $this->driver->chargeResult->data['save_error'] = 'timed out';

        (new PaymentConfirmation($this->gateway))->confirm($this->transaction(), self::TOKEN, 'nonce-value');

        $this->assertSame('540022572', $subscription->meta['active_payment_method']['vendor_method_id']);
        $this->assertSame([], SystemChargeService::$calls, 'still auto-charged');
        $this->assertSame('warning', end($subscription->logs)[2]);
    }

    public function test_nothing_to_pay_today_saves_the_card_without_a_charge(): void
    {
        $subscription = $this->subscription();
        $transaction = $this->transaction(['total' => 0]);

        $result = (new PaymentConfirmation($this->gateway))->confirm($transaction, self::TOKEN, 'nonce-value');

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $this->driver->charges);
        $save = $this->driver->savedMethods[0];
        $this->assertSame('nonce-value', $save->tokenValue);
        $this->assertSame(['first_name' => 'Test', 'last_name' => 'Buyer', 'address' => '1 Main St', 'city' => 'Bellevue', 'state' => 'WA', 'zip' => '98004', 'country' => 'US'], $save->billing);
        $this->assertSame('succeeded', $transaction->status);
        $this->assertSame('', $transaction->vendor_charge_id);
        $this->assertSame('540022574', $subscription->meta['active_payment_method']['vendor_method_id']);
    }

    // Renewals

    public function test_a_renewal_is_charged_to_the_saved_card_and_confirmed(): void
    {
        $subscription = $this->withSavedCard($this->subscription());
        $instance = $this->renewal($subscription);

        $result = $this->gateway->chargeRenewal($instance, ['attempt' => 2]);

        $this->assertTrue($result);
        $request = $this->driver->savedCharges[0];
        $this->assertSame(4900, $request->amount);
        $this->assertSame('528040951', $request->customerRef);
        $this->assertSame('540022572', $request->methodRef);
        $this->assertSame('INV-8', $request->invoiceNumber);
        $this->assertSame('Peptide Test Store #INV-8', $request->description);
        $this->assertSame('NETWORK-ID', $request->originalNetworkTransactionId);

        $transaction = $instance->transaction;
        $this->assertSame('succeeded', $transaction->status);
        $this->assertSame('80060612550', $transaction->vendor_charge_id);
        $this->assertSame('sandbox', $transaction->meta['authorizenet_env']);
        $this->assertArrayNotHasKey(RenewalCharge::IN_FLIGHT_META, $transaction->meta);
        $this->assertCount(1, StatusHelper::$synced);
        $this->assertStringContainsString('GET_LOCK', $this->wpdb->queries[0]);
        $this->assertStringContainsString('RELEASE_LOCK', end($this->wpdb->queries));
    }

    public function test_a_declined_renewal_is_an_error_for_fluentcart_to_retry(): void
    {
        $this->driver->savedChargeResult = DriverResult::failure('This transaction has been declined.', '2', [], '80060612555');
        $instance = $this->renewal($this->withSavedCard($this->subscription()));

        $result = $this->gateway->chargeRenewal($instance);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('This transaction has been declined.', $result->get_error_message());
        $this->assertSame('pending', $instance->transaction->status);
        $this->assertArrayNotHasKey(RenewalCharge::IN_FLIGHT_META, $instance->transaction->meta);
        $this->assertSame([], StatusHelper::$synced);
        $this->assertSame('error', end($this->logs)[2]);
    }

    public function test_an_unanswered_renewal_is_processing_and_is_never_charged_twice(): void
    {
        $this->driver->savedChargeResult = DriverResult::failure('Could not reach Authorize.Net: timed out', 'http_error');
        $instance = $this->renewal($this->withSavedCard($this->subscription()));

        $this->assertSame('processing', $this->gateway->chargeRenewal($instance));
        $marker = $instance->transaction->meta[RenewalCharge::IN_FLIGHT_META];
        $this->assertSame(['invoice_number' => 'INV-8', 'amount' => 4900, 'attempt' => 1], array_diff_key($marker, ['sent_at' => 1]));

        // Called again (an admin's "charge now", a retry): it looks the charge up instead.
        $this->driver->findResult = DriverResult::success('80060612560', 'capturedPendingSettlement', ['state' => RecurringGatewayDriver::STATE_PAID, 'card_last4' => '1111', 'card_brand' => 'Visa']);
        $this->assertTrue($this->gateway->chargeRenewal($instance));

        $this->assertCount(1, $this->driver->savedCharges, 'charged once');
        $this->assertSame([['528040951', 'INV-8', 4900]], $this->driver->lookups);
        $this->assertSame('succeeded', $instance->transaction->status);
        $this->assertSame('80060612560', $instance->transaction->vendor_charge_id);
    }

    public function test_reconcile_of_an_unanswered_charge_that_never_arrived_allows_a_retry(): void
    {
        $instance = $this->renewal($this->withSavedCard($this->subscription()));
        $instance->transaction->meta = [RenewalCharge::IN_FLIGHT_META => ['invoice_number' => 'INV-8', 'amount' => 4900]];
        $instance->transaction->save();

        $result = $this->gateway->reconcileRenewalCharge($instance);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('charge_not_found', $result->get_error_code());
        $this->assertArrayNotHasKey(RenewalCharge::IN_FLIGHT_META, $instance->transaction->meta);
        $this->assertSame([], $this->driver->savedCharges, 'reconcile never charges');
    }

    public function test_reconcile_while_authorize_net_is_unreachable_asks_again_later(): void
    {
        $this->driver->findResult = DriverResult::failure('Could not reach Authorize.Net', 'http_error');
        $instance = $this->renewal($this->withSavedCard($this->subscription()));
        $instance->transaction->meta = [RenewalCharge::IN_FLIGHT_META => ['invoice_number' => 'INV-8', 'amount' => 4900]];
        $instance->transaction->save();

        $this->assertSame('processing', $this->gateway->reconcileRenewalCharge($instance));
        $this->assertArrayHasKey(RenewalCharge::IN_FLIGHT_META, $instance->transaction->meta);
    }

    public function test_a_renewal_held_for_review_settles_when_the_merchant_approves_it(): void
    {
        $this->driver->savedChargeResult = DriverResult::failure('The payment is being held for review by the store.', 'held_for_review', [], '80060612570');
        $instance = $this->renewal($this->withSavedCard($this->subscription()));

        $this->assertSame('processing', $this->gateway->chargeRenewal($instance));
        $this->assertSame('80060612570', $instance->transaction->vendor_charge_id);
        $this->assertSame(1, $instance->transaction->meta[RenewalCharge::HELD_META]);

        $this->driver->statusResult = DriverResult::success('80060612570', 'FDSPendingReview', ['state' => RecurringGatewayDriver::STATE_PENDING]);
        $this->assertSame('processing', $this->gateway->reconcileRenewalCharge($instance));

        $this->driver->statusResult = DriverResult::success('80060612570', 'approvedReview', ['state' => RecurringGatewayDriver::STATE_PAID, 'card_last4' => '1111', 'card_brand' => 'Visa']);
        $this->assertTrue($this->gateway->reconcileRenewalCharge($instance));
        $this->assertSame(['80060612570', '80060612570'], $this->driver->statusChecks);
        $this->assertSame('succeeded', $instance->transaction->status);
        $this->assertArrayNotHasKey(RenewalCharge::HELD_META, $instance->transaction->meta);
    }

    public function test_a_held_renewal_the_merchant_declines_fails(): void
    {
        $instance = $this->renewal($this->withSavedCard($this->subscription()));
        $instance->transaction->meta = [RenewalCharge::HELD_META => 1];
        $instance->transaction->vendor_charge_id = '80060612570';
        $instance->transaction->save();
        $this->driver->statusResult = DriverResult::success('80060612570', 'declined', ['state' => RecurringGatewayDriver::STATE_FAILED, 'reason' => 'Declined by the merchant']);

        $result = $this->gateway->reconcileRenewalCharge($instance);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString('Declined by the merchant', $result->get_error_message());
        $this->assertArrayNotHasKey(RenewalCharge::HELD_META, $instance->transaction->meta);
    }

    public function test_a_renewal_already_paid_is_not_charged_again(): void
    {
        $subscription = $this->withSavedCard($this->subscription());
        $instance = $this->renewal($subscription, $this->transaction(['status' => 'succeeded', 'order' => $this->order(['id' => 8])]));

        $this->assertTrue($this->gateway->chargeRenewal($instance));
        $this->assertSame([], $this->driver->savedCharges);
    }

    public function test_a_renewal_being_paid_by_the_customer_right_now_is_processing(): void
    {
        $this->wpdb->lockAvailable = false;
        $instance = $this->renewal($this->withSavedCard($this->subscription()));

        $this->assertSame('processing', $this->gateway->chargeRenewal($instance));
        $this->assertSame([], $this->driver->savedCharges);
    }

    public function test_a_renewal_without_a_saved_card_fails(): void
    {
        $result = $this->gateway->chargeRenewal($this->renewal($this->subscription()));

        $this->assertSame('missing_token', $result->get_error_code());
    }

    public function test_a_sandbox_card_is_never_charged_with_live_credentials(): void
    {
        BaseGatewaySettings::$allSettings = [(new AuthorizeNetSettings())->methodHandler => ['sandbox' => 'no']];
        $gateway = new AuthorizeNetGateway(fn () => $this->driver);

        $result = $gateway->chargeRenewal($this->renewal($this->withSavedCard($this->subscription(), 'sandbox')));

        $this->assertSame('environment_mismatch', $result->get_error_code());
        $this->assertSame([], $this->driver->savedCharges);
    }

    // Card update

    public function test_the_portal_links_an_auto_charged_subscription_to_the_card_update_page(): void
    {
        Functions\when('home_url')->justReturn('https://store.test/');
        Functions\when('add_query_arg')->alias(fn ($key, $value, $url) => $url . '?' . $key . '=' . $value);

        $subscription = $this->withSavedCard($this->subscription());
        $this->assertSame('https://store.test/?waas_payments_card_update=sub-uuid', $this->gateway->getSubscriptionUrl('', ['subscription' => $subscription]));

        $subscription->collection_method = 'manual';
        $this->assertSame('', $this->gateway->getSubscriptionUrl('', ['subscription' => $subscription]));
    }

    public function test_a_card_update_saves_the_new_card_and_charges_an_overdue_renewal(): void
    {
        $subscription = $this->withSavedCard($this->subscription(['status' => 'past_due']));
        new Order(['id' => 8, 'parent_id' => 7, 'type' => 'renewal', 'payment_status' => 'pending']);
        new Order(['id' => 9, 'parent_id' => 99, 'type' => 'renewal', 'payment_status' => 'pending']);
        Actions\expectDone('fluent_cart/subscriptions/system_payment_method_updated')->once();

        $result = (new CardUpdate($this->gateway))->update($subscription, self::TOKEN, 'nonce-value', 5);

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('charged to the new card', $result['body']['message']);

        $save = $this->driver->savedMethods[0];
        $this->assertSame('528040951', $save->customerRef);
        $this->assertSame('42', $save->customerId);
        $this->assertSame('Bellevue', $save->billing['city']);

        $this->assertSame('540022574', $subscription->meta['active_payment_method']['vendor_method_id']);
        $this->assertSame('', $subscription->meta['active_payment_method']['network_trans_id']);
        $this->assertSame([['528040951', '540022572']], $this->driver->deleted);
        $this->assertSame([['clearFailureState', 3], ['chargeNow', 8, 3, 5]], SystemChargeService::$calls);
    }

    public function test_a_card_still_used_by_another_subscription_is_not_deleted(): void
    {
        // Authorize.Net keeps one payment profile per card on a customer profile, so a customer's two
        // subscriptions on the same card share it.
        $subscription = $this->withSavedCard($this->subscription());
        $this->withSavedCard(new Subscription(['id' => 4, 'uuid' => 'other-sub', 'order' => $this->order()]));
        Actions\expectDone('fluent_cart/subscriptions/system_payment_method_updated')->once();

        $result = (new CardUpdate($this->gateway))->update($subscription, self::TOKEN, 'nonce-value');

        $this->assertSame(200, $result['status']);
        $this->assertSame('540022574', $subscription->meta['active_payment_method']['vendor_method_id']);
        $this->assertSame([], $this->driver->deleted, 'subscription 4 still renews on it');
    }

    public function test_a_card_update_with_nothing_overdue_charges_nothing(): void
    {
        $subscription = $this->withSavedCard($this->subscription());
        new Order(['id' => 8, 'parent_id' => 7, 'type' => 'renewal', 'payment_status' => 'scheduled']);
        Actions\expectDone('fluent_cart/subscriptions/system_payment_method_updated')->once();

        $result = (new CardUpdate($this->gateway))->update($subscription, self::TOKEN, 'nonce-value');

        $this->assertSame(200, $result['status']);
        $this->assertSame([['clearFailureState', 3]], SystemChargeService::$calls);
    }

    public function test_a_card_update_that_authorize_net_rejects_changes_nothing(): void
    {
        $subscription = $this->withSavedCard($this->subscription());
        $this->driver->saveResult = DriverResult::failure('This transaction has been declined.', '2');

        $result = (new CardUpdate($this->gateway))->update($subscription, self::TOKEN, 'nonce-value');

        $this->assertSame(422, $result['status']);
        $this->assertSame('This transaction has been declined.', $result['body']['message']);
        $this->assertSame('540022572', SavedCard::read($subscription)['method_ref']);
        $this->assertSame([], $this->driver->deleted);
        $this->assertSame([], SystemChargeService::$calls);
    }

    public function test_a_card_update_needs_a_token_and_an_auto_charged_subscription(): void
    {
        $update = new CardUpdate($this->gateway);

        $this->assertSame(400, $update->update($this->withSavedCard($this->subscription()), 'OTHER', 'x')['status']);
        $this->assertSame(409, $update->update($this->subscription(), self::TOKEN, 'x')['status'], 'no saved card');
        $this->assertSame([], $this->driver->savedMethods);
    }
}

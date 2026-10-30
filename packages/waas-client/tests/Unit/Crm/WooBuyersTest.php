<?php

namespace Tests\Unit\Crm;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSClient\Crm\WooBuyers;

class WooBuyersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('is_email')->alias(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false);
    }

    private static function order(array $values): array
    {
        return $values + [
            'id' => 1, 'customer_id' => 0, 'status' => 'completed', 'email' => 'guest@example.com', 'first_name' => 'Gus', 'last_name' => 'Guest',
            'phone' => '', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'postcode' => '', 'country' => '', 'ip' => '',
            'date' => '2026-01-01 10:00:00', 'total' => 10.0, 'refunded' => 0.0, 'optin' => '', 'items' => [['product_id' => 5, 'name' => 'Mug']],
        ];
    }

    public function test_registered_buyers_are_found_by_their_account_email_and_guests_by_billing_email(): void
    {
        $result = WooBuyers::from_orders([
            self::order(['id' => 1, 'customer_id' => 7, 'email' => 'carol.work@example.com', 'first_name' => 'Carol', 'city' => 'Leeds', 'total' => 20.0, 'items' => [['product_id' => 5, 'name' => 'Mug']]]),
            self::order(['id' => 2, 'customer_id' => 7, 'email' => 'carol@example.com', 'first_name' => 'Caroline', 'city' => 'York', 'date' => '2026-02-01 10:00:00', 'total' => 15.5, 'items' => [['product_id' => 6, 'name' => 'Cap'], ['product_id' => 5, 'name' => 'Mug']]]),
            self::order(['id' => 3, 'email' => 'Guest@Example.com', 'total' => 8.0]),
        ], [7 => ['email' => 'Carol@example.com', 'optin' => '']]);

        $this->assertSame(['carol@example.com', 'guest@example.com'], array_column($result['records'], 'email'));
        $carol = $result['records'][0];
        $this->assertSame('Caroline', $carol['first_name']);
        $this->assertSame('York', $carol['city']);
        $this->assertSame('2026-01-01 10:00:00', $carol['created_at']);
        $this->assertSame(['Mug: Past', 'Cap: Past'], $carol['tags']);
        $this->assertSame(['woocommerce_orders' => 2, 'woocommerce_spent' => '35.50'], $carol['custom']);
        $this->assertNull($carol['status']);
        $this->assertSame('customer', $carol['contact_type']);
        $this->assertSame(1, $result['stats']['registered']);
        $this->assertSame(1, $result['stats']['guests']);
        $this->assertSame(['Cap: Past' => 1, 'Mug: Past' => 2], $result['stats']['products']);
    }

    public function test_a_full_refund_gives_no_past_tag_and_a_partial_refund_lowers_the_total(): void
    {
        $result = WooBuyers::from_orders([
            self::order(['id' => 1, 'status' => 'refunded', 'total' => 10.0, 'refunded' => 10.0, 'items' => [['product_id' => 5, 'name' => 'Mug']]]),
            self::order(['id' => 2, 'total' => 30.0, 'refunded' => 5.0, 'items' => [['product_id' => 6, 'name' => 'Cap']]]),
        ], []);

        $guest = $result['records'][0];
        $this->assertSame(['Cap: Past'], $guest['tags']);
        $this->assertSame(['woocommerce_orders' => 1, 'woocommerce_spent' => '25.00'], $guest['custom']);
    }

    public function test_opt_in_records(): void
    {
        $result = WooBuyers::from_orders([
            self::order(['id' => 1, 'email' => 'yes@example.com', 'optin' => '1']),
            self::order(['id' => 2, 'email' => 'no@example.com', 'optin' => '0']),
            self::order(['id' => 3, 'customer_id' => 4, 'email' => 'acct@example.com', 'optin' => '1']),
        ], [4 => ['email' => 'acct@example.com', 'optin' => 'unsubscribed']]);

        $statuses = array_column($result['records'], 'status', 'email');
        $this->assertSame('subscribed', $statuses['yes@example.com']);
        $this->assertNull($statuses['no@example.com']);
        $this->assertSame('unsubscribed', $statuses['acct@example.com'], 'the account\'s own choice wins over an older checkout');
        $this->assertSame(1, $result['stats']['opted_in']);
        $this->assertSame(1, $result['stats']['opted_out']);
    }

    public function test_orders_without_a_valid_email_are_skipped(): void
    {
        $result = WooBuyers::from_orders([self::order(['email' => '']), self::order(['email' => 'not-an-email'])], []);

        $this->assertSame([], $result['records']);
        $this->assertSame(2, $result['stats']['skipped_orders']);
    }

    public function test_optin_values(): void
    {
        $this->assertSame('subscribed', WooBuyers::optin(true));
        $this->assertSame('subscribed', WooBuyers::optin('yes'));
        $this->assertSame('unsubscribed', WooBuyers::optin('unsubscribed'));
        $this->assertNull(WooBuyers::optin('0'));
        $this->assertNull(WooBuyers::optin(''));
        $this->assertNull(WooBuyers::optin(null));
    }
}

<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use AnyPressHost\Commerce\FluentCart\FluentCartAdapter;
use AnyPressHost\Commerce\FluentCart\FluentCartDomains;
use AnyPressHost\Commerce\FluentCart\FluentCartStore;
use AnyPressHost\Core\ProvisioningApi;
use AnyPressHost\Features\StoreJobs;

class FluentCartDomainsTest extends TestCase
{
    private const OWNER_ID = 7;
    private const QUOTE = 9000000001;

    private $store;
    private $api;
    private FluentCartDomains $domains;
    private array $options = [];
    /** @var array<int, array<string, mixed>> order id => meta */
    private array $order_meta = [];
    private array $logs = [];
    private array $scheduled = [];
    private int $user = self::OWNER_ID;
    private string $error_log;
    private $previous_error_log;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('update_option')->alias(function ($name, $value) {
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($name) {
            unset($this->options[$name]);
            return true;
        });
        Functions\when('get_current_user_id')->alias(fn () => $this->user);
        Functions\when('as_schedule_single_action')->alias(function ($timestamp, $hook, $args, $group) {
            $this->scheduled[] = [$hook, $args, $group];
            return 1;
        });

        $this->options[FluentCartDomains::ENABLED_OPTION] = 'on';
        $this->options['anypress_domain_quote_' . self::QUOTE] = [
            'user_id' => self::OWNER_ID,
            'subscription_id' => 900,
            'external_id' => 'ext-900',
            'domain' => 'mybakery.com',
            'contact' => ['firstName' => 'Ada', 'lastName' => 'Baker', 'email' => 'ada@example.com'],
            'first_cents' => 2000,
            'yearly_cents' => 1700,
            'made_at' => time(),
        ];

        $this->store = Mockery::mock(FluentCartStore::class);
        $this->store->allows('get_order_meta')->andReturnUsing(fn ($order, $key) => (string) ($this->order_meta[$order->id][$key] ?? ''));
        $this->store->allows('get_order_meta_value')->andReturnUsing(fn ($order, $key) => $this->order_meta[$order->id][$key] ?? '');
        $this->store->allows('update_order_meta')->andReturnUsing(function ($order, $key, $value) {
            $this->order_meta[$order->id][$key] = $value;
        });
        $this->api = Mockery::mock(ProvisioningApi::class);
        $this->domains = new FluentCartDomains($this->api, new FluentCartAdapter($this->store), $this->store);

        $this->error_log = (string) tempnam(sys_get_temp_dir(), 'anypress-log');
        $this->previous_error_log = ini_set('error_log', $this->error_log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previous_error_log);
        @unlink($this->error_log);
        parent::tearDown();
    }

    private function order(int $id)
    {
        $order = Mockery::mock();
        $order->id = $id;
        $order->allows('addLog')->andReturnUsing(function (...$args) {
            $this->logs[] = $args;
        });

        return $order;
    }

    /** The first order, with the quote it paid for. */
    private function paid_order()
    {
        $order = $this->order(500);
        $this->domains->save_quote_on_order(['cart' => (object) ['cart_data' => [['object_id' => self::QUOTE, 'id' => self::QUOTE]]], 'order' => $order]);

        return $order;
    }

    public function test_the_quote_is_a_yearly_subscription_item_with_the_first_year_as_a_signup_fee(): void
    {
        $item = $this->domains->checkout_item(null, ['item_id' => (string) self::QUOTE, 'quantity' => 1, 'is_custom' => true, 'action' => 'instant_checkout']);

        $this->assertSame('Domain mybakery.com', $item->post_title);
        $this->assertSame(1700, $item->item_price);
        $this->assertTrue($item->is_custom);
        $this->assertSame('subscription', $item->other_info['payment_type']);
        $this->assertSame('yearly', $item->other_info['repeat_interval']);
        $this->assertSame('yes', $item->other_info['manage_setup_fee']);
        $this->assertSame(300, $item->other_info['signup_fee']);
    }

    public function test_only_the_customer_who_made_the_quote_can_pay_it_and_only_while_it_is_fresh(): void
    {
        $args = ['item_id' => self::QUOTE, 'action' => 'instant_checkout'];
        $this->user = 99;
        $this->assertNull($this->domains->checkout_item(null, $args));

        $this->user = self::OWNER_ID;
        $this->options['anypress_domain_quote_' . self::QUOTE]['made_at'] = time() - 2 * 86400;
        $this->assertNull($this->domains->checkout_item(null, $args));
    }

    public function test_other_custom_items_are_left_alone(): void
    {
        $theirs = (object) ['id' => 12];
        $this->assertSame($theirs, $this->domains->checkout_item($theirs, ['item_id' => 12, 'action' => 'instant_checkout']));
        $this->assertNull($this->domains->checkout_item(null, ['item_id' => 9000000077, 'action' => 'instant_checkout']));
    }

    public function test_the_shop_adds_its_own_amount_to_each_year(): void
    {
        $this->assertSame(1500, FluentCartDomains::shop_price(1500));
        $this->options[FluentCartDomains::EXTRA_OPTION] = 5;
        $this->assertSame(2000, FluentCartDomains::shop_price(1500));
    }

    public function test_a_paid_order_registers_the_domain_once_with_the_order_as_reference(): void
    {
        $order = $this->paid_order();
        $this->api->expects('buy_paid_domain')
            ->with('ext-900', 'mybakery.com', ['firstName' => 'Ada', 'lastName' => 'Baker', 'email' => 'ada@example.com'], 'fluentcart-order-500-domain')
            ->once()
            ->andReturn((object) ['status' => 'registering']);

        $this->domains->order_paid(['order' => $order]);
        $this->domains->order_paid(['order' => $order]);

        $this->assertSame('registering', $this->order_meta[500][FluentCartDomains::ORDER_DONE_META]['status']);
        $this->assertSame('Domain ordered', $this->logs[0][0]);
        $this->assertArrayNotHasKey('anypress_domain_quote_' . self::QUOTE, $this->options);
    }

    public function test_orders_without_a_domain_are_ignored(): void
    {
        $this->api->expects('buy_paid_domain')->never();
        $this->domains->order_paid(['order' => $this->order(501)]);
        $this->assertSame([], $this->logs);
    }

    public function test_each_renewal_renews_the_domain_with_the_renewal_order_as_reference(): void
    {
        $main = $this->paid_order();
        $renewal = $this->order(612);
        $this->api->expects('renew_paid_domain')->with('ext-900', 'mybakery.com', 'fluentcart-renewal-612')->once()->andReturn((object) ['status' => 'active']);

        $this->domains->renewed(['order' => $renewal, 'main_order' => $main]);

        $this->assertSame(500, $this->order_meta[612]['_anypress_domain_main_order']);
        $this->assertSame('Domain renewed', $this->logs[0][0]);
    }

    public function test_an_api_outage_is_retried_with_backoff(): void
    {
        $order = $this->paid_order();
        $this->api->expects('buy_paid_domain')->andThrow(new \Exception('Failed: 502 - Bad gateway'));

        $this->domains->order_paid(['order' => $order]);

        $this->assertSame([[FluentCartDomains::RETRY_HOOK, [500], StoreJobs::GROUP]], $this->scheduled);
        $this->assertSame('Domain not done yet', $this->logs[0][0]);
    }

    public function test_a_refused_domain_is_not_retried_and_asks_for_a_refund(): void
    {
        $order = $this->paid_order();
        $this->api->expects('buy_paid_domain')->once()->andThrow(new \Exception('Failed: 409 - {"message":"mybakery.com can\'t be bought: This domain is taken"}'));

        $this->domains->order_paid(['order' => $order]);

        $this->assertSame([], $this->scheduled);
        $this->assertStringContainsString('Refund this order', $this->logs[0][1]);
        $this->assertArrayHasKey(500, $this->options[FluentCartDomains::FAILED_OPTION]);
    }
}

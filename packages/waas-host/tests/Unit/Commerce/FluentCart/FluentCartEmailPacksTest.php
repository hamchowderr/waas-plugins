<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\FluentCart\FluentCartAdapter;
use WaaSHost\Commerce\FluentCart\FluentCartEmailPacks;
use WaaSHost\Commerce\FluentCart\FluentCartStore;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\StoreJobs;

class FluentCartEmailPacksTest extends TestCase
{
    private const OWNER_ID = 7;
    private const CUSTOMER_ID = 30;
    private const PACK = 301;
    private const BIG_PACK = 302;
    private const PLAN = 101;

    private $store;
    private $api;
    private $order;
    private FluentCartEmailPacks $packs;
    /** @var array<int, array<string, mixed>> subscription meta */
    private array $meta;
    /** @var array<int, string> subscription statuses */
    private array $statuses;
    /** @var array<string, mixed> order meta */
    private array $order_meta = [];
    private array $logs = [];
    private array $options = [];
    private array $scheduled = [];
    private string $error_log;
    private $previous_error_log;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('number_format_i18n')->alias(fn ($n) => number_format($n));
        Functions\when('add_query_arg')->alias(fn ($key, $value, $url) => $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . $value);
        Functions\when('get_userdata')->alias(fn ($id) => $id === self::OWNER_ID ? (object) ['ID' => $id, 'user_email' => 'owner@apexresearch.example'] : false);
        Functions\when('get_user_by')->justReturn(false);
        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('update_option')->alias(function ($name, $value) {
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('as_schedule_single_action')->alias(function ($timestamp, $hook, $args, $group) {
            $this->scheduled[] = [$timestamp, $hook, $args, $group];
            return 1;
        });

        $this->meta = [
            900 => [Tenant::TENANT_EXTERNAL_ID_META => 'ext-900', Tenant::DOMAIN_NAME_META => 'apex.sites.example', Tenant::TENANT_STATE => Tenant::READY],
            901 => [Tenant::TENANT_EXTERNAL_ID_META => 'ext-901', Tenant::BASE_DOMAIN_NAME_META => 'second.sites.example', Tenant::TENANT_STATE => Tenant::READY],
            902 => [Tenant::TENANT_STATE => Tenant::QUEUED], // being set up: no store yet
            903 => [Tenant::TENANT_EXTERNAL_ID_META => 'ext-903', Tenant::TENANT_STATE => Tenant::READY], // someone else's
            904 => [Tenant::TENANT_EXTERNAL_ID_META => 'ext-904', Tenant::TENANT_STATE => Tenant::DELETED],
        ];
        $this->statuses = [900 => 'active', 901 => 'active', 902 => 'active', 903 => 'active', 904 => 'canceled'];

        $this->store = Mockery::mock(FluentCartStore::class);
        $this->store->allows('get_subscription_meta')->andReturnUsing(fn ($id, $key) => $this->meta[$id][$key] ?? '');
        $this->store->allows('get_subscription_status')->andReturnUsing(fn ($id) => $this->statuses[$id] ?? '');
        $this->store->allows('get_subscription_customer')->andReturnUsing(fn ($id) => [
            'user_id' => $id === 903 ? 99 : self::OWNER_ID, 'email' => 'owner@apexresearch.example', 'name' => 'Pat', 'country' => 'US',
        ]);
        $this->store->allows('find_user_subscription_ids')->with(self::OWNER_ID, 'owner@apexresearch.example')->andReturn([900, 902, 904])->byDefault();
        $this->store->allows('find_customer_subscription_ids')->with(self::CUSTOMER_ID)->andReturn([900, 902, 904])->byDefault();
        $this->store->allows('get_order_customer')->andReturn(['id' => self::CUSTOMER_ID, 'user_id' => self::OWNER_ID, 'email' => 'owner@apexresearch.example'])->byDefault();
        $this->store->allows('get_email_pack_feed')->andReturnUsing(function ($product_id) {
            if ($product_id === self::PACK) {
                return ['enabled' => 'yes', 'product_type' => 'email_pack', 'emails_per_pack' => '5000'];
            }
            if ($product_id === self::BIG_PACK) {
                return ['enabled' => 'yes', 'product_type' => 'email_pack', 'emails_per_pack' => 50000];
            }
            return null;
        });
        $this->store->allows('get_email_pack_products')->andReturn([
            ['product_id' => self::PACK, 'emails' => 5000, 'title' => '5,000 emails', 'checkout_url' => 'https://storefront.example/?fluent-cart=instant_checkout&item_id=31&quantity=1'],
        ])->byDefault();
        $this->store->allows('get_order_meta')->andReturnUsing(fn ($order, $key) => (string) ($this->order_meta[$key] ?? ''));
        $this->store->allows('get_order_meta_value')->andReturnUsing(fn ($order, $key) => $this->order_meta[$key] ?? '');
        $this->store->allows('update_order_meta')->andReturnUsing(function ($order, $key, $value) {
            $this->order_meta[$key] = $value;
        });
        $this->store->allows('get_order_lines')->andReturn([
            ['id' => 71, 'product_id' => self::PACK, 'quantity' => 1],
        ])->byDefault();

        $this->order = Mockery::mock();
        $this->order->id = 500;
        $this->order->allows('addLog')->andReturnUsing(function (...$args) {
            $this->logs[] = $args;
        });

        $this->api = Mockery::mock(ProvisioningApi::class);
        $this->packs = new FluentCartEmailPacks($this->api, new FluentCartAdapter($this->store), $this->store);

        $this->error_log = (string) tempnam(sys_get_temp_dir(), 'waas-log');
        $this->previous_error_log = ini_set('error_log', $this->error_log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previous_error_log);
        @unlink($this->error_log);
        unset($_GET['fct_cart_hash'], $_GET[FluentCartEmailPacks::QUERY_ARG]);
        parent::tearDown();
    }

    private function choose_website(int $subscription_id): void
    {
        $this->order_meta[FluentCartEmailPacks::ORDER_WEBSITE_META] = $subscription_id;
    }

    private function lines(array $lines): void
    {
        $this->store->allows('get_order_lines')->andReturn($lines);
    }

    public function test_each_website_with_a_store_gets_a_buy_more_emails_link_carrying_its_id(): void
    {
        $links = $this->packs->links(900);

        $this->assertCount(1, $links);
        $this->assertSame('Buy more emails', $links[0]['label']);
        $this->assertSame('https://storefront.example/?fluent-cart=instant_checkout&item_id=31&quantity=1&waas_website=900', $links[0]['url']);
    }

    public function test_several_pack_products_each_get_a_link_named_by_their_emails(): void
    {
        $this->store->allows('get_email_pack_products')->andReturn([
            ['product_id' => self::PACK, 'emails' => 5000, 'title' => 'S', 'checkout_url' => 'https://storefront.example/?fluent-cart=instant_checkout&item_id=31&quantity=1'],
            ['product_id' => self::BIG_PACK, 'emails' => 50000, 'title' => 'L', 'checkout_url' => 'https://storefront.example/?fluent-cart=instant_checkout&item_id=32&quantity=1'],
        ]);

        $this->assertSame(['Buy 5,000 more emails', 'Buy 50,000 more emails'], array_column($this->packs->links(900), 'label'));
    }

    public function test_no_link_without_a_pack_product_or_for_a_website_without_a_store(): void
    {
        $this->assertSame([], $this->packs->links(902), 'still being set up');
        $this->assertSame([], $this->packs->links(904), 'deleted');

        $this->store->allows('get_email_pack_products')->andReturn([]);
        $packs = new FluentCartEmailPacks($this->api, new FluentCartAdapter($this->store), $this->store);
        $this->assertSame([], $packs->links(900));
        $this->assertSame('', $packs->buy_emails_url('', 900));
    }

    public function test_the_account_api_gets_the_link_of_the_smallest_pack(): void
    {
        $this->assertSame('https://storefront.example/?fluent-cart=instant_checkout&item_id=31&quantity=1&waas_website=900', $this->packs->buy_emails_url('', 900));
        $this->assertSame('', $this->packs->buy_emails_url('', 902));
    }

    public function test_the_checkout_page_keeps_the_website_from_the_link_in_the_cart(): void
    {
        Functions\stubs(['sanitize_text_field', 'wp_unslash', 'absint' => fn ($v) => abs((int) $v)]);
        $cart = (object) ['checkout_data' => []];
        $_GET['fct_cart_hash'] = 'hash-1';
        $_GET[FluentCartEmailPacks::QUERY_ARG] = '900';
        $this->store->expects('find_cart_by_hash')->with('hash-1')->andReturn($cart);
        $this->store->expects('set_cart_checkout_value')->with($cart, FluentCartEmailPacks::CART_KEY, 900);

        $this->packs->remember_website();
    }

    public function test_an_instant_checkout_without_a_website_clears_one_left_in_the_reused_cart(): void
    {
        Functions\stubs(['sanitize_text_field', 'wp_unslash', 'absint' => fn ($v) => abs((int) $v)]);
        $cart = (object) ['checkout_data' => [FluentCartEmailPacks::CART_KEY => 900]];
        $_GET['fct_cart_hash'] = 'hash-1';
        $this->store->expects('find_cart_by_hash')->with('hash-1')->andReturn($cart);
        $this->store->expects('set_cart_checkout_value')->with($cart, FluentCartEmailPacks::CART_KEY, null);

        $this->packs->remember_website();
    }

    public function test_pages_without_a_cart_hash_are_left_alone(): void
    {
        $_GET[FluentCartEmailPacks::QUERY_ARG] = '900';
        $this->store->expects('find_cart_by_hash')->never();

        $this->packs->remember_website();
    }

    public function test_the_website_goes_onto_the_order_of_a_cart_with_a_pack(): void
    {
        $cart = (object) [];
        $this->store->allows('get_cart_checkout_value')->with($cart, FluentCartEmailPacks::CART_KEY)->andReturn(900);
        $this->store->allows('get_cart_product_ids')->with($cart)->andReturn([self::PLAN, self::PACK]);

        $this->packs->save_website_on_order(['cart' => $cart, 'order' => $this->order]);

        $this->assertSame(900, $this->order_meta[FluentCartEmailPacks::ORDER_WEBSITE_META]);
    }

    public function test_a_cart_without_a_pack_puts_no_website_on_the_order(): void
    {
        $cart = (object) [];
        $this->store->allows('get_cart_checkout_value')->andReturn(900);
        $this->store->allows('get_cart_product_ids')->with($cart)->andReturn([self::PLAN]);

        $this->packs->save_website_on_order(['cart' => $cart, 'order' => $this->order]);

        $this->assertArrayNotHasKey(FluentCartEmailPacks::ORDER_WEBSITE_META, $this->order_meta);
    }

    public function test_a_paid_order_adds_the_packs_emails_to_the_chosen_website(): void
    {
        $this->choose_website(900);
        $this->api->expects('add_email_packs')->once()->with('ext-900', 5000, 'fluentcart-order-500-71')
            ->andReturn((object) ['tenantId' => 't-900', 'externalId' => 'ext-900', 'emailPacks' => 15000]);

        $this->packs->order_paid(['order' => $this->order]);

        $this->assertCount(1, $this->logs);
        $this->assertSame('Emails added', $this->logs[0][0]);
        $this->assertStringContainsString('5,000 emails added to apex.sites.example', $this->logs[0][1]);
        $this->assertStringContainsString('15,000 bought in packs in all', $this->logs[0][1]);
        $this->assertSame([71], array_keys($this->order_meta[FluentCartEmailPacks::ORDER_DONE_META]));
    }

    public function test_quantity_multiplies_the_emails_and_each_line_has_its_own_reference(): void
    {
        $this->choose_website(900);
        $this->lines([
            ['id' => 71, 'product_id' => self::PACK, 'quantity' => 3],
            ['id' => 72, 'product_id' => self::PLAN, 'quantity' => 1],
            ['id' => 73, 'product_id' => self::BIG_PACK, 'quantity' => 2],
        ]);
        $this->api->expects('add_email_packs')->once()->with('ext-900', 15000, 'fluentcart-order-500-71')->andReturn((object) ['emailPacks' => 15000]);
        $this->api->expects('add_email_packs')->once()->with('ext-900', 100000, 'fluentcart-order-500-73')->andReturn((object) ['emailPacks' => 115000]);

        $this->packs->order_paid(['order' => $this->order]);

        $this->assertCount(2, $this->logs);
    }

    public function test_an_order_without_packs_is_left_alone(): void
    {
        $this->lines([['id' => 72, 'product_id' => self::PLAN, 'quantity' => 1]]);
        $this->api->expects('add_email_packs')->never();

        $this->packs->order_paid(['order' => $this->order]);

        $this->assertSame([], $this->logs);
    }

    public function test_lines_already_added_are_not_sent_again(): void
    {
        $this->choose_website(900);
        $this->lines([
            ['id' => 71, 'product_id' => self::PACK, 'quantity' => 1],
            ['id' => 73, 'product_id' => self::BIG_PACK, 'quantity' => 1],
        ]);
        $this->order_meta[FluentCartEmailPacks::ORDER_DONE_META] = [71 => ['subscription_id' => 900, 'emails' => 5000, 'at' => 1]];
        $this->api->expects('add_email_packs')->once()->with('ext-900', 50000, 'fluentcart-order-500-73')->andReturn((object) ['emailPacks' => 55000]);

        $this->packs->order_paid(['order' => $this->order]);

        $this->assertSame([71, 73], array_keys($this->order_meta[FluentCartEmailPacks::ORDER_DONE_META]));
    }

    public function test_a_website_that_is_not_the_customers_gets_nothing(): void
    {
        $this->choose_website(903);
        $this->api->expects('add_email_packs')->never();

        $this->packs->order_paid(['order' => $this->order]);

        $this->assertCount(1, $this->logs);
        $this->assertSame('Emails not added', $this->logs[0][0]);
        $this->assertStringContainsString('subscription #903) is not this customer\'s', $this->logs[0][1]);
        $this->assertSame('error', $this->logs[0][2]);
    }

    public function test_a_chosen_website_without_a_store_gets_nothing(): void
    {
        $this->choose_website(904);
        $this->api->expects('add_email_packs')->never();

        $this->packs->order_paid(['order' => $this->order]);

        $this->assertStringContainsString('has no live website', $this->logs[0][1]);
    }

    public function test_a_guest_order_is_matched_by_its_fluentcart_customer(): void
    {
        $this->store->allows('get_order_customer')->andReturn(['id' => self::CUSTOMER_ID, 'user_id' => 0, 'email' => 'guest@example.com']);
        $this->choose_website(900);
        $this->api->expects('add_email_packs')->once()->with('ext-900', 5000, 'fluentcart-order-500-71')->andReturn((object) ['emailPacks' => 5000]);

        $this->packs->order_paid(['order' => $this->order]);
    }

    public function test_without_a_chosen_website_the_customers_only_live_website_gets_the_emails(): void
    {
        // 902 has no store yet and 904 was deleted, so 900 is the only live website.
        $this->api->expects('add_email_packs')->once()->with('ext-900', 5000, 'fluentcart-order-500-71')->andReturn((object) ['emailPacks' => 5000]);

        $this->packs->order_paid(['order' => $this->order]);

        $this->assertSame('Emails added', $this->logs[0][0]);
    }

    public function test_without_a_chosen_website_an_inactive_subscription_is_not_live(): void
    {
        $this->statuses[900] = 'canceled';
        $this->api->expects('add_email_packs')->never();

        $this->packs->order_paid(['order' => $this->order]);

        $this->assertStringContainsString('has no live website', $this->logs[0][1]);
    }

    public function test_without_a_chosen_website_and_several_live_websites_the_shop_owner_decides(): void
    {
        $this->store->allows('find_user_subscription_ids')->andReturn([900, 901]);
        $this->store->allows('find_customer_subscription_ids')->andReturn([900, 901]);
        $this->api->expects('add_email_packs')->never();

        $this->packs->order_paid(['order' => $this->order]);

        $this->assertCount(1, $this->logs);
        $this->assertSame('Emails not added', $this->logs[0][0]);
        $this->assertStringContainsString('the customer has 2 live websites', $this->logs[0][1]);
    }

    public function test_an_api_failure_is_noted_logged_and_retried_with_backoff(): void
    {
        $this->choose_website(900);
        $this->api->expects('add_email_packs')->andThrow(new \Exception('Failed: 503 - unavailable'));

        $this->packs->order_paid(['order' => $this->order]);

        $this->assertSame('Emails not added yet', $this->logs[0][0]);
        $this->assertStringContainsString('503 - unavailable', $this->logs[0][1]);
        $this->assertSame('error', $this->logs[0][2]);
        $this->assertSame(1, $this->order_meta[FluentCartEmailPacks::ORDER_ATTEMPTS_META]);
        $this->assertArrayNotHasKey(FluentCartEmailPacks::ORDER_DONE_META, $this->order_meta);
        $this->assertCount(1, $this->scheduled);
        [$timestamp, $hook, $args, $group] = $this->scheduled[0];
        $this->assertEqualsWithDelta(time() + StoreJobs::RETRY_DELAYS[0], $timestamp, 5);
        $this->assertSame([FluentCartEmailPacks::RETRY_HOOK, [500], StoreJobs::GROUP], [$hook, $args, $group]);
        $this->assertStringContainsString('adding email packs for order 500', (string) file_get_contents($this->error_log));
    }

    public function test_the_retry_sends_the_same_reference(): void
    {
        $this->choose_website(900);
        $this->order_meta[FluentCartEmailPacks::ORDER_ATTEMPTS_META] = 2;
        $this->store->expects('find_order')->with(500)->andReturn($this->order);
        $this->api->expects('add_email_packs')->once()->with('ext-900', 5000, 'fluentcart-order-500-71')->andReturn((object) ['emailPacks' => 5000]);

        $this->packs->retry(500);

        $this->assertSame(0, $this->order_meta[FluentCartEmailPacks::ORDER_ATTEMPTS_META]);
    }

    public function test_after_the_last_retry_the_order_is_listed_for_the_admin(): void
    {
        $this->choose_website(900);
        $this->order_meta[FluentCartEmailPacks::ORDER_ATTEMPTS_META] = count(StoreJobs::RETRY_DELAYS);
        $this->api->expects('add_email_packs')->andThrow(new \Exception('Failed: 500 - boom'));
        Actions\expectDone('waas_email_packs_failed')->once()->with(500, 900, 'Failed: 500 - boom');

        $this->packs->order_paid(['order' => $this->order]);

        $this->assertSame([], $this->scheduled);
        $this->assertSame('Failed: 500 - boom', $this->options[FluentCartEmailPacks::FAILED_OPTION][500]['error']);
        $this->assertStringContainsString('Use Retry', $this->logs[0][1]);
    }

    public function test_a_success_takes_the_order_off_the_admin_list(): void
    {
        $this->choose_website(900);
        $this->options[FluentCartEmailPacks::FAILED_OPTION] = [500 => ['error' => 'x', 'attempts' => 6, 'at' => 1], 501 => ['error' => 'y']];
        $this->api->expects('add_email_packs')->andReturn((object) ['emailPacks' => 5000]);

        $this->packs->order_paid(['order' => $this->order]);

        $this->assertSame([501], array_keys($this->options[FluentCartEmailPacks::FAILED_OPTION]));
    }
}

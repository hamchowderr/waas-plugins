<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use AnyPressHost\Commerce\FluentCart\FluentCartAdapter;
use AnyPressHost\Commerce\FluentCart\FluentCartEmailPacks;
use AnyPressHost\Commerce\FluentCart\FluentCartStoragePacks;
use AnyPressHost\Commerce\FluentCart\FluentCartStore;
use AnyPressHost\Core\ProvisioningApi;
use AnyPressHost\Core\Tenant;
use AnyPressHost\Features\StoreJobs;

class FluentCartStoragePacksTest extends TestCase
{
    private const OWNER_ID = 7;
    private const CUSTOMER_ID = 30;

    private $store;
    private $api;
    private $order;
    private FluentCartStoragePacks $packs;
    /** @var array<int, array<string, mixed>> subscription meta */
    private array $meta;
    /** @var array<int, string> */
    private array $statuses;
    /** @var array<int, int> */
    private array $quantities = [];
    /** @var array<int, int> */
    private array $paid_through = [];
    private array $order_meta = [];
    private array $notes = [];
    private array $logs = [];
    private array $options = [];
    private array $scheduled = [];
    private array $sent = [];
    private string $error_log;
    private $previous_error_log;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
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
            900 => [Tenant::TENANT_EXTERNAL_ID_META => 'ext-900', Tenant::TENANT_STATE => Tenant::READY],
            901 => [Tenant::TENANT_EXTERNAL_ID_META => 'ext-901', Tenant::TENANT_STATE => Tenant::READY],
            902 => [Tenant::TENANT_STATE => Tenant::QUEUED],
        ];
        $this->statuses = [900 => 'active', 901 => 'active', 902 => 'active'];

        $this->store = Mockery::mock(FluentCartStore::class);
        $this->store->allows('get_subscription_meta')->andReturnUsing(fn ($id, $key) => $this->meta[$id][$key] ?? '');
        $this->store->allows('update_subscription_meta')->andReturnUsing(function ($id, $key, $value) {
            $this->meta[$id][$key] = $value;
        });
        $this->store->allows('get_subscription_status')->andReturnUsing(fn ($id) => $this->statuses[$id] ?? '');
        $this->store->allows('get_subscription_quantity')->andReturnUsing(fn ($id) => $this->quantities[$id] ?? 1);
        $this->store->allows('get_paid_through')->andReturnUsing(fn ($id) => $this->paid_through[$id] ?? 0);
        $this->store->allows('find_subscription_ids_with_meta')->andReturnUsing(function ($key, $value) {
            return array_keys(array_filter($this->meta, fn ($meta) => (string) ($meta[$key] ?? '') === $value));
        });
        $this->store->allows('add_subscription_note')->andReturnUsing(function (...$args) {
            $this->notes[] = $args;
        });
        $this->store->allows('get_storage_pack_product')->andReturn([
            'product_id' => 401, 'title' => '25 GB storage', 'checkout_url' => 'https://storefront.example/?fluent-cart=instant_checkout&item_id=41&quantity=1',
        ])->byDefault();
        $this->store->allows('get_subscription_customer')->andReturn(['user_id' => self::OWNER_ID, 'email' => 'owner@apexresearch.example', 'name' => 'Pat', 'country' => 'US']);
        $this->store->allows('find_user_subscription_ids')->andReturn([900])->byDefault();
        $this->store->allows('find_customer_subscription_ids')->andReturn([900])->byDefault();
        $this->store->allows('get_order_customer')->andReturn(['id' => self::CUSTOMER_ID, 'user_id' => self::OWNER_ID, 'email' => 'owner@apexresearch.example']);
        $this->store->allows('get_order_meta')->andReturnUsing(fn ($order, $key) => (string) ($this->order_meta[$key] ?? ''));

        $this->order = Mockery::mock();
        $this->order->id = 500;
        $this->order->type = 'payment';
        $this->order->allows('addLog')->andReturnUsing(function (...$args) {
            $this->logs[] = $args;
        });

        $this->api = Mockery::mock(ProvisioningApi::class);
        $this->api->allows('set_storage_packs')->andReturnUsing(function ($external_id, $packs) {
            $this->sent[] = [$external_id, $packs];
            return (object) ['storagePacks' => $packs];
        })->byDefault();

        $adapter = new FluentCartAdapter($this->store);
        $this->packs = new FluentCartStoragePacks($this->api, $adapter, $this->store, new FluentCartEmailPacks($this->api, $adapter, $this->store));

        $this->error_log = (string) tempnam(sys_get_temp_dir(), 'anypress-log');
        $this->previous_error_log = ini_set('error_log', $this->error_log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previous_error_log);
        @unlink($this->error_log);
        parent::tearDown();
    }

    /** A pack subscription with its status (and website, once it has one). */
    private function pack(int $id, string $status, int $website = 0): object
    {
        $this->statuses[$id] = $status;
        if ($website > 0) {
            $this->meta[$id][FluentCartStoragePacks::WEBSITE_META] = (string) $website;
        }

        return (object) ['id' => $id];
    }

    public function test_a_website_with_a_store_gets_an_add_storage_link_carrying_its_id(): void
    {
        $link = $this->packs->link(900);

        $this->assertSame('Add 25 GB storage', $link['label']);
        $this->assertSame('https://storefront.example/?fluent-cart=instant_checkout&item_id=41&quantity=1&anypress_website=900', $link['url']);
        $this->assertSame(0, $link['packs']);
        $this->assertNull($this->packs->link(902), 'no store yet');
    }

    public function test_no_link_when_the_shop_sells_no_storage_pack(): void
    {
        $this->store->allows('get_storage_pack_product')->andReturn(null);

        $this->assertNull($this->packs->link(900));
    }

    public function test_a_new_pack_goes_to_the_website_chosen_at_checkout_and_the_store_is_told_its_count(): void
    {
        $this->store->allows('find_user_subscription_ids')->andReturn([900, 901]);
        $this->store->allows('find_customer_subscription_ids')->andReturn([900, 901]);
        $this->order_meta[FluentCartEmailPacks::ORDER_WEBSITE_META] = 901;

        $this->packs->changed($this->order, $this->pack(600, 'active'));

        $this->assertSame('901', $this->meta[600][FluentCartStoragePacks::WEBSITE_META]);
        $this->assertSame([['ext-901', 1]], $this->sent);
        $this->assertSame('1', $this->meta[901][FluentCartStoragePacks::SENT_META]);
        $this->assertStringContainsString('1 storage pack (25 GB more storage)', $this->notes[0][2]);
    }

    public function test_without_a_choice_the_pack_goes_to_the_customers_only_live_website(): void
    {
        $this->packs->changed($this->order, $this->pack(600, 'active'));

        $this->assertSame([['ext-900', 1]], $this->sent);
    }

    public function test_with_no_clear_website_nothing_is_sent_and_the_order_says_why(): void
    {
        $this->store->allows('find_user_subscription_ids')->andReturn([900, 901]);
        $this->store->allows('find_customer_subscription_ids')->andReturn([900, 901]);

        $this->packs->changed($this->order, $this->pack(600, 'active'));

        $this->assertSame([], $this->sent);
        $this->assertSame('Storage not added', $this->logs[0][0]);
    }

    public function test_the_count_adds_every_counted_pack_and_quantity_and_stops_at_the_api_limit(): void
    {
        $this->pack(600, 'active', 900);
        $this->pack(601, 'trialing', 900);
        $this->pack(602, 'failing', 900);
        $this->pack(603, 'paused', 900);
        $this->pack(604, 'expired', 900);
        $this->pack(605, 'active', 901);
        $this->quantities[600] = 3;

        $this->assertSame(5, $this->packs->count(900));

        $this->quantities[600] = 30;
        $this->assertSame(FluentCartStoragePacks::MAX_PACKS, $this->packs->count(900));
    }

    public function test_a_cancelled_pack_keeps_counting_until_its_paid_month_ends_and_is_sent_again_then(): void
    {
        $this->pack(600, 'active', 900);
        $cancelled = $this->pack(601, 'canceled', 900);
        $this->paid_through[601] = time() + 86400;

        $this->packs->changed($this->order, $cancelled);

        $this->assertSame([['ext-900', 2]], $this->sent);
        $this->assertSame(FluentCartStoragePacks::SYNC_HOOK, $this->scheduled[0][1]);
        $this->assertSame([900], $this->scheduled[0][2]);
        $this->assertGreaterThan(time() + 86400, $this->scheduled[0][0]);

        $this->paid_through[601] = time() - 1;
        $this->packs->sync(900);
        $this->assertSame(['ext-900', 1], $this->sent[1]);
    }

    public function test_a_refund_of_the_order_that_bought_the_pack_ends_it_but_a_renewal_refund_does_not(): void
    {
        $renewal = Mockery::mock();
        $renewal->type = 'renewal';
        $this->packs->changed($renewal, $this->pack(600, 'active', 900), true);
        $this->assertSame([['ext-900', 1]], $this->sent);

        $this->packs->changed($this->order, $this->pack(600, 'active', 900), true);
        $this->assertSame(['ext-900', 0], $this->sent[1]);
    }

    public function test_pausing_or_resuming_a_pack_sends_the_count_again(): void
    {
        $paused = $this->pack(600, 'paused', 900);
        $this->packs->paused_or_resumed(['subscription' => $paused]);
        $this->assertSame([['ext-900', 0]], $this->sent);

        $this->statuses[600] = 'active';
        $this->packs->paused_or_resumed(['subscription' => $paused]);
        $this->assertSame(['ext-900', 1], $this->sent[1]);

        $this->packs->paused_or_resumed(['subscription' => (object) ['id' => 900]]); // a website plan: not a pack
        $this->assertCount(2, $this->sent);
    }

    public function test_a_failed_call_is_retried_then_listed_for_the_admin(): void
    {
        $this->api->allows('set_storage_packs')->andThrow(new \Exception('API down'));
        $this->pack(600, 'active', 900);

        $this->packs->sync(900);
        $this->assertSame(FluentCartStoragePacks::SYNC_HOOK, $this->scheduled[0][1]);

        for ($i = 1; $i <= count(StoreJobs::RETRY_DELAYS); $i++) {
            $this->packs->sync(900);
        }
        $this->assertCount(count(StoreJobs::RETRY_DELAYS), $this->scheduled);
        $this->assertSame('API down', $this->options[FluentCartStoragePacks::FAILED_OPTION][900]['error']);
        $this->assertSame('Storage packs not sent', $this->notes[0][1]);
    }

    public function test_a_website_without_a_store_is_not_sent_anything(): void
    {
        $this->pack(600, 'active', 902);

        $this->packs->sync(902);

        $this->assertSame([], $this->sent);
    }
}

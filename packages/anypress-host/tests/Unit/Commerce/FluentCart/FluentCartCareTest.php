<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use AnyPressHost\Commerce\FluentCart\FluentCartAdapter;
use AnyPressHost\Commerce\FluentCart\FluentCartCare;
use AnyPressHost\Commerce\FluentCart\FluentCartCareDesk;
use AnyPressHost\Commerce\FluentCart\FluentCartEmailPacks;
use AnyPressHost\Commerce\FluentCart\FluentCartStore;
use AnyPressHost\Core\ProvisioningApi;
use AnyPressHost\Core\Tenant;

/** Care packages and services: which website they belong to, the month's time, the team's ticket card and log. */
class FluentCartCareTest extends TestCase
{
    private const OWNER_ID = 7;
    private const CUSTOMER_ID = 30;
    private const CARE_PLUS = 401;
    private const HOUR = 502;
    private const SETUP = 501;
    private const PLAN = 101;

    private $store;
    private FluentCartCare $care;
    private $order;
    /** @var array<int, array<string, mixed>> */
    private array $meta;
    /** @var array<int, string> */
    private array $statuses;
    /** @var array<int, int> subscription => product */
    private array $products;
    private array $order_meta = [];
    private array $logs = [];
    private array $notes = [];
    private array $cancelled = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\stubs(['sanitize_text_field']);
        Functions\when('wp_date')->justReturn('2026-11');
        Functions\when('get_the_title')->alias(fn ($id) => [self::CARE_PLUS => 'Care Plus', self::HOUR => 'One hour', self::SETUP => 'Set up my site'][$id] ?? "Product $id");
        Functions\when('get_userdata')->alias(fn ($id) => $id === self::OWNER_ID ? (object) ['ID' => $id, 'user_email' => 'owner@apexresearch.example'] : false);
        Functions\when('get_user_by')->justReturn(false);
        Functions\when('wp_get_current_user')->justReturn((object) ['display_name' => 'Robin']);
        Functions\when('admin_url')->alias(fn ($path = '') => 'https://shop.example/wp-admin/' . $path);

        // 900, 901: the owner's live websites; 902: being set up; 950: a Care Plus package for 900; 951: one waiting.
        $this->meta = [
            900 => [Tenant::TENANT_EXTERNAL_ID_META => 'ext-900', Tenant::DOMAIN_NAME_META => 'apex.sites.example', Tenant::TENANT_STATE => Tenant::READY, FluentCartCare::USED_META => ['month' => '2026-11', 'minutes' => 40]],
            901 => [Tenant::TENANT_EXTERNAL_ID_META => 'ext-901', Tenant::BASE_DOMAIN_NAME_META => 'second.sites.example', Tenant::TENANT_STATE => Tenant::READY],
            902 => [Tenant::TENANT_STATE => Tenant::QUEUED],
            950 => [FluentCartCare::WEBSITE_META => 900],
            951 => [],
        ];
        $this->statuses = [900 => 'active', 901 => 'active', 902 => 'active', 950 => 'active', 951 => 'active'];
        $this->products = [900 => self::PLAN, 901 => self::PLAN, 902 => self::PLAN, 950 => self::CARE_PLUS, 951 => self::CARE_PLUS];

        $this->store = Mockery::mock(FluentCartStore::class);
        $this->store->allows('get_subscription_meta')->andReturnUsing(fn ($id, $key) => $this->meta[$id][$key] ?? '');
        $this->store->allows('update_subscription_meta')->andReturnUsing(function ($id, $key, $value) {
            $this->meta[$id][$key] = $value;
        });
        $this->store->allows('get_subscription_status')->andReturnUsing(fn ($id) => $this->statuses[$id] ?? '');
        $this->store->allows('get_subscription_product_id')->andReturnUsing(fn ($id) => $this->products[$id] ?? 0);
        $this->store->allows('get_subscription_customer')->andReturn(['id' => self::CUSTOMER_ID, 'user_id' => self::OWNER_ID, 'email' => 'owner@apexresearch.example', 'name' => 'Pat', 'country' => 'US']);
        $this->store->allows('find_user_subscription_ids')->andReturnUsing(fn () => array_keys($this->meta));
        $this->store->allows('find_customer_subscription_ids')->andReturn([]);
        $this->store->allows('find_subscription_ids_with_meta')->andReturnUsing(fn ($key, $value) => array_keys(array_filter($this->meta, fn ($m) => (string) ($m[$key] ?? '') === $value)));
        $this->store->allows('find_subscription_meta')->andReturnUsing(function ($key) {
            $found = [];
            foreach ($this->meta as $id => $m) {
                if (isset($m[$key])) {
                    $found[$id] = $m[$key];
                }
            }
            return $found;
        });
        $this->store->allows('get_feed_of_type')->andReturnUsing(function ($product_id, $type) {
            $feeds = [
                self::CARE_PLUS => ['care', ['product_type' => 'care', 'care_minutes' => '120', 'care_answer' => '1 business day', 'care_priority' => 'medium']],
                self::HOUR => ['service', ['product_type' => 'service', 'service_minutes' => '60']],
                self::SETUP => ['service', ['product_type' => 'service', 'service_minutes' => '']],
            ];
            return isset($feeds[$product_id]) && $feeds[$product_id][0] === $type ? $feeds[$product_id][1] : null;
        });
        $this->store->allows('add_subscription_note')->andReturnUsing(function ($id, $title, $detail) {
            $this->notes[] = [$id, $title, $detail];
        });
        $this->store->allows('cancel_subscription')->andReturnUsing(function ($id) {
            $this->cancelled[] = $id;
            return true;
        });
        $this->store->allows('get_order_meta')->andReturnUsing(fn ($order, $key) => (string) ($this->order_meta[$key] ?? ''));
        $this->store->allows('get_order_meta_value')->andReturnUsing(fn ($order, $key) => $this->order_meta[$key] ?? '');
        $this->store->allows('update_order_meta')->andReturnUsing(function ($order, $key, $value) {
            $this->order_meta[$key] = $value;
        });
        $this->store->allows('get_order_customer')->andReturn(['id' => self::CUSTOMER_ID, 'user_id' => self::OWNER_ID, 'email' => 'owner@apexresearch.example', 'name' => 'Pat']);

        $this->order = Mockery::mock();
        $this->order->id = 600;
        $this->order->allows('addLog')->andReturnUsing(function (...$args) {
            $this->logs[] = $args;
        });

        $adapter = new FluentCartAdapter($this->store);
        $this->care = new FluentCartCare($adapter, $this->store, new FluentCartEmailPacks(Mockery::mock(ProvisioningApi::class), $adapter, $this->store));
    }

    public function test_a_website_with_care_shows_its_package_and_this_months_time(): void
    {
        $this->assertSame([
            'subscription_id' => 950,
            'package' => 'Care Plus',
            'answer' => '1 business day',
            'priority' => 'medium',
            'call_url' => '',
            'minutes' => 120,
            'used' => 40,
            'left' => 80,
            'extra' => 0,
        ], $this->care->summary(900));
        $this->assertNull($this->care->summary(901));
    }

    public function test_last_months_time_does_not_count(): void
    {
        $this->meta[900][FluentCartCare::USED_META] = ['month' => '2026-10', 'minutes' => 120];

        $this->assertSame(120, $this->care->summary(900)['left']);
    }

    public function test_a_new_package_in_the_same_month_keeps_the_months_used_time(): void
    {
        $this->statuses[950] = 'canceled';
        $this->meta[951][FluentCartCare::WEBSITE_META] = 900;

        $this->assertSame(951, $this->care->care_of(900));
        $this->assertSame(80, $this->care->summary(900)['left']);
    }

    public function test_a_cancelled_package_is_no_care(): void
    {
        $this->statuses[950] = 'canceled';

        $this->assertSame(0, $this->care->care_of(900));
        $this->assertNull($this->care->summary(900));
    }

    public function test_logged_time_uses_the_month_first_then_extra_time_and_reports_the_rest(): void
    {
        $this->meta[900][FluentCartCare::EXTRA_META] = 30;

        $this->assertSame(['monthly' => 80, 'extra' => 30, 'over' => 10], $this->care->log_time(900, 120, 'Ticket #12'));
        $this->assertSame(['month' => '2026-11', 'minutes' => 160], $this->meta[900][FluentCartCare::USED_META]);
        $this->assertSame(['minutes' => 120, 'note' => 'Ticket #12', 'by' => 'Robin'], array_diff_key($this->care->history(900)[0], ['at' => 1]));
        $this->assertSame(0, $this->meta[900][FluentCartCare::EXTRA_META]);
        $this->assertSame([950, 'Care time logged', '2 h logged: Ticket #12. 10 min over the time left; bill it as extra time.'], $this->notes[0]);
    }

    public function test_time_reads_in_hours_and_minutes(): void
    {
        $this->assertSame('30 min', FluentCartCare::format_minutes(30));
        $this->assertSame('2 h', FluentCartCare::format_minutes(120));
        $this->assertSame('1 h 20 min', FluentCartCare::format_minutes(80));
    }

    public function test_a_package_bought_from_the_account_goes_to_the_chosen_website(): void
    {
        $this->order_meta[FluentCartEmailPacks::ORDER_WEBSITE_META] = 901;
        $this->store->allows('get_order_lines')->andReturn([['id' => 1, 'product_id' => self::CARE_PLUS, 'quantity' => 1]]);
        $this->store->allows('find_subscription_for_order')->andReturn((object) ['id' => 951]);

        $this->care->order_paid(['order' => $this->order]);

        $this->assertSame(901, $this->meta[951][FluentCartCare::WEBSITE_META]);
        $this->assertSame(951, $this->care->care_of(901));
        $this->assertNotEmpty($this->order_meta[FluentCartCare::ORDER_DONE_META]);
    }

    public function test_a_package_bought_without_a_website_goes_to_the_only_one_without_care(): void
    {
        $this->store->allows('get_order_lines')->andReturn([['id' => 1, 'product_id' => self::CARE_PLUS, 'quantity' => 1]]);
        $this->store->allows('find_subscription_for_order')->andReturn((object) ['id' => 951]);

        $this->care->order_paid(['order' => $this->order]);

        $this->assertSame(901, $this->meta[951][FluentCartCare::WEBSITE_META], '900 already has care, 902 is not up yet');
    }

    public function test_with_no_website_to_pick_the_package_waits_for_the_customer_to_choose(): void
    {
        $this->meta[951][FluentCartCare::WEBSITE_META] = 0;
        unset($this->meta[901]);
        $this->store->allows('get_order_lines')->andReturn([['id' => 1, 'product_id' => self::CARE_PLUS, 'quantity' => 1]]);
        $this->store->allows('find_subscription_for_order')->andReturn((object) ['id' => 951]);

        $this->care->order_paid(['order' => $this->order]);

        $this->assertSame(0, $this->meta[951][FluentCartCare::WEBSITE_META]);
        $this->assertSame('Care waiting for a website', $this->logs[0][0]);
        $this->assertSame([951], $this->care->waiting_for_user(self::OWNER_ID));
    }

    public function test_a_waiting_package_goes_only_to_the_customers_own_live_website_without_care(): void
    {
        $this->assertFalse($this->care->attach_for_user(951, 900, self::OWNER_ID), 'already has care');
        $this->assertFalse($this->care->attach_for_user(951, 902, self::OWNER_ID), 'not up yet');
        $this->assertFalse($this->care->attach_for_user(951, 901, 99), 'someone else');
        $this->assertFalse($this->care->attach_for_user(900, 901, self::OWNER_ID), 'not a care package');

        $this->assertTrue($this->care->attach_for_user(951, 901, self::OWNER_ID));
        $this->assertSame(901, $this->meta[951][FluentCartCare::WEBSITE_META]);
        $this->assertSame([], $this->care->waiting_for_user(self::OWNER_ID));
    }

    public function test_a_single_hour_adds_extra_time_to_the_website_once(): void
    {
        $this->order_meta[FluentCartEmailPacks::ORDER_WEBSITE_META] = 900;
        $this->store->allows('get_order_lines')->andReturn([['id' => 1, 'product_id' => self::HOUR, 'quantity' => 2]]);

        $this->care->order_paid(['order' => $this->order]);
        $this->care->order_paid(['order' => $this->order]);

        $this->assertSame(120, $this->meta[900][FluentCartCare::EXTRA_META]);
        $this->assertSame(120, $this->care->summary(900)['extra']);
        // Fluent Support isn't active here, so the order says to contact the customer.
        $this->assertSame('Service request not filed', $this->logs[0][0]);
    }

    public function test_plans_and_other_products_are_left_alone(): void
    {
        $this->store->allows('get_order_lines')->andReturn([['id' => 1, 'product_id' => self::PLAN, 'quantity' => 1]]);

        $this->care->order_paid(['order' => $this->order]);

        $this->assertArrayNotHasKey(FluentCartCare::ORDER_DONE_META, $this->order_meta);
        $this->assertSame([], $this->logs);
    }

    public function test_care_ends_with_the_websites_plan(): void
    {
        $this->care->website_ended(900);
        $this->care->website_ended(901);

        $this->assertSame([950], $this->cancelled);
        $this->assertSame('Care cancelled', end($this->notes)[1]);
    }

    public function test_each_ticket_shows_the_customers_care(): void
    {
        $desk = new FluentCartCareDesk(new FluentCartAdapter($this->store), $this->store, $this->care);

        $widgets = $desk->ticket_widget([], (object) ['user_id' => self::OWNER_ID, 'email' => 'owner@apexresearch.example']);

        $this->assertSame('Care', $widgets['anypress_care']['title']);
        $this->assertSame('Care Plus', $widgets['anypress_care']['badges'][0]['text']);
        $item = $widgets['anypress_care']['sections'][0]['items'][0];
        $this->assertSame('apex.sites.example', $item['title']);
        $this->assertSame('Care Plus · answer within 1 business day', $item['subtitle']);
        $this->assertSame('1 h 20 min of 2 h left this month', $item['detail']);
        $this->assertStringEndsWith('page=anypress-care&log=900#care-900', $item['url']);
        $this->assertCount(1, $widgets['anypress_care']['sections'], 'no time logged yet');

        $this->care->log_time(900, 25, 'Ticket #12: new menu');
        $widgets = $desk->ticket_widget([], (object) ['user_id' => self::OWNER_ID]);
        $logged = $widgets['anypress_care']['sections'][1];
        $this->assertSame('Time logged', $logged['label']);
        $this->assertSame(['Ticket #12: new menu', 'Robin', '25 min', 'date'], [$logged['items'][0]['title'], $logged['items'][0]['subtitle'], $logged['items'][0]['detail'], $logged['items'][0]['meta_type']]);

        $mcp = $desk->ticket_widget([], (object) ['user_id' => self::OWNER_ID], ['format' => 'mcp', 'keys' => null]);
        $this->assertSame(55, $mcp['anypress_care']['mcp'][0]['left']);

        $this->assertSame([], $desk->ticket_widget([], (object) ['user_id' => self::OWNER_ID], ['format' => 'html', 'keys' => ['fluent_cart_purchases']]));
        $this->assertSame([], $desk->ticket_widget([], (object) ['user_id' => 0, 'email' => 'nobody@example.com']));
    }

    public function test_the_care_page_lists_websites_with_care_or_extra_time(): void
    {
        $this->meta[901][FluentCartCare::EXTRA_META] = 60;
        $desk = new FluentCartCareDesk(new FluentCartAdapter($this->store), $this->store, $this->care);

        $this->assertSame([900, 901], $desk->websites());
    }

    public function test_answer_times_are_counted_in_business_days(): void
    {
        $this->assertSame(0, FluentCartCareDesk::answer_days('the same business day'));
        $this->assertSame(1, FluentCartCareDesk::answer_days('1 business day'));
        $this->assertSame(2, FluentCartCareDesk::answer_days('2 business days'));
        $this->assertNull(FluentCartCareDesk::answer_days('soon'));

        $zone = new \DateTimeZone('America/Los_Angeles');
        $friday = new \DateTimeImmutable('2026-11-13 16:00', $zone);
        $this->assertSame('2026-11-13 23:59', FluentCartCareDesk::due($friday, 0)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-16 23:59', FluentCartCareDesk::due($friday, 1)->format('Y-m-d H:i'), 'Friday + 1 is Monday');
        $saturday = new \DateTimeImmutable('2026-11-14 10:00', $zone);
        $this->assertSame('2026-11-16 23:59', FluentCartCareDesk::due($saturday, 0)->format('Y-m-d H:i'), 'a weekend request is due Monday');
        $this->assertSame('2026-11-18 23:59', FluentCartCareDesk::due($saturday, 2)->format('Y-m-d H:i'));
    }
}

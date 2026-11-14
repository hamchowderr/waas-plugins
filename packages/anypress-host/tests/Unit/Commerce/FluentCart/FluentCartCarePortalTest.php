<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use AnyPressHost\Commerce\FluentCart\FluentCartAdapter;
use AnyPressHost\Commerce\FluentCart\FluentCartCare;
use AnyPressHost\Commerce\FluentCart\FluentCartCarePortal;
use AnyPressHost\Commerce\FluentCart\FluentCartStore;
use AnyPressHost\Core\Tenant;

/** Where customers meet care: the thank-you page after a plan and the Care section of a website's card. */
class FluentCartCarePortalTest extends TestCase
{
    private const PLAN = 101;

    private $store;
    private $care;
    private FluentCartCarePortal $portal;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\stubs([
            'sanitize_text_field', 'sanitize_key', 'wp_unslash', 'wp_strip_all_tags',
            'absint' => fn ($v) => abs((int) $v),
            'checked' => fn ($a, $b, $echo) => (string) $a === (string) $b ? ' checked' : '',
            'admin_url' => fn ($path = '') => 'https://shop.example/wp-admin/' . $path,
            'get_current_user_id' => 7,
            'number_format_i18n' => fn ($n) => number_format($n),
            'esc_url_raw' => fn ($url) => (string) $url,
            'is_user_logged_in' => true,
        ]);
        Functions\when('add_query_arg')->alias(function ($key, $value, $url = null) {
            if (is_array($key)) {
                $url = $value;
                foreach ($key as $k => $v) {
                    $url .= '&' . $k . '=' . $v;
                }
                return $url;
            }
            return $url . '&' . $key . '=' . $value;
        });
        Functions\when('wp_register_style')->justReturn(true);
        Functions\when('wp_add_inline_style')->justReturn(true);
        Functions\when('wp_print_styles')->justReturn(true);

        $this->store = Mockery::mock(FluentCartStore::class);
        $this->store->allows('get_plan_feed')->andReturnUsing(fn ($id) => $id === self::PLAN ? ['role' => 'website'] : null);
        $this->store->allows('get_subscription_meta')->andReturnUsing(fn ($id, $key) => $key === Tenant::TENANT_STATE ? Tenant::READY : '');
        $this->store->allows('get_subscription_url')->andReturn('https://shop.example/account/subscriptions/950');
        Functions\when('get_userdata')->justReturn((object) ['user_email' => 'sam@anypress.example']);
        $this->store->allows('find_user_subscription_ids')->andReturn([900]);
        $this->store->allows('get_subscription_customer')->andReturn(['user_id' => 7, 'email' => 'sam@anypress.example']);

        $this->care = Mockery::mock(FluentCartCare::class);
        $this->care->allows('is_live_website')->andReturn(true);
        $this->care->allows('waiting_for_user')->andReturn([]);
        $this->care->allows('packages')->andReturn([
            [
                'product_id' => 401, 'title' => 'Care', 'excerpt' => '', 'settings' => ['care_minutes' => '30', 'care_answer' => '2 business days'],
                'variations' => [
                    ['id' => 41, 'title' => 'Monthly', 'price' => 7900, 'interval' => 'monthly', 'checkout_url' => 'https://shop.example/?fluent-cart=instant_checkout&item_id=41&quantity=1'],
                    ['id' => 42, 'title' => 'Yearly', 'price' => 79000, 'interval' => 'yearly', 'checkout_url' => 'https://shop.example/?fluent-cart=instant_checkout&item_id=42&quantity=1'],
                ],
            ],
            [
                'product_id' => 402, 'title' => 'Care Plus', 'excerpt' => '', 'settings' => ['care_minutes' => '120', 'care_answer' => '1 business day'],
                'variations' => [
                    ['id' => 43, 'title' => 'Monthly', 'price' => 19900, 'interval' => 'monthly', 'checkout_url' => 'https://shop.example/?fluent-cart=instant_checkout&item_id=43&quantity=1'],
                    ['id' => 44, 'title' => 'Yearly', 'price' => 199000, 'interval' => 'yearly', 'checkout_url' => 'https://shop.example/?fluent-cart=instant_checkout&item_id=44&quantity=1'],
                ],
            ],
        ]);
        $this->care->allows('services')->andReturn([
            ['product_id' => 501, 'title' => 'Set up my site for me', 'excerpt' => 'We build it out.', 'settings' => [], 'variations' => [['id' => 51, 'title' => '', 'price' => 29900, 'interval' => '', 'checkout_url' => 'https://shop.example/?fluent-cart=instant_checkout&item_id=51&quantity=1']]],
            ['product_id' => 502, 'title' => 'Extra hour for care customers', 'excerpt' => 'More time.', 'settings' => ['product_type' => 'service', 'service_care_only' => 'yes'], 'variations' => [['id' => 52, 'title' => '', 'price' => 10000, 'interval' => '', 'checkout_url' => 'https://shop.example/?fluent-cart=instant_checkout&item_id=52&quantity=1']]],
        ]);

        $this->portal = new FluentCartCarePortal(new FluentCartAdapter($this->store), $this->store, $this->care);
    }

    protected function tearDown(): void
    {
        unset($_GET[FluentCartCarePortal::SITE_ARG], $_GET[FluentCartCarePortal::RESULT_ARG]);
        parent::tearDown();
    }

    private function rendered(callable $render): string
    {
        ob_start();
        $render();
        return (string) ob_get_clean();
    }

    /** A paid order for $product, whose subscription is $subscription_id. */
    private function order(int $product, int $subscription_id): object
    {
        $order = (object) ['id' => 77];
        $this->store->allows('get_order_lines')->with($order)->andReturn([['product_id' => $product, 'quantity' => 1]]);
        $this->store->allows('find_subscription_for_order')->with($order)->andReturn($subscription_id > 0 ? (object) ['id' => $subscription_id] : null);

        return $order;
    }

    public function test_the_thank_you_page_after_a_plan_offers_each_package_for_the_new_website(): void
    {
        $this->care->allows('care_of')->with(900)->andReturn(0);

        $html = $this->rendered(fn () => $this->portal->render_receipt_offer(['order' => $this->order(self::PLAN, 900)]));

        $this->assertStringContainsString('Add care to your new website', $html);
        $this->assertStringContainsString('<strong>Care Plus</strong></p><p class="anypress-care-price"><b>$199</b> <span>a month</span></p>', $html);
        $this->assertStringContainsString('<li>2 h of changes a month</li><li>Answer within 1 business day</li>', $html);
        $this->assertStringContainsString('href="https://shop.example/?fluent-cart=instant_checkout&item_id=43&quantity=1&anypress_website=900">Add Care Plus</a>', $html);
        $this->assertStringNotContainsString('Book a call', $html);
    }

    public function test_the_thank_you_page_offers_nothing_after_other_orders_or_for_someone_else(): void
    {
        $this->care->allows('care_of')->andReturn(0);

        $this->assertSame('', $this->rendered(fn () => $this->portal->render_receipt_offer(['order' => $this->order(401, 950)])), 'not a plan');
        $this->assertSame('', $this->rendered(fn () => $this->portal->render_receipt_offer(['order' => $this->order(self::PLAN, 901)])), 'not this customer\'s website');
        $this->assertSame('', $this->rendered(fn () => $this->portal->render_receipt_offer([])));
    }

    public function test_a_website_without_care_shows_the_packages_each_with_its_checkout(): void
    {
        $this->care->allows('summary')->andReturn(null);

        $html = $this->rendered(fn () => $this->portal->render_section(900));

        $this->assertStringContainsString('class="anypress-acct-panel anypress-acct-care"', $html);
        $this->assertStringContainsString('<div class="anypress-acct-care-tier"><p class="anypress-acct-care-name"><strong>Care Plus</strong>', $html);
        $this->assertStringContainsString('is-yearly"><p><b>$790</b> <span>a year</span></p><a class="anypress-acct-btn is-primary" href="https://shop.example/?fluent-cart=instant_checkout&item_id=42&quantity=1&anypress_website=900">Add Care</a></div>', $html);
        $this->assertStringContainsString('Yearly <small>2 months free</small>', $html);
        $this->assertStringContainsString('<li>2 h of changes a month</li><li>Answer within 1 business day</li>', $html);
        $this->assertStringContainsString('<strong>Set up my site for me</strong>', $html);
        $this->assertStringNotContainsString('Extra hour for care customers', $html, 'the care price is not offered without care');
        $this->assertStringContainsString('item_id=51&quantity=1&anypress_website=900">Buy</a>', $html);
        $this->assertStringNotContainsString('Book a call', $html);
    }

    public function test_a_website_with_care_shows_the_time_left_and_how_to_manage_it(): void
    {
        $this->care->allows('summary')->andReturn(['call_url' => '', 'subscription_id' => 950, 'package' => 'Care Plus', 'answer' => '1 business day', 'call_url' => 'https://shop.example/book/care-call', 'minutes' => 120, 'used' => 40, 'left' => 80, 'extra' => 60]);
        $_GET[FluentCartCarePortal::SITE_ARG] = '900';
        $_GET[FluentCartCarePortal::RESULT_ARG] = 'sent';

        $html = $this->rendered(fn () => $this->portal->render_section(900));

        $this->assertStringContainsString('Care Plus <small>1 h 20 min of 2 h left this month, answered within 1 business day</small>', $html);
        $this->assertStringContainsString('width:67%', $html);
        $this->assertStringContainsString('Plus 1 h of extra time', $html);
        $this->assertStringContainsString('We answer by email within 1 business day', $html);
        $this->assertStringContainsString('Manage or cancel care', $html);
        $this->assertStringContainsString('href="https://shop.example/book/care-call" target="_blank" rel="noopener">Book this month&#039;s call', str_replace("'", '&#039;', $html));
        $this->assertStringContainsString('item_id=52&quantity=1&anypress_website=900">Extra hour for care customers · $100</a>', $html, 'the extra hour at the care price');
        $this->assertSame(1, substr_count($html, 'anypress_website='), 'no package offers once it has a package');
    }

    public function test_care_and_services_are_left_out_of_the_shop_lists(): void
    {
        $query = Mockery::mock();
        $query->expects('whereNotIn')->with('ID', [401, 402, 501, 502])->andReturnSelf();

        $this->assertSame($query, $this->portal->hide_from_shop($query));
    }

    public function test_checkout_takes_care_and_one_time_help_from_anyone(): void
    {
        $this->store->allows('get_cart_product_ids')->andReturn([402], [501]);

        $this->assertSame([], $this->portal->validate_checkout([], ['cart' => (object) [], 'data' => []]));
        $this->assertSame([], $this->portal->validate_checkout([], ['cart' => (object) [], 'data' => []]));
    }

    public function test_the_extra_hour_at_the_care_price_needs_a_website_with_care(): void
    {
        $this->store->allows('get_cart_product_ids')->andReturn([502], [502, self::PLAN], [502]);
        $this->care->allows('has_care_package')->with(7)->andReturn(false, false, true);

        $this->assertArrayHasKey('care_only', $this->portal->validate_checkout([], ['cart' => (object) [], 'data' => []])['anypress_care']);
        $this->assertArrayHasKey('care_only', $this->portal->validate_checkout([], ['cart' => (object) [], 'data' => []])['anypress_care'], 'a plan in the cart is not care');
        $this->assertSame([], $this->portal->validate_checkout([], ['cart' => (object) [], 'data' => []]));
    }
}

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

/** Where customers meet care: the box on a plan's product page and the Care section of a website's card. */
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
        ]);
        Functions\when('add_query_arg')->alias(fn ($key, $value, $url) => $url . '&' . $key . '=' . $value);
        Functions\when('wp_register_script')->justReturn(true);
        Functions\when('wp_add_inline_script')->justReturn(true);
        Functions\when('wp_enqueue_script')->justReturn(true);

        $this->store = Mockery::mock(FluentCartStore::class);
        $this->store->allows('get_plan_feed')->andReturnUsing(fn ($id) => $id === self::PLAN ? ['role' => 'website'] : null);
        $this->store->allows('get_subscription_meta')->andReturnUsing(fn ($id, $key) => $key === Tenant::TENANT_STATE ? Tenant::READY : '');
        $this->store->allows('get_subscription_url')->andReturn('https://shop.example/account/subscriptions/950');

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
        ]);

        $this->portal = new FluentCartCarePortal(new FluentCartAdapter($this->store), $this->store, $this->care);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[FluentCartCarePortal::PICK_COOKIE], $_GET[FluentCartCarePortal::SITE_ARG], $_GET[FluentCartCarePortal::RESULT_ARG]);
        parent::tearDown();
    }

    private function output(callable $render): string
    {
        ob_start();
        $render();
        return (string) ob_get_clean();
    }

    public function test_a_plan_page_offers_the_packages_with_their_monthly_price_and_the_services(): void
    {
        $html = $this->output(fn () => $this->portal->render_product_box(['product' => (object) ['ID' => self::PLAN]]));

        $this->assertStringContainsString('Want us to look after it?', $html);
        $this->assertStringContainsString('value="0" checked', $html);
        $this->assertStringContainsString('<strong>Care Plus</strong> <small>2 h of changes a month, answered within 1 business day</small></span> <b>$199 a month</b>', $html);
        $this->assertStringContainsString('<li><span>Set up my site for me</span> <b>$299</b></li>', $html);
    }

    public function test_the_box_is_only_on_plan_pages(): void
    {
        $this->assertSame('', $this->output(fn () => $this->portal->render_product_box(['product' => (object) ['ID' => 401]])));
        $this->assertSame('', $this->output(fn () => $this->portal->render_product_box([])));
    }

    public function test_a_website_without_care_shows_the_packages_as_tiers_with_the_pick_from_the_product_page_marked(): void
    {
        $_COOKIE[FluentCartCarePortal::PICK_COOKIE] = '43';
        $this->care->allows('summary')->andReturn(null);

        $html = $this->output(fn () => $this->portal->render_section(900));

        $this->assertStringContainsString('class="anypress-acct-panel anypress-acct-care"', $html);
        $this->assertStringContainsString('<div class="anypress-acct-care-tier is-picked"><p class="anypress-acct-care-name"><strong>Care Plus</strong> <em>Your pick</em>', $html);
        $this->assertStringContainsString('item_id=43&quantity=1&anypress_website=900">Add Care Plus</a>', $html);
        $this->assertMatchesRegularExpression('/is-yearly"><p><b>\$790<\/b> <span>a year<\/span><\/p><a [^>]*item_id=42&quantity=1&anypress_website=900"/', $html);
        $this->assertStringContainsString('value="monthly" checked', $html, 'monthly first, as the pick is monthly');
        $this->assertStringContainsString('Yearly <small>2 months free</small>', $html);
        $this->assertStringContainsString('<li>2 h of changes a month</li><li>Answer within 1 business day</li>', $html);
        $this->assertStringContainsString('item_id=51', $html);
    }

    public function test_a_website_with_care_shows_the_time_left_and_how_to_manage_it(): void
    {
        $this->care->allows('summary')->andReturn(['call_url' => '', 'subscription_id' => 950, 'package' => 'Care Plus', 'answer' => '1 business day', 'call_url' => 'https://shop.example/book/care-call', 'minutes' => 120, 'used' => 40, 'left' => 80, 'extra' => 60]);
        $_GET[FluentCartCarePortal::SITE_ARG] = '900';
        $_GET[FluentCartCarePortal::RESULT_ARG] = 'sent';

        $html = $this->output(fn () => $this->portal->render_section(900));

        $this->assertStringContainsString('Care Plus <small>1 h 20 min of 2 h left this month, answered within 1 business day</small>', $html);
        $this->assertStringContainsString('width:67%', $html);
        $this->assertStringContainsString('Plus 1 h of extra time', $html);
        $this->assertStringContainsString('We answer by email within 1 business day', $html);
        $this->assertStringContainsString('Manage or cancel care', $html);
        $this->assertStringContainsString('href="https://shop.example/book/care-call" target="_blank" rel="noopener">Book this month&#039;s call', str_replace("'", '&#039;', $html));
        $this->assertStringNotContainsString('anypress_website=', $html, 'no offers once it has a package');
    }

    public function test_care_and_services_are_left_out_of_the_shop_lists(): void
    {
        $query = Mockery::mock();
        $query->expects('whereNotIn')->with('ID', [401, 402, 501])->andReturnSelf();

        $this->assertSame($query, $this->portal->hide_from_shop($query));
    }

    public function test_checkout_turns_away_care_for_a_buyer_without_a_website(): void
    {
        $this->store->allows('get_cart_product_ids')->andReturn([402]);
        $this->care->expects('has_live_website')->with(7)->andReturn(false);

        $errors = $this->portal->validate_checkout([], ['cart' => (object) [], 'data' => []]);

        $this->assertArrayHasKey('no_website', $errors['anypress_care']);
    }

    public function test_checkout_takes_care_for_a_buyer_with_a_website_or_with_a_plan_in_the_cart(): void
    {
        $this->store->allows('get_cart_product_ids')->andReturn([501], [501, self::PLAN], [self::PLAN]);
        $this->care->expects('has_live_website')->once()->with(7)->andReturn(true);

        $this->assertSame([], $this->portal->validate_checkout([], ['cart' => (object) [], 'data' => []]));
        $this->assertSame([], $this->portal->validate_checkout([], ['cart' => (object) [], 'data' => []]));
        $this->assertSame([], $this->portal->validate_checkout([], ['cart' => (object) [], 'data' => []]));
    }
}

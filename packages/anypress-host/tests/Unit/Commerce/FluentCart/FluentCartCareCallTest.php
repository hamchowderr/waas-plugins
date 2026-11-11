<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use AnyPressHost\Commerce\FluentCart\FluentCartCare;
use AnyPressHost\Commerce\FluentCart\FluentCartCareCall;
use AnyPressHost\Commerce\FluentCart\FluentCartEmailPacks;
use AnyPressHost\Commerce\FluentCart\FluentCartStore;
use AnyPressHost\Core\Tenant;

/** Care starts with a call: the team makes a pay link for one website and one package after it. */
class FluentCartCareCallTest extends TestCase
{
    private $store;
    private $care;
    private array $cart = [];
    private FluentCartCareCall $call;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\stubs([
            'sanitize_text_field', 'sanitize_key', 'wp_unslash',
            'absint' => fn ($v) => abs((int) $v),
            'wp_salt' => 'test-salt',
            'current_user_can' => true,
            'wp_verify_nonce' => fn ($nonce, $action) => $nonce === 'good' && $action === FluentCartCareCall::LINK_ACTION,
        ]);
        Functions\when('add_query_arg')->alias(function ($args, $url) {
            return $url . '&' . http_build_query($args);
        });

        $this->store = Mockery::mock(FluentCartStore::class);
        $this->store->allows('find_subscription_meta')->with(Tenant::TENANT_STATE, [])->andReturn([900 => Tenant::READY, 901 => Tenant::DELETED]);
        $this->store->allows('get_subscription_customer')->andReturn(['email' => 'sam@anypress.example']);
        $this->store->allows('find_cart_by_hash')->andReturn((object) []);
        $this->store->allows('set_cart_checkout_value')->andReturnUsing(function ($cart, $key, $value) {
            $this->cart[$key] = $value;
        });
        $this->store->allows('get_cart_checkout_value')->andReturnUsing(fn ($cart, $key) => $this->cart[$key] ?? null);

        $this->care = Mockery::mock(FluentCartCare::class);
        $this->care->allows('is_live_website')->andReturn(true);
        $this->care->allows('domain')->andReturn('sam.anypress.example');
        $this->care->allows('packages')->andReturn([
            ['product_id' => 402, 'title' => 'Care Plus', 'settings' => [], 'variations' => [
                ['id' => 43, 'price' => 19900, 'interval' => 'monthly', 'checkout_url' => 'https://shop.example/?item_id=43'],
            ]],
        ]);
        $this->care->allows('services')->andReturn([
            ['product_id' => 501, 'title' => 'Set up my site for me', 'settings' => [], 'variations' => [['id' => 51, 'price' => 29900, 'interval' => '', 'checkout_url' => 'https://shop.example/?item_id=51']]],
            ['product_id' => 502, 'title' => 'Extra hour for care customers', 'settings' => ['product_type' => 'service', 'service_care_only' => 'yes'], 'variations' => [['id' => 52, 'price' => 10000, 'interval' => '', 'checkout_url' => 'https://shop.example/?item_id=52']]],
        ]);

        $this->call = new FluentCartCareCall($this->store, $this->care);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    public function test_pay_links_are_for_packages_and_one_time_help_but_not_the_care_only_hour(): void
    {
        $this->assertSame([43 => 'Care Plus · $199 a month', 51 => 'Set up my site for me · $299'], array_map(fn ($offer) => $offer['label'], $this->call->offers()));
        $this->assertSame([900 => 'sam.anypress.example · sam@anypress.example'], $this->call->live_websites(), 'only live websites');
    }

    public function test_a_pay_link_made_on_the_care_page_lets_that_website_check_out_for_14_days(): void
    {
        $_GET = ['pay_website' => '900', 'pay_offer' => '43', '_wpnonce' => 'good'];
        $link = $this->call->requested_link(1000);

        $this->assertSame(1000 + 14 * 86400, $link['until']);
        $this->assertStringStartsWith('https://shop.example/?item_id=43&anypress_website=900&anypress_care_until=1210600&anypress_care_pay=', $link['url']);

        // The customer follows it: FluentCart's checkout page has the cart hash, and the link's args.
        parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $args);
        $_GET = $args + ['fct_cart_hash' => 'abc'];
        $this->call->remember_link();
        $this->cart[FluentCartEmailPacks::CART_KEY] = 900;

        $this->assertTrue($this->call->paid_by_link((object) [], [402], 2000));
        $this->assertFalse($this->call->paid_by_link((object) [], [402, 501], 2000), 'not for something else as well');
        $this->assertFalse($this->call->paid_by_link((object) [], [402], 1000 + 15 * 86400), 'ended');
    }

    public function test_a_pay_link_needs_the_nonce_and_a_live_website(): void
    {
        $_GET = ['pay_website' => '900', 'pay_offer' => '43', '_wpnonce' => 'bad'];
        $this->assertNull($this->call->requested_link());

        $_GET = ['pay_website' => '901', 'pay_offer' => '43', '_wpnonce' => 'good'];
        $this->assertNull($this->call->requested_link());

        $_GET = ['pay_website' => '900', 'pay_offer' => '52', '_wpnonce' => 'good'];
        $this->assertNull($this->call->requested_link(), 'the care-only hour needs no link');
    }

    public function test_a_checkout_without_a_link_clears_an_old_one(): void
    {
        $this->cart[FluentCartCareCall::CART_KEY] = ['signature' => 'old', 'until' => 1];
        $_GET = ['fct_cart_hash' => 'abc'];
        $this->call->remember_link();

        $this->assertArrayNotHasKey(FluentCartCareCall::CART_KEY, array_filter($this->cart));
    }
}

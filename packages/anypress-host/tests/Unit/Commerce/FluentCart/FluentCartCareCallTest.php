<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use AnyPressHost\Commerce\FluentCart\FluentCartCare;
use AnyPressHost\Commerce\FluentCart\FluentCartCareCall;
use AnyPressHost\Commerce\FluentCart\FluentCartStore;
use AnyPressHost\Core\Tenant;

/** AnyPress > Care makes checkout links for one website and one package, to email a customer. */
class FluentCartCareCallTest extends TestCase
{
    private $store;
    private $care;
    private FluentCartCareCall $call;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\stubs([
            'sanitize_text_field', 'sanitize_key', 'wp_unslash',
            'absint' => fn ($v) => abs((int) $v),
            'current_user_can' => true,
            'wp_verify_nonce' => fn ($nonce, $action) => $nonce === 'good' && $action === FluentCartCareCall::LINK_ACTION,
        ]);
        Functions\when('add_query_arg')->alias(fn ($key, $value, $url) => $url . '&' . $key . '=' . $value);

        $this->store = Mockery::mock(FluentCartStore::class);
        $this->store->allows('find_subscription_meta')->with(Tenant::TENANT_STATE, [])->andReturn([900 => Tenant::READY, 901 => Tenant::DELETED]);
        $this->store->allows('get_subscription_customer')->andReturn(['email' => 'sam@anypress.example']);

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

    public function test_a_link_made_on_the_care_page_opens_checkout_for_that_website(): void
    {
        $_GET = ['pay_website' => '900', 'pay_offer' => '43', '_wpnonce' => 'good'];
        $link = $this->call->requested_link();

        $this->assertSame('https://shop.example/?item_id=43&anypress_website=900', $link['url']);
        $this->assertSame('Care Plus · $199 a month', $link['offer']);
    }

    public function test_a_link_needs_the_nonce_and_a_live_website(): void
    {
        $_GET = ['pay_website' => '900', 'pay_offer' => '43', '_wpnonce' => 'bad'];
        $this->assertNull($this->call->requested_link());

        $_GET = ['pay_website' => '901', 'pay_offer' => '43', '_wpnonce' => 'good'];
        $this->assertNull($this->call->requested_link());

        $_GET = ['pay_website' => '900', 'pay_offer' => '52', '_wpnonce' => 'good'];
        $this->assertNull($this->call->requested_link(), 'the care-only hour is not offered');
    }
}

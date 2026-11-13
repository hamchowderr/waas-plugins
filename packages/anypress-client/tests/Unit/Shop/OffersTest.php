<?php

namespace Tests\Unit\Shop;

use AnyPressClient\Shop\Offers;
use Tests\Unit\TestCase;

class OffersTest extends TestCase
{
    public function test_clean_rules_keeps_whole_rules_and_each_product_once(): void
    {
        $rules = Offers::clean_rules([
            ['product' => '12', 'page' => '40'],
            ['product' => 0, 'page' => 41],
            ['product' => 13],
            ['product' => 12, 'page' => 42],
            'junk',
            ['product' => 14, 'page' => 43],
        ]);

        $this->assertSame([['product' => 12, 'page' => 40], ['product' => 14, 'page' => 43]], $rules);
        $this->assertSame([], Offers::clean_rules('not a list'));
    }

    public function test_the_first_product_in_the_order_with_a_rule_decides(): void
    {
        $rules = [['product' => 20, 'page' => 2], ['product' => 10, 'page' => 1]];

        $this->assertSame(1, Offers::page_for($rules, [10, 20]));
        $this->assertSame(2, Offers::page_for($rules, [30, 20, 10]));
        $this->assertNull(Offers::page_for($rules, [30]));
        $this->assertNull(Offers::page_for([], [10]));
    }

    public function test_pages_lists_each_page_once(): void
    {
        $this->assertSame([5, 6], Offers::pages([['product' => 1, 'page' => 5], ['product' => 2, 'page' => 6], ['product' => 3, 'page' => 5]]));
    }

    public function test_move_query_carries_the_receipt_query_to_the_offer_page(): void
    {
        $from = 'https://shop.example/receipt/?method=stripe&trx_hash=abc-123&fct_redirect=yes';

        $this->assertSame('https://shop.example/special/?method=stripe&trx_hash=abc-123&fct_redirect=yes', Offers::move_query($from, 'https://shop.example/special/'));
        $this->assertSame('https://shop.example/?page_id=9&method=stripe&trx_hash=abc-123&fct_redirect=yes', Offers::move_query($from, 'https://shop.example/?page_id=9'));
        $this->assertSame('https://shop.example/a/?trx_hash=keep&method=stripe&fct_redirect=yes#top', Offers::move_query($from, 'https://shop.example/a/?trx_hash=keep#top'));
        $this->assertSame('https://shop.example/a/', Offers::move_query('https://shop.example/receipt/', 'https://shop.example/a/'));
    }

    public function test_add_hash_keeps_the_receipt_on_links_to_this_site_only(): void
    {
        $button = static fn ($href) => '<div class="wp-block-button"><a class="wp-block-button__link" href="' . $href . '">No Thanks</a></div>';

        $this->assertSame($button('https://shop.example/thanks/?trx_hash=abc-1'), Offers::add_hash($button('https://shop.example/thanks/'), 'abc-1', 'shop.example'));
        $this->assertSame($button('/thanks/?a=1&amp;trx_hash=abc-1'), Offers::add_hash($button('/thanks/?a=1'), 'abc-1', 'shop.example'));
        $this->assertSame($button('https://elsewhere.example/'), Offers::add_hash($button('https://elsewhere.example/'), 'abc-1', 'shop.example'));
        $this->assertSame($button('https://shop.example/?fluent-cart=instant_checkout&amp;item_id=3'), Offers::add_hash($button('https://shop.example/?fluent-cart=instant_checkout&amp;item_id=3'), 'abc-1', 'shop.example'));
        $this->assertSame($button('https://shop.example/t/?trx_hash=other'), Offers::add_hash($button('https://shop.example/t/?trx_hash=other'), 'abc-1', 'shop.example'));
        $this->assertSame($button('https://shop.example/t/'), Offers::add_hash($button('https://shop.example/t/'), '"><script>', 'shop.example'));
    }

    public function test_offer_markup_uses_fluentcarts_button_and_receipt(): void
    {
        foreach (['__', 'esc_html__', 'esc_attr__'] as $fn) {
            \Brain\Monkey\Functions\when($fn)->returnArg(1);
        }
        \Brain\Monkey\Functions\when('esc_html')->alias(fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES));
        \Brain\Monkey\Functions\when('esc_url')->returnArg(1);
        $markup = Offers::offer_markup('Gift Wrap', 77, 'https://shop.example/thanks/');

        $this->assertStringContainsString('[fluent_cart_checkout_button variation_id="77" instant_checkout="yes"', $markup);
        $this->assertStringContainsString('href="https://shop.example/thanks/"', $markup);
        $this->assertStringContainsString('Gift Wrap', $markup);
        $this->assertTrue(Offers::shows_receipt($markup));
        $this->assertFalse(Offers::shows_receipt('<!-- wp:paragraph --><p>Thanks!</p><!-- /wp:paragraph -->'));
        $this->assertDoesNotMatchRegularExpression('/(?<!&)#[0-9a-f]{3,6}\b|\d+px/i', $markup);
    }
}

<?php

namespace Tests\Unit\Shop;

use AnyPressClient\Shop\EddShortcodes;
use Tests\Unit\TestCase;

class EddShortcodesTest extends TestCase
{
    public function test_page_shortcodes_become_fluentcart_ones(): void
    {
        $out = EddShortcodes::rewrite("<p>Pay</p>\n[download_checkout]\n[download_cart]\n[edd_login redirect=\"/\"]", []);

        $this->assertSame("<p>Pay</p>\n[fluent_cart_checkout]\n[fluent_cart_cart]\n[fluent_cart_login_form]", $out['content']);
        $this->assertCount(3, $out['replacements']);
        $this->assertSame([], $out['missing']);
    }

    public function test_buy_button_needs_the_copied_product(): void
    {
        $out = EddShortcodes::rewrite('[purchase_link id="12" text="Buy"] [purchase_link id="99"]', [12 => 340]);

        $this->assertSame('[fluent_cart_product_card product_id="340"] [purchase_link id="99"]', $out['content']);
        $this->assertSame(['[purchase_link id="99"]'], $out['missing']);
    }

    public function test_account_shortcodes_give_one_profile(): void
    {
        $out = EddShortcodes::rewrite("[purchase_history]\n[download_history]\n[edd_profile_editor]", []);

        $this->assertSame("[fluent_cart_customer_profile]\n\n", $out['content']);
    }

    public function test_blocks_become_shortcode_blocks(): void
    {
        $content = "<!-- wp:edd/checkout /-->\n<!-- wp:edd/buy-button {\"download_id\":12} /-->\n<!-- wp:edd/order-history {\"x\":1} --><div>old</div><!-- /wp:edd/order-history -->\n<!-- wp:edd/user-downloads /-->";
        $out = EddShortcodes::rewrite($content, [12 => 340]);

        $this->assertStringContainsString("<!-- wp:shortcode -->\n[fluent_cart_checkout]\n<!-- /wp:shortcode -->", $out['content']);
        $this->assertStringContainsString('[fluent_cart_product_card product_id="340"]', $out['content']);
        $this->assertSame(1, substr_count($out['content'], '[fluent_cart_customer_profile]'));
        $this->assertStringNotContainsString('wp:edd/', $out['content']);
        $this->assertStringNotContainsString('<div>old</div>', $out['content']);
    }

    public function test_escaped_and_other_shortcodes_stay(): void
    {
        $content = '[[download_checkout]] [downloads_extra] [download_discounts]';
        $out = EddShortcodes::rewrite($content, []);

        $this->assertSame($content, $out['content']);
        $this->assertSame([], $out['replacements']);
    }

    public function test_switched_content_has_nothing_left_to_swap(): void
    {
        $once = EddShortcodes::rewrite('[downloads category="ebooks"] [edd_receipt]', [])['content'];
        $again = EddShortcodes::rewrite($once, []);

        $this->assertSame('[fluent_cart_products] [fluent_cart_receipt]', $once);
        $this->assertSame([], $again['replacements']);
    }
}

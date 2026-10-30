<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSHost\Features\AccountStyles;

class AccountStylesTest extends TestCase
{
    public function test_prints_once_through_the_style_queue_with_the_bundled_font(): void
    {
        Functions\when('esc_url')->returnArg();
        $inline = [];
        $registered = [];
        $printed = [];
        Functions\when('wp_register_style')->alias(function ($handle, $src) use (&$registered) {
            $registered[] = [$handle, $src];
            return true;
        });
        Functions\when('wp_print_styles')->alias(function ($handle) use (&$printed) {
            $printed[] = $handle;
            return [];
        });
        Functions\when('wp_add_inline_style')->alias(function ($handle, $css) use (&$inline) {
            $inline[$handle] = $css;
            return true;
        });

        ob_start();
        AccountStyles::print_once();
        AccountStyles::print_once();
        $echoed = ob_get_clean();

        $this->assertSame('', $echoed, 'nothing is echoed outside the style queue');
        $this->assertSame([['waas-account', false]], $registered);
        $this->assertSame(['waas-account'], $printed);
        $this->assertStringContainsString('@font-face{font-family:"Hanken Grotesk"', $inline['waas-account']);
        $this->assertStringContainsString('waas-host/assets/fonts/hanken-grotesk-latin.woff2', $inline['waas-account']);
        $this->assertStringNotContainsString('googleapis', $inline['waas-account']);
    }
}

<?php

namespace Tests\Unit\Forms;

use Tests\Unit\TestCase;
use WaaSClient\Forms\PageSwitcher;

class PageSwitcherTest extends TestCase
{
    private function switcher(): PageSwitcher
    {
        return new PageSwitcher([
            'contactform7' => [['id' => 8, 'hash' => 'a8a5f43c0ffee1234', 'title' => 'CF7 Kitchen Sink', 'fluent' => 3]],
            'wpforms' => [['id' => 15, 'fluent' => 4]],
            'ninja_forms' => [['id' => 2, 'fluent' => 5]],
        ]);
    }

    public function test_cf7_shortcodes_by_hash_id_and_title(): void
    {
        $content = "[contact-form-7 id=\"a8a5f43\" title=\"CF7 Kitchen Sink\"]\n\n<!-- legacy id form -->\n[contact-form-7 id=\"8\"]\n[contact-form title=\"CF7 Kitchen Sink\"]";

        $result = $this->switcher()->rewrite($content);

        $this->assertSame("[fluentform id=\"3\"]\n\n<!-- legacy id form -->\n[fluentform id=\"3\"]\n[fluentform id=\"3\"]", $result['content']);
        $this->assertCount(3, $result['replacements']);
        $this->assertSame([], $result['missing']);
    }

    public function test_blocks_become_the_fluent_forms_block(): void
    {
        $content = '<!-- wp:contact-form-7/contact-form-selector {"id":8,"hash":"a8a5f43","title":"CF7 Kitchen Sink"} -->'
            . "\n" . '<div class="wp-block-contact-form-7-contact-form-selector">[contact-form-7 id="a8a5f43" title="CF7 Kitchen Sink"]</div>'
            . "\n" . '<!-- /wp:contact-form-7/contact-form-selector -->'
            . "\n\n" . '<!-- wp:wpforms/form-selector {"formId":"15"} /-->'
            . "\n\n" . '<!-- wp:ninja-forms/form {"formID":2} /-->';

        $result = $this->switcher()->rewrite($content);

        $this->assertSame(
            '<!-- wp:fluentfom/guten-block {"formId":"3"} /-->' . "\n\n" . '<!-- wp:fluentfom/guten-block {"formId":"4"} /-->' . "\n\n" . '<!-- wp:fluentfom/guten-block {"formId":"5"} /-->',
            $result['content']
        );
        $this->assertSame(['contactform7', 'wpforms', 'ninja_forms'], array_column($result['replacements'], 'source'));
    }

    public function test_wpforms_and_ninja_shortcodes(): void
    {
        $content = '[wpforms id="15" title="false"] [ninja_form id=2] [ninja_forms id="2"] [ninja_forms_display_form id=2]';

        $this->assertSame(
            '[fluentform id="4"] [fluentform id="5"] [fluentform id="5"] [fluentform id="5"]',
            $this->switcher()->rewrite($content)['content']
        );
    }

    public function test_unknown_forms_and_escaped_shortcodes_are_left_alone(): void
    {
        $content = '[wpforms id="99"] [[wpforms id="15"]] [contact-form-7 id="deadbee"] [wpformsx id="15"]';

        $result = $this->switcher()->rewrite($content);

        $this->assertSame($content, $result['content']);
        $this->assertSame(['[wpforms id="99"]', '[contact-form-7 id="deadbee"]'], $result['missing']);
    }

    public function test_a_numeric_cf7_hash_is_read_as_a_hash_first(): void
    {
        $switcher = new PageSwitcher(['contactform7' => [
            ['id' => 1234567, 'hash' => 'ffffffff', 'fluent' => 7],
            ['id' => 20, 'hash' => '1234567abc', 'fluent' => 8],
        ]]);

        $this->assertSame('[fluentform id="8"]', $switcher->rewrite('[contact-form-7 id="1234567"]')['content']);
    }

    public function test_revert_puts_the_originals_back_in_order(): void
    {
        $original = "Intro [contact-form-7 id=\"a8a5f43\"]\n[contact-form-7 id=\"8\"] and <!-- wp:wpforms/form-selector {\"formId\":\"15\"} /-->";
        $result = $this->switcher()->rewrite($original);

        $this->assertSame($original, PageSwitcher::revert($result['content'], $result['replacements']));

        // The owner edited the page after the switch: their text stays, the forms go back.
        $edited = 'New heading. ' . $result['content'] . ' Footer.';
        $this->assertSame('New heading. ' . $original . ' Footer.', PageSwitcher::revert($edited, $result['replacements']));
    }

    public function test_a_second_rewrite_finds_nothing_to_do(): void
    {
        $first = $this->switcher()->rewrite('[wpforms id="15"]');
        $second = $this->switcher()->rewrite($first['content']);

        $this->assertSame($first['content'], $second['content']);
        $this->assertSame([], $second['replacements']);
    }

    public function test_attributes_parse_like_wordpress(): void
    {
        $this->assertSame(['id' => '8', 'title' => 'A "b"', 'html_class' => 'x'], PageSwitcher::attributes(' id=8 title=\'A "b"\' HTML_CLASS="x"'));
    }
}

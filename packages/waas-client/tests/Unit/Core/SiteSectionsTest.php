<?php

namespace Tests\Unit\Core;

use Tests\Unit\TestCase;
use WaaSClient\Core\SiteSections;

class SiteSectionsTest extends TestCase
{
    /** What parse_blocks returns for a heading, a group and a paragraph, with the newlines between them. */
    private function blocks(): array
    {
        return [
            $this->block('core/heading', '<h2>Welcome</h2>'),
            $this->gap(),
            $this->block('core/group', '<div></div>', [$this->block('core/paragraph', '<p>We bake &amp; deliver   every day.</p>')], ['metadata' => ['name' => 'Opening hours']]),
            $this->gap(),
            $this->block('core/paragraph', '<p>Bye</p>'),
        ];
    }

    public function test_sections_skip_the_whitespace_between_blocks(): void
    {
        $this->assertSame([0, 2, 4], SiteSections::positions($this->blocks()));
    }

    public function test_outline_names_each_section(): void
    {
        $this->assertSame([
            ['section' => 0, 'block' => 'core/heading', 'text' => 'Welcome'],
            ['section' => 1, 'block' => 'core/group', 'name' => 'Opening hours', 'text' => 'We bake & deliver every day.'],
            ['section' => 2, 'block' => 'core/paragraph', 'text' => 'Bye'],
        ], SiteSections::outline($this->blocks()));
    }

    public function test_replace_and_remove_address_sections_not_raw_indexes(): void
    {
        $new = [$this->block('core/paragraph', '<p>New</p>')];

        $replaced = SiteSections::replace($this->blocks(), 1, $new);
        $this->assertSame('<p>New</p>', $replaced[2]['innerHTML']);
        $this->assertCount(5, $replaced);

        $removed = SiteSections::remove($this->blocks(), 2);
        $this->assertSame(['core/heading', 'core/group'], array_column(SiteSections::outline($removed), 'block'));

        $this->assertNull(SiteSections::replace($this->blocks(), 3, $new));
        $this->assertNull(SiteSections::remove($this->blocks(), -1));
    }

    public function test_insert_goes_before_a_section_or_at_the_end(): void
    {
        $new = [$this->block('core/image', '<figure></figure>')];

        $this->assertSame('core/image', SiteSections::insert($this->blocks(), 1, $new)[2]['blockName']);
        $this->assertSame('core/image', SiteSections::insert($this->blocks(), null, $new)[5]['blockName']);
        $this->assertSame('core/image', SiteSections::insert($this->blocks(), 99, $new)[5]['blockName']);
        $this->assertNull(SiteSections::insert($this->blocks(), -1, $new));
    }

    public function test_long_text_is_cut_short(): void
    {
        $outline = SiteSections::outline([$this->block('core/paragraph', '<p>' . str_repeat('word ', 60) . '</p>')]);

        $this->assertLessThanOrEqual(120, mb_strlen($outline[0]['text']));
        $this->assertStringEndsWith('…', $outline[0]['text']);
    }

    private function block(string $name, string $html, array $inner = [], array $attrs = []): array
    {
        return ['blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $inner, 'innerHTML' => $html, 'innerContent' => [$html]];
    }

    private function gap(): array
    {
        return ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n\n", 'innerContent' => ["\n\n"]];
    }
}

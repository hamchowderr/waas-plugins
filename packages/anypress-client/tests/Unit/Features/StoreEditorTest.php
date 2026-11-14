<?php

namespace Tests\Unit\Features;

use Tests\Unit\TestCase;
use AnyPressClient\Features\StoreEditor;

/** The new-page pattern window keeps this website's kit's starter pages and the shared ones. */
class StoreEditorTest extends TestCase
{
    public function test_another_kits_starter_page_is_left_out(): void
    {
        $this->assertTrue(StoreEditor::is_other_kit('About page: Assay kit', 'Circuit'));
        $this->assertTrue(StoreEditor::is_other_kit('Home page: Hearth kit, food and drink', 'Circuit'));
    }

    public function test_this_kits_and_shared_starter_pages_stay(): void
    {
        $this->assertFalse(StoreEditor::is_other_kit('Home page: Circuit kit, electricians', 'Circuit'));
        $this->assertFalse(StoreEditor::is_other_kit('About page: Circuit kit', 'circuit'));
        $this->assertFalse(StoreEditor::is_other_kit('About page', 'Circuit'));
        $this->assertFalse(StoreEditor::is_other_kit('Home page: food and drink', ''));
    }

    public function test_with_no_kit_on_every_kits_page_is_left_out(): void
    {
        $this->assertTrue(StoreEditor::is_other_kit('Contact page: Ledger kit', ''));
    }
}

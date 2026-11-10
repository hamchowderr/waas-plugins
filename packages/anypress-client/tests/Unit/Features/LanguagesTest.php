<?php

namespace Tests\Unit\Features;

use AnyPressClient\Features\Languages;
use Brain\Monkey\Functions;
use Tests\Unit\TestCase;

class LanguagesTest extends TestCase
{
    private string $plugin = '';

    protected function tearDown(): void
    {
        if ($this->plugin !== '') {
            @unlink($this->plugin);
            @rmdir(dirname($this->plugin));
        }
        parent::tearDown();
    }

    private function install(): void
    {
        $this->plugin = WP_PLUGIN_DIR . '/' . Languages::PLUGIN;
        @mkdir(dirname($this->plugin), 0777, true);
        touch($this->plugin);
    }

    public function test_polylang_joins_the_plan_only_while_languages_is_on_and_installed(): void
    {
        Functions\when('get_option')->justReturn(['on' => true, 'languages' => ['es_ES']]);
        $this->assertSame(['x.php'], Languages::with_polylang(['x.php']), 'not installed');

        $this->install();
        $this->assertSame(['x.php', Languages::PLUGIN], Languages::with_polylang(['x.php']));
        $this->assertSame([Languages::PLUGIN], Languages::with_polylang([Languages::PLUGIN]));

        Functions\when('get_option')->justReturn(['on' => false]);
        $this->assertSame(['x.php'], Languages::with_polylang(['x.php']));
    }

    public function test_off_and_not_ready_by_default(): void
    {
        Functions\when('get_option')->justReturn(false);
        $this->install();
        $this->assertFalse(Languages::is_on());
        $this->assertFalse(Languages::is_ready());
        $this->assertSame(['on' => false, 'languages' => [], 'ready' => false], Languages::state());
    }
}

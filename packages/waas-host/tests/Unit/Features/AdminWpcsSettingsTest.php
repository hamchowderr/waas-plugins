<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\WPCSService;
use WaaSHost\Features\AdminWpcsSettings;

class AdminWpcsSettingsTest extends TestCase
{
    private AdminWpcsSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('sanitize_text_field')->alias('trim');
        $this->settings = new AdminWpcsSettings(Mockery::mock(WPCSService::class), Mockery::mock(ConfigService::class));
    }

    public function test_a_records_are_saved_as_a_clean_list(): void
    {
        $this->assertSame('203.0.113.10, 203.0.113.11', $this->settings->sanitize_dns_a_records("203.0.113.10\n203.0.113.11, nope"));
        $this->assertSame('', $this->settings->sanitize_dns_a_records('nope'));
    }

    /** @dataProvider cname_targets */
    public function test_cname_target_must_be_a_hostname(string $input, string $saved): void
    {
        $this->assertSame($saved, $this->settings->sanitize_dns_cname_target($input));
    }

    public function cname_targets(): array
    {
        return [
            'hostname' => ['Stores.Otaku.Example', 'stores.otaku.example'],
            'trailing dot' => ['stores.otaku.example.', 'stores.otaku.example'],
            'url' => ['https://stores.otaku.example', ''],
            'markup' => ['<b>x</b>.example', ''],
            'empty' => ['', ''],
        ];
    }
}

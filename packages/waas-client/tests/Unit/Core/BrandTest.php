<?php

namespace Tests\Unit\Core;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSClient\Core\Brand;

class BrandTest extends TestCase
{
    private const FILE = 'waas-client/index.php';

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('WAAS_CLIENT_BASENAME')) {
            define('WAAS_CLIENT_BASENAME', self::FILE);
        }
        Functions\when('__')->returnArg();
        Functions\when('wp_strip_all_tags')->alias('strip_tags');
    }

    private function plugins(): array
    {
        return [
            self::FILE => ['Name' => 'AnyPress Client', 'Title' => 'AnyPress Client', 'Author' => 'Otaku Solutions', 'AuthorName' => 'Otaku Solutions', 'AuthorURI' => 'https://otakusolutions.io', 'PluginURI' => 'https://github.com/hamchowderr/waas-plugins', 'Description' => 'Runs on each AnyPress website.'],
            'other/other.php' => ['Name' => 'Other'],
        ];
    }

    public function test_anypress_by_default(): void
    {
        Functions\when('get_option')->justReturn('');

        $this->assertSame('AnyPress', Brand::name());
        $this->assertSame('AnyPress Client', Brand::plugin_name());
        $this->assertSame($this->plugins(), Brand::plugins_list($this->plugins()));
    }

    public function test_a_resellers_website_goes_by_the_resellers_brand(): void
    {
        Functions\when('get_option')->justReturn('  KnowBS  ');

        $this->assertSame('KnowBS', Brand::name());
        $this->assertSame('KnowBS Client', Brand::plugin_name());
        $plugins = Brand::plugins_list($this->plugins());
        $this->assertSame('KnowBS Client', $plugins[self::FILE]['Name']);
        $this->assertSame('KnowBS', $plugins[self::FILE]['Author']);
        $this->assertSame('', $plugins[self::FILE]['AuthorURI']);
        $this->assertSame('', $plugins[self::FILE]['PluginURI']);
        $this->assertStringNotContainsString('AnyPress', $plugins[self::FILE]['Description']);
        $this->assertSame(['Name' => 'Other'], $plugins['other/other.php']);
    }

    public function test_markup_in_the_brand_is_dropped(): void
    {
        Functions\when('get_option')->justReturn('<b>Acme</b>');

        $this->assertSame('Acme', Brand::name());
    }
}

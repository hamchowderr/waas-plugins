<?php

namespace Tests\Unit;

/** H6: the plugin header tells WordPress what the plugin needs and how it is licensed. */
class PluginHeaderTest extends TestCase
{
    public function test_the_plugin_header_declares_requirements_license_and_text_domain(): void
    {
        $index = file_get_contents(dirname(__DIR__, 2) . '/index.php');
        preg_match('#/\*\s*\nPlugin Name:(.*?)\*/#s', $index, $header);
        $this->assertNotEmpty($header);

        $this->assertMatchesRegularExpression('/^Requires PHP: 8\.1$/m', $header[0]);
        $this->assertMatchesRegularExpression('/^Requires at least: \d+\.\d+$/m', $header[0]);
        $this->assertMatchesRegularExpression('/^License: GPL-3\.0-or-later$/m', $header[0]);

        preg_match("/define\( 'WPCS_WAAS_HOST_TEXTDOMAIN', '([^']+)' \)/", $index, $domain);
        $this->assertMatchesRegularExpression('/^Text Domain: ' . preg_quote($domain[1], '/') . '$/m', $header[0]);
    }
}

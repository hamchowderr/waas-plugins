<?php

namespace Tests\Unit;

/**
 * The plugin file loads its files by absolute path. A relative require ('vendor/autoload.php') is
 * resolved against the current directory first, so WP-CLI run from inside another plugin's folder
 * loads that plugin's autoloader and WordPress fails with "class not found".
 */
class PluginBootstrapPathTest extends TestCase
{
    public function test_the_plugin_file_requires_files_by_absolute_path(): void
    {
        $index = file_get_contents(dirname(__DIR__, 2) . '/index.php');
        preg_match_all('/\b(?:require|include)(?:_once)?\b\s*\(?\s*([^;]+);/', $index, $matches);
        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $path) {
            $this->assertMatchesRegularExpression('/^(__DIR__|ABSPATH)\b/', trim($path), "Relative path in index.php: $path");
        }
    }
}

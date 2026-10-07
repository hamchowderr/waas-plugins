<?php

namespace Tests\Unit;

/**
 * Stores never update waas-client themselves: it comes with the template version and changes when
 * the store moves to a newer version (waas-hw4). The Update URI keeps wordpress.org from offering
 * another plugin with the same slug.
 */
class NoSelfUpdateTest extends TestCase
{
    public function test_the_plugin_has_no_updater_and_keeps_its_update_uri(): void
    {
        $root = dirname(__DIR__, 2);
        $index = file_get_contents($root . '/index.php');

        $this->assertMatchesRegularExpression('/^Update URI: wpcs-waas-client$/m', $index);
        $this->assertStringNotContainsString('Updater', preg_replace('#//.*#', '', $index));
        $this->assertDirectoryDoesNotExist($root . '/src/Updater');
        $this->assertStringNotContainsString('Daxez/waas-wpcs/releases', $this->source($root . '/src'));
    }

    private function source(string $dir): string
    {
        $all = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $all .= file_get_contents($file->getPathname());
        }
        return $all;
    }
}

<?php

namespace Tests\Unit;

/**
 * The Update URI keeps wordpress.org from offering another "anypress-payments" over ours, and points
 * WordPress at our updater (waas-hw4). The release build stamps the version into both places.
 */
class PluginHeaderTest extends TestCase
{
    public function test_the_header_has_our_update_uri_and_the_same_version_as_the_constant(): void
    {
        $main = file_get_contents(dirname(__DIR__, 2) . '/anypress-payments.php');
        $this->assertMatchesRegularExpression('/^ \* Update URI: anypress-payments$/m', $main);

        preg_match('/^ \* Version: (\S+)$/m', $main, $header);
        preg_match("/define\('ANYPRESS_PAYMENTS_VERSION', '([^']+)'\)/", $main, $constant);
        $this->assertSame($header[1], $constant[1]);
    }

    public function test_the_copy_of_the_shared_updater_is_in_sync(): void
    {
        exec(escapeshellarg(dirname(__DIR__, 4) . '/scripts/sync-updater.sh') . ' --check 2>&1', $out, $status);
        $this->assertSame(0, $status, implode("\n", $out));
    }
}

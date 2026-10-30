<?php

namespace Tests\Unit;

use AnyPressExport\Preflight;
use PHPUnit\Framework\TestCase;

final class PreflightTest extends TestCase
{
    private static function layout(array $site): array
    {
        return Preflight::layout($site + [
            'home' => 'https://old.example',
            'siteurl' => 'https://old.example',
            'contentDir' => '/var/www/wp-content',
            'contentUrl' => 'https://old.example/wp-content',
            'uploadsDir' => '/var/www/wp-content/uploads',
            'uploadsConstant' => null,
            'uploadPath' => '',
        ]);
    }

    public function testAnOrdinarySiteHasNothingToFlag(): void
    {
        $this->assertSame(['blockers' => [], 'warnings' => []], self::layout([]));
        $this->assertSame([], Preflight::requirements(false, true, true));
        $this->assertSame([], Preflight::notes(['blockers' => [], 'warnings' => [], 'files' => ['complete' => true]]));
    }

    public function testRefusesEarlyWithoutCurlZlibOrOnMultisite(): void
    {
        $blockers = Preflight::requirements(true, false, false);
        $this->assertCount(3, $blockers);
        $this->assertStringContainsString('Multisite', $blockers[0]);
        $this->assertStringContainsString('zlib', $blockers[1]);
        $this->assertStringContainsString('cURL', $blockers[2]);
    }

    public function testRefusesWhenMediaLivesOutsideWpContent(): void
    {
        $layout = self::layout(['uploadsDir' => '/var/www/media', 'uploadsConstant' => '../media']);
        $this->assertCount(1, $layout['blockers']);
        $this->assertStringContainsString('leave your images and files behind', $layout['blockers'][0]);
        // A sibling folder whose name only starts the same is outside too.
        $this->assertCount(1, self::layout(['uploadsDir' => '/var/www/wp-content-media'])['blockers']);
    }

    public function testWarnsWhenTheMediaFolderIsSetByHandButStillMoves(): void
    {
        $layout = self::layout(['uploadPath' => '/home/old/public_html/wp-content/uploads']);
        $this->assertSame([], $layout['blockers']);
        $this->assertStringContainsString('set by hand', $layout['warnings'][0]);
    }

    public function testRecordsACustomContentAddressAndASiteInAFolder(): void
    {
        $layout = self::layout(['home' => 'https://old.example/blog', 'siteurl' => 'https://old.example/blog/wp', 'contentUrl' => 'https://old.example/app']);
        $this->assertSame('https://old.example/app', $layout['contentUrl']);
        $this->assertSame('https://old.example/blog/wp', $layout['siteUrl']);
        $this->assertStringContainsString('ends in a folder (/blog)', $layout['warnings'][0]);

        $layout = self::layout(['siteurl' => 'https://old.example/wp', 'contentUrl' => 'https://old.example/wp-content']);
        $this->assertArrayNotHasKey('contentUrl', $layout);
        $this->assertSame('https://old.example/wp', $layout['siteUrl']);
        $this->assertStringContainsString('different address', $layout['warnings'][0]);
    }

    public function testListsTheNamesOfNonCoreConstantsAndNeverTheirValues(): void
    {
        $config = <<<'PHP'
<?php
define( 'DB_NAME', 'shop' );
define('DB_PASSWORD', 'hunter2');
define('AUTH_KEY', 'salty');
define( "GF_LICENSE_KEY", 'gf-secret-123' );
\define('WP_ROCKET_KEY', getenv('ROCKET'));
Config::define('ACF_PRO_LICENSE', env('ACF'));
// define('COMMENTED_OUT', 1);
define('WP_DEBUG', false);
define('ANYPRESS_EXPORT_ALLOW_HTTP', true);
define('AS3CF_SETTINGS', serialize(['key' => 'x']));
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
$table_prefix = 'wp_';
PHP;
        $names = Preflight::configConstants($config);
        $this->assertSame(['ACF_PRO_LICENSE', 'AS3CF_SETTINGS', 'GF_LICENSE_KEY', 'WP_ROCKET_KEY'], $names);
        $this->assertStringNotContainsString('secret', json_encode($names));
        $this->assertSame([], Preflight::configConstants('not php at all'));
    }

    public function testRecognisesMailerAndCachePlugins(): void
    {
        $found = Preflight::recognise(['fluent-smtp/fluent-smtp.php', 'wp-rocket/wp-rocket.php', 'woocommerce/woocommerce.php', 'redis-cache/redis-cache.php', 'post-smtp/postman-smtp.php']);
        $this->assertSame(['FluentSMTP', 'Post SMTP'], $found['mailers']);
        $this->assertSame(['WP Rocket', 'Redis Object Cache'], $found['caches']);
    }

    public function testTheDatabasePartAddsUpTheSizes(): void
    {
        $db = new FakeDatabase();
        $db->tables['wp_posts'] = ['columns' => ['ID' => 'int'], 'rows' => [['1'], ['2']], 'avg' => 100];
        $db->behind['views'] = ['wp_report'];
        $report = Preflight::database($db);
        $this->assertSame(200, $report['databaseBytes']);
        $this->assertSame(['wp_report'], $report['views']);
    }

    public function testNotesAreShortGroupsForThePage(): void
    {
        $notes = Preflight::notes([
            'blockers' => [],
            'warnings' => ['Your site\'s address ends in a folder (/blog).'],
            'tablePrefix' => 'wp_',
            'otherTables' => array_map(function ($i) {
                return "legacy_$i";
            }, range(1, 12)),
            'triggers' => ['wp_audit'],
            'configConstants' => ['GF_LICENSE_KEY'],
            'mailers' => ['FluentSMTP'],
            'caches' => ['WP Rocket'],
            'actionScheduler' => ['pending' => 40, 'pastDue' => 12],
            'php' => ['extensions' => ['Core', 'ionCube Loader']],
            'databaseBytes' => 600 * 1048576,
            'files' => ['complete' => true, 'bytes' => 700 * 1048576, 'uploadsBytes' => 500 * 1048576, 'symlinkCount' => 1, 'symlinks' => [['path' => 'uploads/2024', 'target' => '/mnt/media/2024']], 'unreadableCount' => 0],
            'disk' => ['free' => 1024 * 1048576],
        ]);
        $groups = array_column($notes, 'items', 'id');
        $this->assertSame(['before', 'behind', 'constants', 'after'], array_keys($groups));
        $this->assertStringContainsString('1 GB of free space, and the export needs about 1.7 GB', $groups['before'][0]);
        $this->assertStringContainsString('wp anypress-export send', $groups['before'][1]);
        $this->assertSame('Database tables that don\'t start with wp_: legacy_1, legacy_2, legacy_3, legacy_4, legacy_5, legacy_6, legacy_7, legacy_8, legacy_9, legacy_10 and 2 more', $groups['behind'][0]);
        $this->assertSame('Database triggers: wp_audit', $groups['behind'][1]);
        $this->assertSame('Linked folders and files (symbolic links): uploads/2024 → /mnt/media/2024', $groups['behind'][2]);
        $this->assertSame(['GF_LICENSE_KEY'], $groups['constants']);
        $after = implode("\n", $groups['after']);
        foreach (['ends in a folder', 'WP Rocket', 'FluentSMTP', '12 scheduled tasks are overdue here (40 waiting in all)', 'ionCube Loader'] as $text) {
            $this->assertStringContainsString($text, $after);
        }
    }
}

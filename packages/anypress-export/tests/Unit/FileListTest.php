<?php

namespace Tests\Unit;

use AnyPressExport\FileList;
use PHPUnit\Framework\TestCase;

final class FileListTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/anypress-list-' . getmypid() . '-' . mt_rand();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir) . ' ' . escapeshellarg("$this->dir.txt"));
    }

    public function testListsContentButNotCachesBackupsDropInsLinksOrTheWorkFolder(): void
    {
        $dir = $this->dir;
        foreach (['plugins/shop', 'themes/brand', 'uploads/2026/10', 'uploads/anypress-export-abc', 'cache/pages', 'updraft', 'mu-plugins'] as $d) {
            @mkdir("$dir/$d", 0777, true);
        }
        file_put_contents("$dir/plugins/shop/shop.php", '<?php');
        file_put_contents("$dir/themes/brand/style.css", 'body{}');
        file_put_contents("$dir/uploads/2026/10/photo.jpg", str_repeat('x', 1000));
        file_put_contents("$dir/uploads/anypress-export-abc/database.sql", 'secret');
        file_put_contents("$dir/cache/pages/index.html", 'cached');
        file_put_contents("$dir/updraft/backup.zip", 'old');
        file_put_contents("$dir/object-cache.php", '<?php');
        file_put_contents("$dir/mu-plugins/custom.php", '<?php');
        symlink("$dir/plugins", "$dir/linked");
        symlink('/srv/shared/2025', "$dir/uploads/2025");

        $list = "$dir.txt";
        $result = FileList::build($dir, $list, ["$dir/uploads/anypress-export-abc"]);
        $files = file($list, FILE_IGNORE_NEW_LINES);
        sort($files);

        $this->assertSame(['mu-plugins/custom.php', 'plugins/shop/shop.php', 'themes/brand/style.css', 'uploads/2026/10/photo.jpg'], $files);
        $this->assertTrue($result['done']);
        $this->assertSame(4, $result['files']);
        $this->assertSame(5 + 6 + 1000 + 5, $result['bytes']);
        $this->assertSame(1000, $result['uploadsBytes']);
        // Links are left out, and reported with where they point.
        $this->assertSame(2, $result['symlinkCount']);
        $links = $result['symlinks'];
        usort($links, function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });
        $this->assertSame([['path' => 'linked', 'target' => "$dir/plugins"], ['path' => 'uploads/2025', 'target' => '/srv/shared/2025']], $links);
    }

    public function testResumesAcrossRequestsWithinTheTimeBudget(): void
    {
        $dir = $this->dir;
        $expected = [];
        for ($d = 0; $d < 30; $d++) {
            mkdir("$dir/uploads/$d", 0777, true);
            for ($f = 0; $f < 100; $f++) {
                file_put_contents("$dir/uploads/$d/$f.jpg", 'x');
                $expected[] = "uploads/$d/$f.jpg";
            }
        }
        $list = "$dir.txt";
        $cursor = FileList::start();
        $requests = 0;
        while (empty($cursor['done'])) {
            // Each request appends to the list where the last stopped; the cursor goes through the saved state.
            $out = fopen($list, 'ab');
            $cursor = FileList::step($dir, $out, $cursor, [], microtime(true) - 1);
            fclose($out);
            $cursor = json_decode(json_encode($cursor), true);
            $this->assertLessThan(10000, ++$requests);
        }
        $this->assertGreaterThan(3000, $requests, 'an expired budget still lists one entry per request');
        $files = file($list, FILE_IGNORE_NEW_LINES);
        sort($files);
        sort($expected);
        $this->assertSame($expected, $files);
        $this->assertSame(3000, $cursor['files']);
    }

    public function testReportsFilesItCannotRead(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) $this->markTestSkipped('root reads every file');
        mkdir("$this->dir/uploads", 0777, true);
        file_put_contents("$this->dir/uploads/secret.txt", 'x');
        chmod("$this->dir/uploads/secret.txt", 0);
        $result = FileList::build($this->dir, null);
        chmod("$this->dir/uploads/secret.txt", 0644);
        $this->assertSame(0, $result['files']);
        $this->assertSame(['uploads/secret.txt'], $result['unreadable']);
    }
}

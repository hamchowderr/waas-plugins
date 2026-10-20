<?php

namespace Tests\Unit;

use AnyPressExport\FileList;
use PHPUnit\Framework\TestCase;

final class FileListTest extends TestCase
{
    public function testListsContentButNotCachesBackupsDropInsLinksOrTheWorkFolder(): void
    {
        $dir = sys_get_temp_dir() . '/anypress-list-' . getmypid();
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

        $list = "$dir.txt";
        $result = FileList::build($dir, $list, ["$dir/uploads/anypress-export-abc"]);
        $files = file($list, FILE_IGNORE_NEW_LINES);
        sort($files);
        exec('rm -rf ' . escapeshellarg($dir) . ' ' . escapeshellarg($list));

        $this->assertSame(['mu-plugins/custom.php', 'plugins/shop/shop.php', 'themes/brand/style.css', 'uploads/2026/10/photo.jpg'], $files);
        $this->assertSame(['files' => 4, 'bytes' => 5 + 6 + 1000 + 5], $result);
    }
}

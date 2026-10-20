<?php

namespace Tests\Unit;

use AnyPressExport\SqlDump;
use PHPUnit\Framework\TestCase;

final class SqlDumpTest extends TestCase
{
    public function testDumpsStructureAndRowsInBatches(): void
    {
        $db = new FakeDatabase();
        $rows = [];
        for ($i = 1; $i <= SqlDump::BATCH + 3; $i++) $rows[] = [(string) $i, "it's #$i", null, $i === 1 ? "\x00\xff" : ''];
        $db->tables['wp_posts'] = ['columns' => ['ID' => 'bigint(20)', 'title' => 'text', 'parent' => 'bigint(20)', 'data' => 'longblob'], 'rows' => $rows];
        $file = tempnam(sys_get_temp_dir(), 'sql');
        $dump = new SqlDump($db, $file);
        $dump->begin();
        $next = $dump->dumpBatch('wp_posts', 0);
        $this->assertSame(SqlDump::BATCH, $next);
        $this->assertNull($dump->dumpBatch('wp_posts', $next));
        $dump->end();
        $sql = file_get_contents($file);
        unlink($file);

        $this->assertStringStartsWith("-- AnyPress Export\nSET NAMES utf8mb4;", $sql);
        $this->assertSame(1, substr_count($sql, 'DROP TABLE IF EXISTS `wp_posts`;'));
        $this->assertStringContainsString("CREATE TABLE `wp_posts`", $sql);
        $this->assertStringContainsString("('1','it\\'s #1',NULL,0x00ff)", $sql);
        $this->assertStringContainsString("('2','it\\'s #2',NULL,'')", $sql);
        $this->assertStringContainsString("('503','it\\'s #503',NULL,'')", $sql);
        // 503 rows in INSERTs of at most 100.
        $this->assertSame(6, substr_count($sql, 'INSERT INTO `wp_posts` VALUES'));
        $this->assertStringEndsWith("SET FOREIGN_KEY_CHECKS = 1;\n", $sql);
    }
}

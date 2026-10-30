<?php

namespace Tests\Unit;

use AnyPressExport\SqlDump;
use PHPUnit\Framework\TestCase;

final class SqlDumpTest extends TestCase
{
    /** @var string */
    private $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'sql');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** Dumps every table to the end; returns the SQL and each table's cursors. */
    private function dump(FakeDatabase $db, array &$cursors = []): string
    {
        $dump = new SqlDump($db, $this->file);
        $dump->begin();
        $stats = $db->tableStats();
        foreach ($db->tables() as $table) {
            $cursor = [];
            do {
                $cursor = $dump->dumpBatch($table, $cursor, $stats[$table] ?? null);
                $cursor = json_decode(json_encode($cursor), true); // as the saved state keeps it
                $cursors[$table][] = $cursor;
                $this->assertLessThan(10000, count($cursors[$table]));
            } while (!$cursor['done']);
        }
        $dump->end();
        return (string) file_get_contents($this->file);
    }

    /** @return string[] the INSERT statements for a table */
    private static function inserts(string $sql, string $table): array
    {
        return array_values(array_filter(array_map('ltrim', explode(";\n", $sql)), function ($statement) use ($table) {
            return strpos($statement, "INSERT INTO `$table` ") === 0;
        }));
    }

    public function testDumpsStructureAndRowsWithColumnListsInBatches(): void
    {
        $db = new FakeDatabase();
        $rows = [];
        for ($i = 1; $i <= SqlDump::BATCH + 3; $i++) $rows[] = [(string) $i, "it's #$i, 50% off", null, $i === 1 ? "\x00\xff" : ''];
        $db->tables['wp_posts'] = ['columns' => ['ID' => 'bigint(20)', 'title' => 'text', 'parent' => 'bigint(20)', 'data' => 'longblob'], 'rows' => $rows, 'avg' => 40];
        $cursors = [];
        $sql = $this->dump($db, $cursors);

        $this->assertStringStartsWith("-- AnyPress Export\nSET NAMES utf8mb4;", $sql);
        $this->assertSame(1, substr_count($sql, 'DROP TABLE IF EXISTS `wp_posts`;'));
        $this->assertStringContainsString('CREATE TABLE `wp_posts`', $sql);
        $this->assertStringContainsString("INSERT INTO `wp_posts` (`ID`, `title`, `parent`, `data`) VALUES\n('1','it\\'s #1, 50% off',NULL,0x00ff)", $sql);
        $this->assertStringContainsString("('2','it\\'s #2, 50% off',NULL,'')", $sql);
        $this->assertStringContainsString("('503','it\\'s #503, 50% off',NULL,'')", $sql);
        // 503 rows in INSERTs of at most 100.
        $this->assertCount(6, self::inserts($sql, 'wp_posts'));
        $this->assertSame(503, end($cursors['wp_posts'])['rows']);
        $this->assertStringEndsWith("SET FOREIGN_KEY_CHECKS = 1;\n", $sql);
    }

    public function testCapsEachInsertAtAboutOneMegabyte(): void
    {
        $db = new FakeDatabase();
        $rows = [];
        for ($i = 1; $i <= 100; $i++) $rows[] = [(string) $i, str_repeat(chr(97 + $i % 26), 300000)];
        $db->tables['wp_postmeta'] = ['columns' => ['meta_id' => 'bigint(20)', 'meta_value' => 'longtext'], 'rows' => $rows];
        $sql = $this->dump($db);
        $inserts = self::inserts($sql, 'wp_postmeta');
        $this->assertGreaterThanOrEqual(34, count($inserts));
        foreach ($inserts as $insert) $this->assertLessThanOrEqual(SqlDump::INSERT_BYTES + 200, strlen($insert));
        for ($i = 1; $i <= 100; $i++) $this->assertStringContainsString("('$i','" . str_repeat(chr(97 + $i % 26), 300000) . "')", $sql);
    }

    public function testABiggerRowGetsAStatementOfItsOwn(): void
    {
        $db = new FakeDatabase();
        $db->tables['wp_options'] = ['columns' => ['option_id' => 'bigint', 'option_value' => 'longtext'], 'rows' => [['1', 'small'], ['2', str_repeat('x', 3000000)], ['3', 'small too']], 'avg' => 10];
        $inserts = self::inserts($this->dump($db), 'wp_options');
        $this->assertCount(3, $inserts);
        $this->assertStringContainsString("('2','xxx", $inserts[1]);
    }

    public function testSizesBatchesByBytesSoBigRowsAreNotReadManyAtATime(): void
    {
        $db = new FakeDatabase();
        $rows = [];
        for ($i = 1; $i <= 12; $i++) $rows[] = [(string) $i, str_repeat('e', 2 * 1048576)];
        $db->tables['wp_big'] = ['columns' => ['id' => 'int', 'body' => 'longtext'], 'rows' => $rows];
        $small = [];
        for ($i = 1; $i <= 1200; $i++) $small[] = [(string) $i, "row $i"];
        $db->tables['wp_small'] = ['columns' => ['id' => 'int', 'body' => 'text'], 'rows' => $small, 'avg' => 30];
        $cursors = [];
        $this->dump($db, $cursors);

        // Unknown row size: one row first, then as many as fit in the byte budget (at most two 2 MB rows at a time).
        $this->assertSame(1, $db->limits['wp_big'][0]);
        $this->assertLessThanOrEqual(2, max($db->limits['wp_big']));
        $this->assertSame(12, end($cursors['wp_big'])['rows']);
        // Small rows the database reports: full batches from the start.
        $this->assertSame(SqlDump::BATCH, $db->limits['wp_small'][0]);
        $this->assertSame(1200, end($cursors['wp_small'])['rows']);
        $this->assertSame(1, SqlDump::limitFor(PHP_INT_MAX));
        $this->assertSame(SqlDump::BATCH, SqlDump::limitFor(10));
    }

    public function testKeysetPaginationDoesNotSkipRowsWhenEarlierOnesAreDeleted(): void
    {
        $db = new FakeDatabase();
        $rows = [];
        for ($i = 1; $i <= 1100; $i++) $rows[] = [(string) $i, "v$i"];
        $db->tables['wp_options'] = ['columns' => ['option_id' => 'bigint', 'option_value' => 'text'], 'rows' => $rows, 'avg' => 10];
        $dump = new SqlDump($db, $this->file);
        $dump->begin();
        $cursor = $dump->dumpBatch('wp_options', [], ['avgRowLength' => 10]);
        $this->assertSame(['500'], $cursor['after']);
        // Expired transients deleted between two requests: 100 rows already dumped disappear.
        $db->tables['wp_options']['rows'] = array_slice($db->tables['wp_options']['rows'], 100);
        while (!$cursor['done']) $cursor = $dump->dumpBatch('wp_options', $cursor);
        $sql = (string) file_get_contents($this->file);
        for ($i = 1; $i <= 1100; $i++) $this->assertStringContainsString("('$i','v$i')", $sql);
        $this->assertSame(1100, $cursor['rows']);
    }

    public function testCompositeKeysAndTablesWithoutAKey(): void
    {
        $db = new FakeDatabase();
        $rel = [];
        foreach ([3, 1, 2] as $object) foreach ([20, 10] as $term) $rel[] = [(string) $object, (string) $term, '0'];
        $db->tables['wp_term_relationships'] = ['columns' => ['object_id' => 'bigint', 'term_taxonomy_id' => 'bigint', 'term_order' => 'int'], 'rows' => $rel, 'key' => ['object_id', 'term_taxonomy_id']];
        $log = [];
        for ($i = 0; $i < 7; $i++) $log[] = ["line $i"];
        $db->tables['wp_shop_log'] = ['columns' => ['message' => 'text'], 'rows' => $log, 'key' => []];
        $cursors = [];
        $sql = $this->dump($db, $cursors);
        $this->assertSame(6, end($cursors['wp_term_relationships'])['rows']);
        $this->assertSame(['3', '20'], $cursors['wp_term_relationships'][count($cursors['wp_term_relationships']) - 1]['after']);
        $this->assertSame(7, end($cursors['wp_shop_log'])['rows']);
        $this->assertNull(end($cursors['wp_shop_log'])['after']);
        for ($i = 0; $i < 7; $i++) $this->assertStringContainsString("('line $i')", $sql);
    }

    public function testLeavesOutGeneratedColumnsAndWritesSpatialAndBitAsHex(): void
    {
        $db = new FakeDatabase();
        $point = pack('V', 4326) . "\x01\x01\x00\x00\x00" . pack('e', 1.5) . pack('e', -2.25);
        $db->tables['wp_stores'] = [
            'columns' => ['id' => 'int', 'name' => 'varchar(20)', 'slug' => 'varchar(20)', 'location' => 'point', 'open' => 'bit(1)', 'kind' => "enum('shop','depot')"],
            'generated' => ['slug'],
            'rows' => [['1', 'Main', 'main', $point, "\x01", 'shop'], ['2', 'Depot', 'depot', null, "\x00", 'depot']],
            'avg' => 50,
        ];
        $sql = $this->dump($db);
        $this->assertStringContainsString('CREATE TABLE `wp_stores`', $sql);
        $this->assertStringContainsString('`slug` varchar(20)', $sql, 'the structure keeps the generated column');
        $this->assertStringContainsString("INSERT INTO `wp_stores` (`id`, `name`, `location`, `open`, `kind`) VALUES\n('1','Main',0x" . bin2hex($point) . ",0x01,'shop'),\n('2','Depot',NULL,0x00,'depot');", $sql);
    }

    public function testNamesTheCharacterSetTheRowsAreReadInSoLatin1BytesGoBackUnchanged(): void
    {
        $db = new FakeDatabase();
        $db->charset = 'latin1';
        // "café" in latin1, and a euro sign stored as UTF-8 bytes in a latin1 column (double encoded).
        $db->tables['wp_old'] = ['columns' => ['id' => 'int', 'text' => 'varchar(50)'], 'rows' => [['1', "caf\xE9"], ['2', "\xE2\x82\xAC 5"]]];
        $sql = $this->dump($db);
        $this->assertStringStartsWith("-- AnyPress Export\nSET NAMES latin1;", $sql);
        $this->assertStringContainsString("('1','caf\xE9')", $sql);
        $this->assertStringContainsString("('2','\xE2\x82\xAC 5')", $sql);
    }

    public function testFailsClearlyWhenTheDiskIsFull(): void
    {
        ShortWriteStream::register();
        ShortWriteStream::$room = 1000;
        $db = new FakeDatabase();
        $db->tables['wp_posts'] = ['columns' => ['ID' => 'int', 'body' => 'text'], 'rows' => [['1', str_repeat('x', 500)]]];
        $dump = new SqlDump($db, 'shortwrite://database.sql');
        $dump->begin();
        ShortWriteStream::$room = 300;
        $this->expectExceptionMessage('Is the disk full?');
        $dump->dumpBatch('wp_posts', []);
    }
}

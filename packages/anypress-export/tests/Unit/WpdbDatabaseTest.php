<?php

namespace Tests\Unit;

use AnyPressExport\WpdbDatabase;
use PHPUnit\Framework\TestCase;

final class WpdbDatabaseTest extends TestCase
{
    public static function literal(string $value, string $type): string
    {
        return $type === 'varbinary(16)' ? '0x' . bin2hex($value) : "'" . addslashes($value) . "'";
    }

    public function testReadsBatchesByKeyWhenThereIsOne(): void
    {
        $columns = ['option_id' => 'bigint(20) unsigned', 'option_name' => 'varchar(191)'];
        $this->assertSame(
            'SELECT `option_id`, `option_name` FROM `wp_options` ORDER BY `option_id` LIMIT 500',
            WpdbDatabase::select('wp_options', $columns, ['option_id'], null, 0, 500, [self::class, 'literal'])
        );
        $this->assertSame(
            "SELECT `option_id`, `option_name` FROM `wp_options` WHERE `option_id` > '500' ORDER BY `option_id` LIMIT 500",
            WpdbDatabase::select('wp_options', $columns, ['option_id'], ['500'], 500, 500, [self::class, 'literal'])
        );
    }

    public function testCompositeKeysAndBinaryKeys(): void
    {
        $columns = ['object_id' => 'bigint', 'term_taxonomy_id' => 'bigint', 'term_order' => 'int'];
        $this->assertSame(
            "SELECT `object_id`, `term_taxonomy_id`, `term_order` FROM `wp_term_relationships` WHERE (`object_id` > '7' OR (`object_id` = '7' AND `term_taxonomy_id` > '3')) ORDER BY `object_id`, `term_taxonomy_id` LIMIT 100",
            WpdbDatabase::select('wp_term_relationships', $columns, ['object_id', 'term_taxonomy_id'], ['7', '3'], 0, 100, [self::class, 'literal'])
        );
        $this->assertSame(
            'SELECT `id`, `v` FROM `wp_t` WHERE `id` > 0x00ff ORDER BY `id` LIMIT 10',
            WpdbDatabase::select('wp_t', ['id' => 'varbinary(16)', 'v' => 'text'], ['id'], ["\x00\xff"], 0, 10, [self::class, 'literal'])
        );
    }

    public function testTablesWithoutAKeyUseAnOffset(): void
    {
        $this->assertSame(
            'SELECT `message`, `at` FROM `wp_shop_log` LIMIT 500 OFFSET 1000',
            WpdbDatabase::select('wp_shop_log', ['message' => 'text', 'at' => 'datetime'], [], null, 1000, 500, [self::class, 'literal'])
        );
    }

    public function testKnowsGeneratedColumnsFromTheirExtra(): void
    {
        foreach (['VIRTUAL GENERATED', 'STORED GENERATED', 'PERSISTENT GENERATED', 'VIRTUAL', 'PERSISTENT'] as $extra) {
            $this->assertTrue(WpdbDatabase::isGenerated($extra), $extra);
        }
        foreach (['', 'auto_increment', 'DEFAULT_GENERATED', 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP', 'INVISIBLE'] as $extra) {
            $this->assertFalse(WpdbDatabase::isGenerated($extra), $extra);
        }
    }
}

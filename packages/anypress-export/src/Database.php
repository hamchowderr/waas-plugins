<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

/** What the export reads from the site's database. WpdbDatabase is the real one; tests use their own. */
interface Database
{
    /** @return string[] the site's own tables: base tables whose names start with its table prefix. */
    public function tables(): array;

    /**
     * The character set rows are read in, which the dump names in its SET NAMES so the bytes go back
     * into the same columns unchanged.
     */
    public function charset(): string;

    /** The table's CREATE TABLE statement. */
    public function createTable(string $table): string;

    /**
     * @return array<string, string> column name => column type, in order, leaving out generated columns
     *     (the database computes those again on import).
     */
    public function columns(string $table): array;

    /** @return string[] the primary key's columns, in order; empty when it has none. */
    public function primaryKey(string $table): array;

    /**
     * Up to `$limit` rows of `$columns` (name => type), values in that order. With a key (the primary
     * key's columns) the rows come in key order after the key values `$after` (from the start when
     * null), so rows deleted or added between batches never shift the next batch. Without a key, the
     * rows from `$offset`.
     *
     * @param array<string, string> $columns
     * @param string[] $key
     * @param array<int, string>|null $after
     * @return array<int, array<int, string|null>>
     */
    public function rows(string $table, array $columns, array $key, ?array $after, int $offset, int $limit): array;

    /** The value escaped for use inside a single-quoted SQL string. */
    public function escape(string $value): string;

    /**
     * What the database says about the site's tables, for sizing batches and the disk check. Empty when
     * it can't tell.
     *
     * @return array<string, array{rows: int, avgRowLength: int, bytes: int}>
     */
    public function tableStats(): array;

    /**
     * What the export does not take: tables without the site's prefix, and views, triggers and stored
     * routines (the dump holds tables only).
     *
     * @return array{otherTables: string[], views: string[], triggers: string[], routines: string[]}
     */
    public function leftBehind(): array;
}

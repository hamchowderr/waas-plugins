<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

/** What the SQL dump reads from the site's database. WpdbDatabase is the real one; tests use their own. */
interface Database
{
    /** @return string[] the site's own tables: base tables whose names start with its table prefix. */
    public function tables(): array;

    /** The table's CREATE TABLE statement. */
    public function createTable(string $table): string;

    /** @return array<string, string> column name => column type, in order. */
    public function columns(string $table): array;

    /** @return string[] the primary key's columns, in order; empty when it has none. */
    public function primaryKey(string $table): array;

    /** @return array<int, array<int, string|null>> up to `$limit` rows from `$offset`, in `$orderBy` order. */
    public function rows(string $table, array $orderBy, int $offset, int $limit): array;

    /** The value escaped for use inside a single-quoted SQL string. */
    public function escape(string $value): string;
}

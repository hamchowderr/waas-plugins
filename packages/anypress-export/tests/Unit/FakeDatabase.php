<?php

namespace Tests\Unit;

use AnyPressExport\Database;

/** Tables in memory: name => [columns (name => type), rows]. */
final class FakeDatabase implements Database
{
    /** @var array<string, array{columns: array<string, string>, rows: array}> */
    public $tables = [];

    public function tables(): array
    {
        $names = array_keys($this->tables);
        sort($names);
        return $names;
    }

    public function createTable(string $table): string
    {
        $cols = [];
        foreach ($this->tables[$table]['columns'] as $name => $type) $cols[] = "  `$name` $type";
        return "CREATE TABLE `$table` (\n" . implode(",\n", $cols) . "\n)";
    }

    public function columns(string $table): array
    {
        return $this->tables[$table]['columns'];
    }

    public function primaryKey(string $table): array
    {
        return [array_key_first($this->tables[$table]['columns'])];
    }

    public function rows(string $table, array $orderBy, int $offset, int $limit): array
    {
        return array_slice($this->tables[$table]['rows'], $offset, $limit);
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }
}

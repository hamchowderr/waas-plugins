<?php

namespace Tests\Unit;

use AnyPressExport\Database;

/**
 * Tables in memory: name => [columns (name => type), rows (values in column order)], and optionally
 * key (primary key columns; the first column when not given, [] for none), generated (generated
 * column names) and avg (the average row length the database reports).
 */
final class FakeDatabase implements Database
{
    /** @var array<string, array> */
    public $tables = [];
    /** @var string */
    public $charset = 'utf8mb4';
    /** @var array<string, int[]> each table's row limits asked for, in order */
    public $limits = [];
    /** @var array<string, string[]> */
    public $behind = ['otherTables' => [], 'views' => [], 'triggers' => [], 'routines' => []];

    public function tables(): array
    {
        $names = array_keys($this->tables);
        sort($names);
        return $names;
    }

    public function charset(): string
    {
        return $this->charset;
    }

    public function createTable(string $table): string
    {
        $cols = [];
        foreach ($this->tables[$table]['columns'] as $name => $type) $cols[] = "  `$name` $type";
        return "CREATE TABLE `$table` (\n" . implode(",\n", $cols) . "\n)";
    }

    public function columns(string $table): array
    {
        return array_diff_key($this->tables[$table]['columns'], array_flip($this->tables[$table]['generated'] ?? []));
    }

    public function primaryKey(string $table): array
    {
        return $this->tables[$table]['key'] ?? [array_key_first($this->tables[$table]['columns'])];
    }

    public function rows(string $table, array $columns, array $key, ?array $after, int $offset, int $limit): array
    {
        $this->limits[$table][] = $limit;
        $names = array_keys($this->tables[$table]['columns']);
        $at = function (string $column) use ($names) {
            return array_search($column, $names, true);
        };
        $rows = $this->tables[$table]['rows'];
        if ($key) {
            $compare = function (array $a, array $b) {
                foreach ($a as $i => $value) {
                    $c = is_numeric($value) && is_numeric($b[$i]) ? $value <=> $b[$i] : strcmp((string) $value, (string) $b[$i]);
                    if ($c) return $c;
                }
                return 0;
            };
            $keyOf = function (array $row) use ($key, $at) {
                return array_map(function ($column) use ($row, $at) {
                    return $row[$at($column)];
                }, $key);
            };
            usort($rows, function ($a, $b) use ($compare, $keyOf) {
                return $compare($keyOf($a), $keyOf($b));
            });
            if ($after !== null) {
                $rows = array_values(array_filter($rows, function ($row) use ($compare, $keyOf, $after) {
                    return $compare($keyOf($row), $after) > 0;
                }));
            }
            $rows = array_slice($rows, 0, $limit);
        } else {
            $rows = array_slice($rows, $offset, $limit);
        }
        return array_map(function ($row) use ($columns, $at) {
            return array_map(function ($column) use ($row, $at) {
                return $row[$at($column)];
            }, array_keys($columns));
        }, $rows);
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function tableStats(): array
    {
        $stats = [];
        foreach ($this->tables as $name => $table) {
            if (isset($table['avg'])) $stats[$name] = ['rows' => count($table['rows']), 'avgRowLength' => $table['avg'], 'bytes' => $table['avg'] * count($table['rows'])];
        }
        return $stats;
    }

    public function leftBehind(): array
    {
        return $this->behind;
    }
}

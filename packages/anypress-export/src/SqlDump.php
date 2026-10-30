<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are not printed here; Plugin and Cli show them as JSON or CLI text.

/**
 * Writes the site's tables to database.sql a batch of rows at a time, so a large database is dumped
 * over several short requests. Binary columns are written as hex, everything else as escaped strings.
 */
final class SqlDump
{
    /** Rows read per query. */
    const BATCH = 500;
    /** Rows per INSERT statement. */
    const ROWS_PER_INSERT = 100;

    /** @var Database */
    private $db;
    /** @var string */
    private $file;

    public function __construct(Database $db, string $file)
    {
        $this->db = $db;
        $this->file = $file;
    }

    /** Starts the file. */
    public function begin(): void
    {
        $this->write("-- AnyPress Export\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n", false);
    }

    /**
     * Dumps the next batch of `$table` from row `$offset`; at offset 0 the table's structure first.
     * Returns the offset to continue from, or null once the table is done.
     */
    public function dumpBatch(string $table, int $offset): ?int
    {
        $quoted = WpdbDatabase::quote($table);
        if ($offset === 0) {
            $this->write("DROP TABLE IF EXISTS $quoted;\n" . $this->db->createTable($table) . ";\n\n");
        }
        $columns = $this->db->columns($table);
        $binary = [];
        foreach (array_values($columns) as $i => $type) {
            $binary[$i] = (bool) preg_match('/blob|binary/', $type);
        }
        $rows = $this->db->rows($table, $this->db->primaryKey($table), $offset, self::BATCH);
        foreach (array_chunk($rows, self::ROWS_PER_INSERT) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $values[] = '(' . implode(',', array_map(function ($value, $i) use ($binary) {
                    return $this->value($value, !empty($binary[$i]));
                }, array_values($row), array_keys(array_values($row)))) . ')';
            }
            $this->write("INSERT INTO $quoted VALUES\n" . implode(",\n", $values) . ";\n");
        }
        if (count($rows) < self::BATCH) {
            $this->write("\n");
            return null;
        }
        return $offset + count($rows);
    }

    /** Ends the file. */
    public function end(): void
    {
        $this->write("SET FOREIGN_KEY_CHECKS = 1;\n");
    }

    /** @param string|null $value */
    private function value($value, bool $binary): string
    {
        if ($value === null) return 'NULL';
        if ($binary) return $value === '' ? "''" : '0x' . bin2hex($value);
        return "'" . $this->db->escape((string) $value) . "'";
    }

    private function write(string $text, bool $append = true): void
    {
        if (file_put_contents($this->file, $text, $append ? FILE_APPEND : 0) === false) {
            throw new \RuntimeException(__('Could not write the database export. Is the disk full?', 'anypress-export'));
        }
    }
}

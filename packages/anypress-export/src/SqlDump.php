<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are not printed here; Plugin and Cli show them as JSON or CLI text.

/**
 * Writes the site's tables to database.sql a batch of rows at a time, so a large database is dumped
 * over several short requests. Batches are sized by bytes, not only rows, so tables with big rows
 * (page builder data, form entries, logs) don't run past PHP's memory limit, and each INSERT stays
 * under about 1 MB so the import never hits the database's packet limit. Rows are read in primary-key
 * order after the last key dumped (keyset pagination), or by offset for tables without a primary key.
 * Binary, BIT and spatial columns are written as hex, everything else as escaped strings, with an
 * explicit column list that leaves out generated columns.
 */
final class SqlDump
{
    /** Most rows read per query. */
    const BATCH = 500;
    /** About how many bytes of rows one query reads. */
    const BATCH_BYTES = 4194304;
    /** Most rows per INSERT statement. */
    const ROWS_PER_INSERT = 100;
    /** About the most bytes in one INSERT statement (a single bigger row still gets one of its own). */
    const INSERT_BYTES = 1048576;

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
        $charset = preg_replace('/[^A-Za-z0-9_]/', '', $this->db->charset()) ?: 'utf8mb4';
        $this->write("-- AnyPress Export\nSET NAMES $charset;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n", false);
    }

    /**
     * Dumps the next batch of `$table`, starting with its structure when `$cursor` is empty. Returns the
     * cursor to carry on from; its `done` is true once the table is complete, and `rows` counts the rows
     * written so far.
     *
     * @param array{rows?: int, after?: array|null, offset?: int, limit?: int, done?: bool} $cursor
     * @param array{avgRowLength?: int}|null $stats what the database says about the table
     * @return array{rows: int, after: array|null, offset: int, limit: int, done: bool}
     */
    public function dumpBatch(string $table, array $cursor, ?array $stats = null): array
    {
        $quoted = WpdbDatabase::quote($table);
        if (!$cursor) {
            $this->write("DROP TABLE IF EXISTS $quoted;\n" . $this->db->createTable($table) . ";\n\n");
            $average = (int) ($stats['avgRowLength'] ?? 0);
            // Unknown row sizes start with one row and grow from what it shows.
            $cursor = ['rows' => 0, 'after' => null, 'offset' => 0, 'limit' => $average > 0 ? self::limitFor(2 * $average) : 1, 'done' => false];
        }
        $columns = $this->db->columns($table);
        $names = array_keys($columns);
        $types = array_values($columns);
        $key = $this->db->primaryKey($table);
        $keyAt = [];
        foreach ($key as $column) {
            $at = array_search($column, $names, true);
            if ($at === false) {
                $keyAt = [];
                $key = [];
                break;
            }
            $keyAt[] = $at;
        }
        $limit = max(1, (int) $cursor['limit']);
        $rows = $this->db->rows($table, $columns, $key, $key ? $cursor['after'] : null, (int) $cursor['offset'], $limit);

        $head = "INSERT INTO $quoted (" . implode(', ', array_map([WpdbDatabase::class, 'quote'], $names)) . ") VALUES\n";
        $values = [];
        $bytes = 0;
        $largest = 0;
        foreach ($rows as $row) {
            $row = array_values($row);
            $size = 0;
            $parts = [];
            foreach ($row as $i => $value) {
                $size += $value === null ? 0 : strlen((string) $value);
                $parts[] = $this->value($value, $types[$i] ?? '');
            }
            $largest = max($largest, $size);
            $sql = '(' . implode(',', $parts) . ')';
            if ($values && (count($values) >= self::ROWS_PER_INSERT || $bytes + strlen($sql) > self::INSERT_BYTES)) {
                $this->write($head . implode(",\n", $values) . ";\n");
                $values = [];
                $bytes = 0;
            }
            $values[] = $sql;
            $bytes += strlen($sql) + 2;
        }
        if ($values) $this->write($head . implode(",\n", $values) . ";\n");

        $count = count($rows);
        $cursor['rows'] += $count;
        $cursor['offset'] += $count;
        if ($count && $key) {
            $last = array_values($rows[$count - 1]);
            $cursor['after'] = array_map(function ($at) use ($last) {
                return (string) $last[$at];
            }, $keyAt);
        }
        $cursor['limit'] = $count ? self::limitFor($largest) : $limit;
        if ($count < $limit) {
            $this->write("\n");
            $cursor['done'] = true;
        }
        return $cursor;
    }

    /** Rows per query for rows of about `$rowBytes`. */
    public static function limitFor(int $rowBytes): int
    {
        return max(1, min(self::BATCH, intdiv(self::BATCH_BYTES, max(1, $rowBytes))));
    }

    /** Ends the file. */
    public function end(): void
    {
        $this->write("SET FOREIGN_KEY_CHECKS = 1;\n");
    }

    public static function isBinary(string $type): bool
    {
        return (bool) preg_match('/blob|binary|^bit\b/i', $type);
    }

    public static function isSpatial(string $type): bool
    {
        return (bool) preg_match('/^(geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection|geomcollection)\b/i', $type);
    }

    /**
     * A value as an SQL literal. Binary and BIT values go as hex. Spatial values are read in the
     * database's own format (SRID + WKB) and go back as the same bytes in hex, as mysqldump --hex-blob
     * writes them, which MySQL and MariaDB both take for a geometry column.
     *
     * @param string|null $value
     */
    private function value($value, string $type): string
    {
        if ($value === null) return 'NULL';
        if (self::isBinary($type) || self::isSpatial($type)) return $value === '' ? "''" : '0x' . bin2hex((string) $value);
        return "'" . $this->db->escape((string) $value) . "'";
    }

    private function write(string $text, bool $append = true): void
    {
        $written = @file_put_contents($this->file, $text, $append ? FILE_APPEND : 0);
        if ($written !== strlen($text)) {
            throw new \RuntimeException(__('Could not write the database export. Is the disk full? Free up space on this server, then cancel and start again.', 'anypress-export'));
        }
    }
}

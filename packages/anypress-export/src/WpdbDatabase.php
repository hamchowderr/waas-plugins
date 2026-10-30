<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are not printed here; Plugin and Cli show them as JSON or CLI text.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- an export reads every table as it is. Table names come from SHOW TABLES and are backtick-quoted by quote(); %i needs WordPress 6.2, and this plugin supports older sites being moved.

/** The site's database, through WordPress's $wpdb. */
final class WpdbDatabase implements Database
{
    /** @var \wpdb */
    private $wpdb;
    /** @var array<string, array<string, array{type: string, generated: bool}>> */
    private $columns = [];

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function tables(): array
    {
        $like = $this->wpdb->esc_like($this->wpdb->base_prefix) . '%';
        $rows = $this->wpdb->get_results($this->wpdb->prepare('SHOW FULL TABLES LIKE %s', $like), ARRAY_N);
        $tables = [];
        foreach ((array) $rows as $row) {
            if (($row[1] ?? '') === 'BASE TABLE') $tables[] = $row[0];
        }
        sort($tables);
        return $tables;
    }

    /**
     * The dump reads in utf8mb4 when the server has it, whatever the site's own connection uses: every
     * MySQL character set converts to utf8mb4 and back without loss, so latin1 columns (even ones holding
     * UTF-8 bytes) come back byte for byte, and emoji in utf8mb4 columns survive a latin1 site connection.
     * Otherwise the connection's own character set.
     */
    public function charset(): string
    {
        if ($this->canSwitch()) return 'utf8mb4';
        $dbh = $this->dbh();
        if ($dbh instanceof \mysqli) {
            $name = (string) $dbh->character_set_name();
            if ($name !== '') return $name;
        }
        return (string) ($this->wpdb->charset ?: 'utf8mb4');
    }

    public function createTable(string $table): string
    {
        $row = $this->read(function () use ($table) {
            return $this->wpdb->get_row('SHOW CREATE TABLE ' . self::quote($table), ARRAY_N);
        });
        if (!is_array($row) || !isset($row[1])) throw new \RuntimeException(sprintf(
            /* translators: %s: database table name */
            __('Could not read the structure of %s.', 'anypress-export'),
            $table
        ));
        return $row[1];
    }

    public function columns(string $table): array
    {
        $columns = [];
        foreach ($this->describe($table) as $name => $column) {
            if (!$column['generated']) $columns[$name] = $column['type'];
        }
        return $columns;
    }

    /** @return array<string, array{type: string, generated: bool}> */
    private function describe(string $table): array
    {
        if (isset($this->columns[$table])) return $this->columns[$table];
        $columns = [];
        foreach ((array) $this->wpdb->get_results('SHOW COLUMNS FROM ' . self::quote($table), ARRAY_A) as $column) {
            $columns[$column['Field']] = [
                'type' => strtolower($column['Type']),
                'generated' => self::isGenerated((string) ($column['Extra'] ?? '')),
            ];
        }
        return $this->columns[$table] = $columns;
    }

    /**
     * Whether a column's Extra marks it as generated: "VIRTUAL GENERATED" or "STORED GENERATED" (MySQL,
     * MariaDB), "PERSISTENT GENERATED" or "VIRTUAL"/"PERSISTENT" (older MariaDB). MySQL 8's
     * "DEFAULT_GENERATED" is a column with an expression default, which is stored and dumped.
     */
    public static function isGenerated(string $extra): bool
    {
        return (bool) preg_match('/\b(VIRTUAL|STORED|PERSISTENT)\b/i', $extra);
    }

    public function primaryKey(string $table): array
    {
        $keys = [];
        foreach ((array) $this->wpdb->get_results('SHOW KEYS FROM ' . self::quote($table) . " WHERE Key_name = 'PRIMARY'", ARRAY_A) as $key) {
            $keys[(int) $key['Seq_in_index']] = $key['Column_name'];
        }
        ksort($keys);
        return array_values($keys);
    }

    public function rows(string $table, array $columns, array $key, ?array $after, int $offset, int $limit): array
    {
        $sql = self::select($table, $columns, $key, $after, $offset, $limit, [$this, 'literal']);
        $rows = $this->read(function () use ($sql) {
            return $this->wpdb->get_results($sql, ARRAY_N);
        });
        $error = $this->wpdb->last_error;
        $this->wpdb->flush(); // wpdb keeps a second copy of the rows otherwise
        if ($error) throw new \RuntimeException(sprintf(
            /* translators: 1: database table name, 2: the database error */
            __('Could not read %1$s: %2$s', 'anypress-export'),
            $table,
            $error
        ));
        return (array) $rows;
    }

    /**
     * The SELECT for one batch: explicit columns, then keyset pagination on the key (`k1 > v1 OR (k1 = v1
     * AND k2 > v2) ...`, which works for composite keys) or OFFSET when there is no key.
     *
     * @param array<string, string> $columns
     * @param string[] $key
     * @param array<int, string>|null $after
     * @param callable(string, string): string $literal value, column type => SQL literal
     */
    public static function select(string $table, array $columns, array $key, ?array $after, int $offset, int $limit, callable $literal): string
    {
        $sql = 'SELECT ' . implode(', ', array_map([self::class, 'quote'], array_keys($columns))) . ' FROM ' . self::quote($table);
        if (!$key) return $sql . sprintf(' LIMIT %d OFFSET %d', $limit, $offset);
        if ($after !== null) {
            $or = [];
            foreach ($key as $i => $column) {
                $and = [];
                for ($j = 0; $j < $i; $j++) {
                    $and[] = self::quote($key[$j]) . ' = ' . $literal((string) $after[$j], $columns[$key[$j]] ?? '');
                }
                $and[] = self::quote($column) . ' > ' . $literal((string) $after[$i], $columns[$column] ?? '');
                $or[] = count($and) > 1 ? '(' . implode(' AND ', $and) . ')' : $and[0];
            }
            $sql .= ' WHERE ' . (count($or) > 1 ? '(' . implode(' OR ', $or) . ')' : $or[0]);
        }
        return $sql . ' ORDER BY ' . implode(', ', array_map([self::class, 'quote'], $key)) . sprintf(' LIMIT %d', $limit);
    }

    /** A key value as an SQL literal: hex for binary columns, a quoted string otherwise. */
    public function literal(string $value, string $type): string
    {
        if (SqlDump::isBinary($type) || SqlDump::isSpatial($type)) return $value === '' ? "''" : '0x' . bin2hex($value);
        return "'" . $this->escape($value) . "'";
    }

    public function escape(string $value): string
    {
        // _real_escape also swaps every % for a placeholder meant for wpdb::prepare(); a dump must keep the %.
        return $this->wpdb->remove_placeholder_escape($this->wpdb->_real_escape($value));
    }

    public function tableStats(): array
    {
        $rows = $this->quietly(function () {
            return $this->wpdb->get_results($this->wpdb->prepare(
                'SELECT TABLE_NAME, TABLE_ROWS, AVG_ROW_LENGTH, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
                $this->wpdb->esc_like($this->wpdb->base_prefix) . '%'
            ), ARRAY_N);
        });
        $stats = [];
        foreach ((array) $rows as $row) {
            if (!isset($row[0])) continue;
            $stats[(string) $row[0]] = ['rows' => (int) ($row[1] ?? 0), 'avgRowLength' => (int) ($row[2] ?? 0), 'bytes' => (int) ($row[3] ?? 0) + (int) ($row[4] ?? 0)];
        }
        return $stats;
    }

    public function leftBehind(): array
    {
        $prefix = (string) $this->wpdb->base_prefix;
        $result = ['otherTables' => [], 'views' => [], 'triggers' => [], 'routines' => []];
        foreach ((array) $this->quietly(function () {
            return $this->wpdb->get_results('SHOW FULL TABLES', ARRAY_N);
        }) as $row) {
            $name = (string) ($row[0] ?? '');
            if ($name === '' || self::isInternal($name)) continue;
            if (($row[1] ?? '') === 'VIEW') {
                $result['views'][] = $name;
            } elseif (strpos($name, $prefix) !== 0) {
                $result['otherTables'][] = $name;
            }
        }
        foreach ((array) $this->quietly(function () use ($prefix) {
            return $this->wpdb->get_results($this->wpdb->prepare(
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE LIKE %s',
                $this->wpdb->esc_like($prefix) . '%'
            ), ARRAY_N);
        }) as $row) {
            if (isset($row[0])) $result['triggers'][] = (string) $row[0];
        }
        foreach ((array) $this->quietly(function () {
            return $this->wpdb->get_results('SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()', ARRAY_N);
        }) as $row) {
            if (isset($row[0])) $result['routines'][] = (string) $row[0];
        }
        foreach ($result as &$names) {
            $names = array_values(array_unique($names));
            sort($names);
        }
        return $result;
    }

    /** Bookkeeping tables of the SQLite database WordPress Playground and some local tools use instead of MySQL. */
    private static function isInternal(string $name): bool
    {
        return strpos($name, 'sqlite_') === 0 || strpos($name, '_mysql_') === 0 || strpos($name, '_wp_sqlite_') === 0;
    }

    public static function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** Runs a query whose failure only means "can't tell" (old servers, information_schema closed off). */
    private function quietly(callable $query)
    {
        $suppressed = $this->wpdb->suppress_errors(true);
        try {
            $result = $query();
            return $this->wpdb->last_error ? [] : $result;
        } finally {
            $this->wpdb->suppress_errors($suppressed);
        }
    }

    /** @return \mysqli|mixed the connection, when wpdb has a MySQL one */
    private function dbh()
    {
        return $this->wpdb->dbh;
    }

    private function canSwitch(): bool
    {
        return $this->dbh() instanceof \mysqli && $this->wpdb->has_cap('utf8mb4');
    }

    /** Runs a read on a utf8mb4 connection (see charset()), then puts the site's connection back as it was. */
    private function read(callable $query)
    {
        $dbh = $this->dbh();
        if (!$this->canSwitch()) return $query();
        $before = (string) $dbh->character_set_name();
        if ($before === 'utf8mb4') return $query();
        if (!@mysqli_set_charset($dbh, 'utf8mb4')) throw new \RuntimeException(__('Could not read the database as utf8mb4.', 'anypress-export'));
        try {
            return $query();
        } finally {
            if ($this->wpdb->charset === $before) {
                $this->wpdb->set_charset($dbh, $this->wpdb->charset, $this->wpdb->collate);
            } else {
                @mysqli_set_charset($dbh, $before);
            }
        }
    }
}

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

    public function createTable(string $table): string
    {
        $row = $this->wpdb->get_row('SHOW CREATE TABLE ' . self::quote($table), ARRAY_N);
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
        foreach ((array) $this->wpdb->get_results('SHOW COLUMNS FROM ' . self::quote($table), ARRAY_A) as $column) {
            $columns[$column['Field']] = strtolower($column['Type']);
        }
        return $columns;
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

    public function rows(string $table, array $orderBy, int $offset, int $limit): array
    {
        $order = $orderBy ? ' ORDER BY ' . implode(', ', array_map([self::class, 'quote'], $orderBy)) : '';
        $rows = $this->wpdb->get_results(sprintf('SELECT * FROM %s%s LIMIT %d OFFSET %d', self::quote($table), $order, $limit, $offset), ARRAY_N);
        if ($this->wpdb->last_error) throw new \RuntimeException(sprintf(
            /* translators: 1: database table name, 2: the database error */
            __('Could not read %1$s: %2$s', 'anypress-export'),
            $table,
            $this->wpdb->last_error
        ));
        return (array) $rows;
    }

    public function escape(string $value): string
    {
        // _real_escape also swaps every % for a placeholder meant for wpdb::prepare(); a dump must keep the %.
        return $this->wpdb->remove_placeholder_escape($this->wpdb->_real_escape($value));
    }

    public static function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}

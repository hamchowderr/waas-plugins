<?php

namespace AnyPressExport;

defined('ABSPATH') || exit;

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
        if (!is_array($row) || !isset($row[1])) throw new \RuntimeException("Could not read the structure of $table.");
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
        if ($this->wpdb->last_error) throw new \RuntimeException("Could not read $table: " . $this->wpdb->last_error);
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

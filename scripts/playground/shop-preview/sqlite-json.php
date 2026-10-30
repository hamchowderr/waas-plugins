<?php
/**
 * Plugin Name: Preview: MySQL JSON functions on SQLite
 * Description: Playground runs on SQLite, which lacks two MySQL functions FluentCart's checkout and product pages use. Preview only; the live shop runs MariaDB.
 */

if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'get_driver' ) ) {
	$anypress_pdo = $GLOBALS['wpdb']->get_driver()->get_connection()->get_pdo();
	$anypress_pdo->sqliteCreateFunction(
		'JSON_UNQUOTE',
		static function ( $value ) {
			$decoded = is_string( $value ) ? json_decode( $value, true ) : null;
			return is_string( $decoded ) ? $decoded : $value;
		},
		1
	);
	$anypress_pdo->sqliteCreateFunction(
		'JSON_MERGE_PATCH',
		static function ( $target, $patch ) {
			$merge = static function ( $a, $b ) use ( &$merge ) {
				if ( ! is_array( $b ) || array_is_list( $b ) ) {
					return $b;
				}
				$a = is_array( $a ) && ! array_is_list( $a ) ? $a : array();
				foreach ( $b as $k => $v ) {
					if ( null === $v ) {
						unset( $a[ $k ] );
					} else {
						$a[ $k ] = $merge( $a[ $k ] ?? null, $v );
					}
				}
				return $a;
			};
			return wp_json_encode( $merge( json_decode( (string) $target, true ), json_decode( (string) $patch, true ) ) );
		},
		2
	);
}

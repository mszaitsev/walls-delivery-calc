<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = (string) $wpdb->prefix . 'wdc_pek_location_mappings';

	$clear_error = static function () use ( $wpdb ): void {
		if ( property_exists( $wpdb, 'last_error' ) ) {
			$wpdb->last_error = '';
		}
	};
	$throw_on_error = static function ( string $message ) use ( $wpdb ): void {
		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			throw new RuntimeException( $message );
		}
	};
	$quoted_table = static function ( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	};
	$table_exists = static function () use ( $wpdb, $table, $clear_error, $throw_on_error ): bool {
		$clear_error();
		$like = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $table ) : addcslashes( $table, '_%\\' );
		$sql = method_exists( $wpdb, 'prepare' )
			? $wpdb->prepare( 'SHOW TABLES LIKE %s', $like )
			: "SHOW TABLES LIKE '" . addslashes( $like ) . "'";
		$result = $wpdb->get_var( $sql );
		$throw_on_error( 'PEK mapping precision migration table lookup failed.' );

		return is_scalar( $result ) && (string) $result === $table;
	};
	$column_exists = static function ( string $column ) use ( $wpdb, $table, $clear_error, $throw_on_error ): bool {
		$clear_error();
		$sql = method_exists( $wpdb, 'prepare' )
			? $wpdb->prepare( 'SHOW COLUMNS FROM ' . '`' . str_replace( '`', '``', $table ) . '`' . ' LIKE %s', $column )
			: 'SHOW COLUMNS FROM ' . '`' . str_replace( '`', '``', $table ) . "` LIKE '" . addslashes( $column ) . "'";
		$result = $wpdb->get_var( $sql );
		$throw_on_error( 'PEK mapping precision migration column lookup failed.' );

		return is_scalar( $result ) && (string) $result === $column;
	};

	if ( ! $table_exists() ) {
		return;
	}

	$has_mapping_precision = $column_exists( 'mapping_precision' );
	$has_legacy_precision = $column_exists( 'precision' );

	if ( ! $has_mapping_precision ) {
		$clear_error();
		$result = $wpdb->query( 'ALTER TABLE ' . $quoted_table( $table ) . ' ADD COLUMN mapping_precision varchar(16) NULL AFTER longitude' );
		if ( false === $result ) {
			throw new RuntimeException( 'PEK mapping precision column migration failed.' );
		}
		$throw_on_error( 'PEK mapping precision column migration failed.' );
	}

	if ( ! $column_exists( 'mapping_precision' ) ) {
		throw new RuntimeException( 'PEK mapping precision postcondition failed.' );
	}

	if ( $has_legacy_precision ) {
		$clear_error();
		$result = $wpdb->query( 'UPDATE ' . $quoted_table( $table ) . ' SET mapping_precision = `precision` WHERE mapping_precision IS NULL' );
		if ( false === $result ) {
			throw new RuntimeException( 'PEK mapping precision backfill failed.' );
		}
		$throw_on_error( 'PEK mapping precision backfill failed.' );
	}
};

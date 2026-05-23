<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = $wpdb->prefix . 'wdc_locations';

	$table_exists = static function ( string $table ) use ( $wpdb ): bool {
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return ! in_array( $result, array( null, '', 0, '0' ), true );
	};

	$column_exists = static function ( string $table, string $column ) use ( $wpdb ): bool {
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ), ARRAY_A );
		return is_array( $row ) && array() !== $row;
	};

	$index_exists = static function ( string $table, string $index ) use ( $wpdb ): bool {
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index ), ARRAY_A );
		return is_array( $row ) && array() !== $row;
	};

	$is_duplicate = static function () use ( $wpdb ): bool {
		$error = strtolower( (string) ( $wpdb->last_error ?? '' ) );
		return str_contains( $error, 'duplicate column' ) || str_contains( $error, 'duplicate key' ) || str_contains( $error, 'duplicate key name' );
	};

	if ( ! $table_exists( $table ) ) {
		return;
	}

	if ( ! $column_exists( $table, 'region_type' ) ) {
		$result = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN region_type varchar(30) NOT NULL DEFAULT '' AFTER region_code" );
		if ( false === $result && ! $is_duplicate() && ! $column_exists( $table, 'region_type' ) ) {
			$error = trim( (string) ( $wpdb->last_error ?? '' ) );
			throw new RuntimeException( trim( "Unable to add region_type to {$table}. {$error}" ) );
		}
	}

	if ( $index_exists( $table, 'ix_region_type' ) ) {
		return;
	}

	$result = $wpdb->query( "ALTER TABLE {$table} ADD KEY ix_region_type (region_type)" );
	if ( false === $result && ! $is_duplicate() && ! $index_exists( $table, 'ix_region_type' ) ) {
		$error = trim( (string) ( $wpdb->last_error ?? '' ) );
		throw new RuntimeException( trim( "Unable to add ix_region_type index to {$table}. {$error}" ) );
	}
};

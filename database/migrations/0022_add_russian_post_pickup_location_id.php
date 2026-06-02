<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = $wpdb->prefix . 'wdc_pickup_points_russian_post';
	$schema_value = static function ( mixed $value, string $key ): string {
		if ( is_object( $value ) && isset( $value->{$key} ) ) {
			return (string) $value->{$key};
		}
		if ( is_array( $value ) && isset( $value[ $key ] ) ) {
			return (string) $value[ $key ];
		}

		return (string) $value;
	};

	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( (string) $table_exists !== $table ) {
		return;
	}

	$column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'location_id' ) );
	if ( 'location_id' !== $schema_value( $column, 'Field' ) ) {
		$result = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN location_id BIGINT UNSIGNED NULL AFTER gar_region_id" );
		if ( false === $result ) {
			$error = trim( (string) ( $wpdb->last_error ?? '' ) );
			throw new RuntimeException( trim( "Unable to add location_id to {$table}. {$error}" ) );
		}
	}

	$index = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'idx_location_id' ) );
	if ( 'idx_location_id' !== $schema_value( $index, 'Key_name' ) ) {
		$result = $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_location_id (location_id)" );
		if ( false === $result ) {
			$error = trim( (string) ( $wpdb->last_error ?? '' ) );
			throw new RuntimeException( trim( "Unable to add idx_location_id to {$table}. {$error}" ) );
		}
	}
};

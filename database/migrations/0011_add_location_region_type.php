<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = $wpdb->prefix . 'wdc_locations';

	$columns = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'region_type' ), ARRAY_A );
	if ( ! is_array( $columns ) || array() === $columns ) {
		$result = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN region_type varchar(30) NOT NULL DEFAULT '' AFTER region_code" );
		if ( false === $result ) {
			$error = trim( (string) ( $wpdb->last_error ?? '' ) );
			throw new RuntimeException( trim( "Unable to add region_type to {$table}. {$error}" ) );
		}
	}

	$indexes = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'ix_region_type' ), ARRAY_A );
	if ( is_array( $indexes ) && array() !== $indexes ) {
		return;
	}

	$result = $wpdb->query( "ALTER TABLE {$table} ADD KEY ix_region_type (region_type)" );
	if ( false === $result ) {
		$error = trim( (string) ( $wpdb->last_error ?? '' ) );
		throw new RuntimeException( trim( "Unable to add ix_region_type index to {$table}. {$error}" ) );
	}
};

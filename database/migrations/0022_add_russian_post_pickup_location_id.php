<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = $wpdb->prefix . 'wdc_pickup_points_russian_post';
	$previous_suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : false;
	$wpdb->query( "ALTER TABLE {$table} ADD COLUMN location_id BIGINT UNSIGNED NULL AFTER gar_region_id" );
	$wpdb->query( "ALTER TABLE {$table} ADD KEY idx_location_id (location_id)" );
	if ( method_exists( $wpdb, 'suppress_errors' ) ) {
		$wpdb->suppress_errors( (bool) $previous_suppress );
	}
};

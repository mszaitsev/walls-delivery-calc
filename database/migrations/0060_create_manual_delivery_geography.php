<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	$regions = $wpdb->prefix . 'wdc_manual_delivery_regions';
	$locations = $wpdb->prefix . 'wdc_manual_delivery_locations';

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta(
		"CREATE TABLE {$regions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL,
			region_name varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ux_manual_region (service_id, region_name),
			KEY service_id (service_id),
			KEY region_name (region_name)
		) {$charset_collate};"
	);

	dbDelta(
		"CREATE TABLE {$locations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL,
			location_name varchar(255) NOT NULL DEFAULT '',
			region_name varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ux_manual_location (service_id, location_name, region_name),
			KEY service_id (service_id),
			KEY region_name (region_name),
			KEY location_name (location_name)
		) {$charset_collate};"
	);

	$region_index = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$regions} WHERE Key_name = %s", 'ux_manual_region' ) );
	$location_index = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$locations} WHERE Key_name = %s", 'ux_manual_location' ) );
	if ( null === $region_index || null === $location_index ) {
		throw new RuntimeException( 'Manual delivery geography migration postcondition failed.' );
	}
};

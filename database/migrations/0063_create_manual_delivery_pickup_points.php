<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	$table = $wpdb->prefix . 'wdc_manual_delivery_pickup_points';

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL,
			code varchar(120) NOT NULL,
			title varchar(255) NOT NULL DEFAULT '',
			country_code varchar(2) NOT NULL,
			region_name varchar(255) NOT NULL,
			location_name varchar(255) NOT NULL,
			address text NOT NULL,
			postcode varchar(32) NOT NULL DEFAULT '',
			latitude decimal(10,7) NULL,
			longitude decimal(10,7) NULL,
			work_time text NOT NULL,
			comment text NOT NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			sort_order int NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ux_manual_pickup_service_code (service_id, code),
			KEY service_id (service_id),
			KEY active_lookup (service_id, active),
			KEY locality_lookup (service_id, active, country_code, region_name(120), location_name(120))
		) {$charset_collate};"
	);

	$index = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'ux_manual_pickup_service_code' ) );
	if ( null === $index ) {
		throw new RuntimeException( 'Manual delivery pickup points migration postcondition failed.' );
	}
};

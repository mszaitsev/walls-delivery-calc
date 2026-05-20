<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'wdc_pickup_points';
	$charset_collate = $wpdb->get_charset_collate();

	$columns = "(
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		carrier_key varchar(64) NOT NULL DEFAULT '',
		point_code varchar(128) NOT NULL DEFAULT '',
		point_type varchar(64) NOT NULL DEFAULT '',
		country_code varchar(8) NOT NULL DEFAULT '',
		region_name varchar(255) NOT NULL DEFAULT '',
		city_name varchar(255) NOT NULL DEFAULT '',
		address text NOT NULL,
		postcode varchar(32) NOT NULL DEFAULT '',
		latitude decimal(10,7) NULL,
		longitude decimal(10,7) NULL,
		work_time text NOT NULL,
		comment text NOT NULL,
		extra_cost_kopecks bigint(20) NOT NULL DEFAULT 0,
		active tinyint(1) NOT NULL DEFAULT 1,
		raw_reference longtext NULL,
		updated_at datetime NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY carrier_point (carrier_key, point_code),
		KEY city_name (city_name),
		KEY region_name (region_name),
		KEY active (active)
	) {$charset_collate}";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table_name} {$columns};" );
	dbDelta( "CREATE TABLE {$table_name} {$columns};" );
};

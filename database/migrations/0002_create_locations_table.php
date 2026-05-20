<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'wdc_locations';
	$charset_collate = $wpdb->get_charset_collate();

	$columns = "(
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		fias_id varchar(64) NOT NULL DEFAULT '',
		gar_id varchar(64) NOT NULL DEFAULT '',
		country_code varchar(8) NOT NULL DEFAULT '',
		region_name varchar(255) NOT NULL DEFAULT '',
		region_code varchar(64) NOT NULL DEFAULT '',
		city_name varchar(255) NOT NULL DEFAULT '',
		settlement_name varchar(255) NOT NULL DEFAULT '',
		settlement_type varchar(64) NOT NULL DEFAULT '',
		display_name varchar(512) NOT NULL DEFAULT '',
		postcode varchar(32) NOT NULL DEFAULT '',
		latitude decimal(10,7) NULL,
		longitude decimal(10,7) NULL,
		searchable_text longtext NOT NULL,
		active tinyint(1) NOT NULL DEFAULT 1,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY country_code (country_code),
		KEY region_name (region_name),
		KEY city_name (city_name),
		KEY settlement_name (settlement_name),
		KEY postcode (postcode),
		KEY active (active)
	) {$charset_collate}";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table_name} {$columns};" );
	dbDelta( "CREATE TABLE {$table_name} {$columns};" );

	$previous_suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : false;
	$wpdb->query( "ALTER TABLE {$table_name} ADD FULLTEXT KEY searchable_text (searchable_text)" );
	if ( method_exists( $wpdb, 'suppress_errors' ) ) {
		$wpdb->suppress_errors( (bool) $previous_suppress );
	}
};

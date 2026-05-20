<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'wdc_calendar_days';
	$charset_collate = $wpdb->get_charset_collate();

	$columns = "(
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		calendar_type varchar(50) NOT NULL,
		calendar_date date NOT NULL,
		is_working tinyint(1) NOT NULL DEFAULT 0,
		reason varchar(255) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY calendar_type_date (calendar_type, calendar_date),
		KEY calendar_date (calendar_date)
	) {$charset_collate}";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table_name} {$columns};" );
	dbDelta( "CREATE TABLE {$table_name} {$columns};" );
};

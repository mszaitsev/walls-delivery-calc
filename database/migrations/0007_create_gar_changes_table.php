<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'wdc_gar_changes';
	$charset_collate = $wpdb->get_charset_collate();

	$columns = "(
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		task_id varchar(128) NOT NULL DEFAULT '',
		status varchar(64) NOT NULL DEFAULT '',
		requested_at datetime NOT NULL,
		completed_at datetime NULL,
		payload longtext NULL,
		applied tinyint(1) NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY task_id (task_id),
		KEY status (status),
		KEY applied (applied)
	) {$charset_collate}";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table_name} {$columns};" );
	dbDelta( "CREATE TABLE {$table_name} {$columns};" );
};

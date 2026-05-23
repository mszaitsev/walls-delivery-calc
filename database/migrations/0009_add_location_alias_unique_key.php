<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'wdc_location_aliases';
	$charset_collate = $wpdb->get_charset_collate();

	$columns = "(
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		location_id bigint(20) unsigned NOT NULL,
		alias varchar(255) NOT NULL DEFAULT '',
		alias_normalized varchar(255) NOT NULL DEFAULT '',
		source varchar(64) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY ux_location_alias_source (location_id, alias_normalized, source),
		KEY alias_normalized (alias_normalized),
		KEY location_id (location_id)
	) {$charset_collate}";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE {$table_name} {$columns};" );
};

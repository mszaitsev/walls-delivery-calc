<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'wdc_rule_conditions';
	$charset_collate = $wpdb->get_charset_collate();

	$columns = "(
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		rule_id bigint(20) unsigned NOT NULL,
		condition_group int NOT NULL DEFAULT 1,
		condition_type varchar(64) NOT NULL DEFAULT '',
		operator varchar(64) NOT NULL DEFAULT '',
		value_text text NOT NULL,
		value_number decimal(18,4) NULL,
		value_json longtext NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY rule_id (rule_id),
		KEY condition_type (condition_type),
		KEY condition_group (condition_group)
	) {$charset_collate}";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table_name} {$columns};" );
	dbDelta( "CREATE TABLE {$table_name} {$columns};" );
};

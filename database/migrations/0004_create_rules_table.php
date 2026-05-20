<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'wdc_rules';
	$charset_collate = $wpdb->get_charset_collate();

	$columns = "(
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(255) NOT NULL DEFAULT '',
		enabled tinyint(1) NOT NULL DEFAULT 1,
		priority int NOT NULL DEFAULT 100,
		target_type varchar(64) NOT NULL DEFAULT '',
		target_value varchar(255) NOT NULL DEFAULT '',
		action_type varchar(64) NOT NULL DEFAULT '',
		operation_type varchar(64) NOT NULL DEFAULT '',
		operation_value decimal(18,4) NOT NULL DEFAULT 0.0000,
		operation_base varchar(64) NOT NULL DEFAULT '',
		promo_shipping tinyint(1) NOT NULL DEFAULT 0,
		stop_processing tinyint(1) NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY enabled (enabled),
		KEY priority (priority),
		KEY target_type (target_type),
		KEY promo_shipping (promo_shipping)
	) {$charset_collate}";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table_name} {$columns};" );
	dbDelta( "CREATE TABLE {$table_name} {$columns};" );
};

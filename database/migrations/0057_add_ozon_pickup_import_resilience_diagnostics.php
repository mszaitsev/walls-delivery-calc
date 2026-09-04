<?php
declare(strict_types=1);
defined( 'ABSPATH' ) || exit;
return static function (): void {
	global $wpdb;
	$table = $wpdb->prefix . 'wdc_ozon_delivery_pickup_generations';
	$quoted = '`' . str_replace( '`', '``', $table ) . '`';
	$columns = array(
		'retry_count' => 'int unsigned NOT NULL DEFAULT 0 AFTER conflict_count',
		'safe_error_operation' => 'varchar(120) NULL AFTER safe_error_message',
		'safe_error_http_status' => 'smallint unsigned NULL AFTER safe_error_operation',
		'safe_error_retryable' => 'tinyint(1) NULL AFTER safe_error_http_status',
		'failed_page' => 'int unsigned NULL AFTER safe_error_retryable',
		'failed_cursor' => 'varchar(255) NULL AFTER failed_page',
		'failed_after_ids' => 'int unsigned NULL AFTER failed_cursor',
		'failed_attempt' => 'int unsigned NULL AFTER failed_after_ids',
		'failed_at' => 'datetime NULL AFTER failed_attempt',
	);
	foreach ( $columns as $column => $definition ) {
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$quoted} LIKE %s", $column ) );
		if ( $column === $exists ) {
			continue;
		}
		if ( false === $wpdb->query( "ALTER TABLE {$quoted} ADD COLUMN {$column} {$definition}" ) ) {
			throw new RuntimeException( 'Ozon pickup import diagnostics migration failed.' );
		}
	}
	foreach ( array_keys( $columns ) as $column ) {
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$quoted} LIKE %s", $column ) );
		if ( $column !== $exists ) {
			throw new RuntimeException( 'Ozon pickup import diagnostics migration postcondition failed.' );
		}
	}
};

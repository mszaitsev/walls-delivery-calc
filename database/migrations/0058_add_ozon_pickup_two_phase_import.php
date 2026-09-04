<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	if ( ! function_exists( 'dbDelta' ) ) {
		throw new RuntimeException( 'WordPress dbDelta function is unavailable.' );
	}

	$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
	$generations = $wpdb->prefix . 'wdc_ozon_delivery_pickup_generations';
	$ids = $wpdb->prefix . 'wdc_ozon_delivery_pickup_ids';
	$quoted_generations = '`' . str_replace( '`', '``', $generations ) . '`';
	$quoted_ids = '`' . str_replace( '`', '``', $ids ) . '`';

	\dbDelta( "CREATE TABLE {$ids} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,generation_id bigint(20) unsigned NOT NULL,point_id bigint(20) unsigned NOT NULL,status varchar(24) NOT NULL DEFAULT 'pending',reject_code varchar(40) NULL,created_at datetime NULL,updated_at datetime NULL,PRIMARY KEY(id),UNIQUE KEY generation_point(generation_id,point_id),KEY generation_status_id(generation_id,status,id),KEY generation_id(generation_id)) {$charset};" );

	$columns = array(
		'phase' => "varchar(16) NOT NULL DEFAULT 'discovery' AFTER state",
		'discovery_page_count' => 'int unsigned NOT NULL DEFAULT 0 AFTER downloaded_count',
		'discovered_count' => 'int unsigned NOT NULL DEFAULT 0 AFTER discovery_page_count',
		'discovery_completed_at' => 'datetime NULL AFTER discovered_count',
		'enrichment_processed_count' => 'int unsigned NOT NULL DEFAULT 0 AFTER discovery_completed_at',
	);

	foreach ( $columns as $column => $definition ) {
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$quoted_generations} LIKE %s", $column ) );
		if ( $column === $exists ) {
			continue;
		}
		if ( false === $wpdb->query( "ALTER TABLE {$quoted_generations} ADD COLUMN {$column} {$definition}" ) ) {
			throw new RuntimeException( 'Ozon pickup two-phase generation migration failed.' );
		}
	}

	foreach ( array_keys( $columns ) as $column ) {
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$quoted_generations} LIKE %s", $column ) );
		if ( $column !== $exists ) {
			throw new RuntimeException( 'Ozon pickup two-phase generation migration postcondition failed.' );
		}
	}

	foreach ( array( 'id', 'generation_id', 'point_id', 'status', 'reject_code', 'created_at', 'updated_at' ) as $column ) {
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$quoted_ids} LIKE %s", $column ) );
		if ( $column !== $exists ) {
			throw new RuntimeException( 'Ozon pickup staging IDs migration postcondition failed.' );
		}
	}

	foreach ( array( 'PRIMARY', 'generation_point', 'generation_status_id', 'generation_id' ) as $index ) {
		$exists = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$quoted_ids} WHERE Key_name=%s", $index ) );
		if ( null === $exists ) {
			throw new RuntimeException( 'Ozon pickup staging IDs index postcondition failed.' );
		}
	}
};

<?php
declare(strict_types=1);
defined( 'ABSPATH' ) || exit;
return static function (): void {
	global $wpdb;
	$table = $wpdb->prefix . 'wdc_ozon_delivery_pickup_points'; $quoted = '`' . str_replace( '`', '``', $table ) . '`';
	$column_exists = static function ( string $column ) use ( $wpdb, $quoted ): bool { $result = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$quoted} LIKE %s", $column ) ); return $column === $result; };
	if ( ! $column_exists( 'schedule' ) ) { if ( false === $wpdb->query( "ALTER TABLE {$quoted} ADD COLUMN schedule text NOT NULL AFTER longitude" ) ) { throw new RuntimeException( 'Ozon pickup schedule migration failed.' ); } }
	if ( $column_exists( 'schedule_json' ) && false === $wpdb->query( "ALTER TABLE {$quoted} DROP COLUMN schedule_json" ) ) { throw new RuntimeException( 'Ozon pickup legacy schedule migration failed.' ); }
	if ( ! $column_exists( 'schedule' ) || $column_exists( 'schedule_json' ) ) { throw new RuntimeException( 'Ozon pickup schedule migration postcondition failed.' ); }
};

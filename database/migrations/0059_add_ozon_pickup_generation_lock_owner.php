<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = $wpdb->prefix . 'wdc_ozon_delivery_pickup_generations';
	$quoted = '`' . str_replace( '`', '``', $table ) . '`';

	$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$quoted} LIKE %s", 'lock_owner' ) );
	if ( 'lock_owner' !== $exists ) {
		if ( false === $wpdb->query( "ALTER TABLE {$quoted} ADD COLUMN lock_owner varchar(64) NULL AFTER job_id" ) ) {
			throw new RuntimeException( 'Ozon pickup generation lock owner migration failed.' );
		}
	}

	$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$quoted} LIKE %s", 'lock_owner' ) );
	if ( 'lock_owner' !== $exists ) {
		throw new RuntimeException( 'Ozon pickup generation lock owner migration postcondition failed.' );
	}
};

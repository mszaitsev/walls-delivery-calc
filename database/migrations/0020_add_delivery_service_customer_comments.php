<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = $wpdb->prefix . 'wdc_delivery_services';
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( null === $table_exists ) {
		return;
	}

	$has_pickup = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'pickup_customer_comment' ) );
	if ( null === $has_pickup ) {
		$wpdb->query( "ALTER TABLE {$table} ADD pickup_customer_comment text NULL AFTER packaging_weight_mode" );
	}

	$has_courier = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'courier_customer_comment' ) );
	if ( null === $has_courier ) {
		$wpdb->query( "ALTER TABLE {$table} ADD courier_customer_comment text NULL AFTER pickup_customer_comment" );
	}
};

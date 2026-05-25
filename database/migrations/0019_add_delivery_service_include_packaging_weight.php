<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = $wpdb->prefix . 'wdc_delivery_services';

	$has_include = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'include_packaging_weight' ) );
	if ( null === $has_include ) {
		$wpdb->query( 'ALTER TABLE ' . $table . ' ADD include_packaging_weight tinyint(1) NOT NULL DEFAULT 1 AFTER minimum_price_rub' );
	}

	$has_mode = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'packaging_weight_mode' ) );
	if ( null === $has_mode ) {
		$wpdb->query( "ALTER TABLE {$table} ADD packaging_weight_mode varchar(30) NOT NULL DEFAULT 'total_weight' AFTER include_packaging_weight" );
	}
};

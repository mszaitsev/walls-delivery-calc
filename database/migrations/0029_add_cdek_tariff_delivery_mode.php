<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

global $wpdb;

$table = $wpdb->prefix . 'wdc_cdek_tariffs';

if ( null === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'delivery_mode' ) ) ) {
	$wpdb->query( "ALTER TABLE {$table} ADD COLUMN delivery_mode TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER delivery_type" );
}
if ( null === $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s', 'idx_delivery_mode' ) ) ) {
	$wpdb->query( "ALTER TABLE {$table} ADD KEY idx_delivery_mode (delivery_mode)" );
}

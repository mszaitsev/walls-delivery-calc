<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = $wpdb->prefix . 'wdc_cdek_tariffs';
	$table_exists = method_exists( $wpdb, 'get_var' ) && (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	if ( ! $table_exists ) {
		return;
	}

	$columns = array(
		'weight_min' => 'DECIMAL(12,3) NULL',
		'weight_max' => 'DECIMAL(12,3) NULL',
		'weight_calc_max' => 'DECIMAL(12,3) NULL',
		'length_min' => 'DECIMAL(12,3) NULL',
		'length_max' => 'DECIMAL(12,3) NULL',
		'width_min' => 'DECIMAL(12,3) NULL',
		'width_max' => 'DECIMAL(12,3) NULL',
		'height_min' => 'DECIMAL(12,3) NULL',
		'height_max' => 'DECIMAL(12,3) NULL',
	);

	foreach ( $columns as $column => $definition ) {
		$exists = (string) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
		if ( '' === $exists ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition} AFTER tariff_name_from_cdek" );
		}
	}
};

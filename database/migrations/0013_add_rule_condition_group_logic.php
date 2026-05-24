<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = $wpdb->prefix . 'wdc_rules';

	$table_exists = static function ( string $table ) use ( $wpdb ): bool {
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return ! in_array( $result, array( null, '', 0, '0' ), true );
	};

	$column_exists = static function ( string $table, string $column ) use ( $wpdb ): bool {
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ), ARRAY_A );
		return is_array( $row ) && array() !== $row;
	};

	if ( ! $table_exists( $table ) ) {
		return;
	}

	if ( ! $column_exists( $table, 'condition_group_logic' ) ) {
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN condition_group_logic longtext NULL AFTER stop_processing" );
	}

	$wpdb->query( "UPDATE {$table} SET condition_group_logic = '{\"1\":\"and\",\"2\":\"and\",\"3\":\"and\"}' WHERE condition_group_logic IS NULL OR condition_group_logic = ''" );
};

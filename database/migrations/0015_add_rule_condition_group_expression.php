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

	if ( ! $table_exists( $table ) || $column_exists( $table, 'condition_group_expression' ) ) {
		return;
	}

	$wpdb->query( "ALTER TABLE {$table} ADD COLUMN condition_group_expression varchar(80) NOT NULL DEFAULT 'condition_1_or_2_or_3' AFTER condition_group_logic" );
};

<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMappingRepository;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wdc_jet_logistic_status_mapping_column_exists' ) ) {
	function wdc_jet_logistic_status_mapping_column_exists( \wpdb $wpdb, string $table, string $column ): bool {
		$rows = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ), ARRAY_A );
		return is_array( $rows ) && array() !== $rows;
	}
}

if ( ! function_exists( 'wdc_jet_logistic_status_mapping_index_exists' ) ) {
	function wdc_jet_logistic_status_mapping_index_exists( \wpdb $wpdb, string $table, string $index ): bool {
		$rows = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index ), ARRAY_A );
		return is_array( $rows ) && array() !== $rows;
	}
}

return static function (): void {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}

	if ( ! function_exists( 'dbDelta' ) ) {
		throw new \RuntimeException( 'WordPress dbDelta function is unavailable.' );
	}

	global $wpdb;
	$repository = new JetLogisticStatusMappingRepository();
	$repository->create_schema();
	$repository->remove_legacy_broad_defaults();
	$repository->ensure_default_mappings();

	$table = $wpdb->prefix . 'wdc_jet_logistic_status_mappings';
	foreach ( array( 'active_status', 'last_seen' ) as $index ) {
		if ( wdc_jet_logistic_status_mapping_index_exists( $wpdb, $table, $index ) ) {
			$wpdb->query( "DROP INDEX {$index} ON {$table}" );
		}
	}
	foreach ( array( 'active', 'last_seen', 'occurrence_count' ) as $column ) {
		if ( wdc_jet_logistic_status_mapping_column_exists( $wpdb, $table, $column ) ) {
			$wpdb->query( "ALTER TABLE {$table} DROP COLUMN {$column}" );
		}
	}
};

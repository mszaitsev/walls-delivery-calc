<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();
require_once __DIR__ . '/shipment-cost-analytics-test-helpers.php';

use WallsShop\WDC\Shipments\Analytics\Storage\ShipmentCostAnalyticsRecord;
use WallsShop\WDC\Shipments\Analytics\Storage\ShipmentCostAnalyticsTable;

function shipment_cost_analytics_storage_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$context = shipment_cost_analytics_test_bootstrap();
$table = $context['table'];
$repository = $context['repository'];

$schema = $table->schema();
foreach ( array( 'UNIQUE KEY uniq_order_id (order_id)', 'KEY idx_created_at (order_created_at)', 'KEY idx_carrier_created (carrier_key, order_created_at)', 'KEY idx_order_number (order_number)', 'KEY idx_actual_cost (actual_cost_kopecks)', 'KEY idx_base_cost (base_api_cost_kopecks)', 'KEY idx_difference (difference_kopecks)', 'KEY idx_difference_percent (difference_percent_basis_points)', 'KEY idx_threshold_created (threshold_status, order_created_at)' ) as $expected ) {
	shipment_cost_analytics_storage_assert( str_contains( $schema, $expected ), 'Schema must contain index: ' . $expected );
}
shipment_cost_analytics_storage_assert( ShipmentCostAnalyticsTable::MIGRATION === '0041_create_shipment_cost_analytics_table.php' && is_file( dirname( __DIR__, 2 ) . '/database/migrations/' . ShipmentCostAnalyticsTable::MIGRATION ), 'Schema migration must be registered as a migration file.' );

$record = new ShipmentCostAnalyticsRecord( 10, 'WC-10', '2026-07-20 07:00:00', 'alpha', 'alpha_service', 'Alpha Service', 'alpha', 'A-10', 10000, 12000, 'RUB', 'carrier_api', 'create', '2026-07-20 08:00:00', 2000, 2000, 'over_threshold', '2026-07-21 07:00:00' );
$repository->upsert( $record );
$repository->upsert( new ShipmentCostAnalyticsRecord( 10, 'WC-10', '2026-07-20 07:00:00', 'alpha', 'alpha_service', 'Alpha Service', 'alpha', 'A-10', 10000, 9000, 'RUB', 'manual', 'admin_manual', '2026-07-20 09:00:00', -1000, -1000, 'within_threshold', '2026-07-21 07:05:00' ) );

shipment_cost_analytics_storage_assert( 1 === count( $GLOBALS['wpdb']->rows ), 'Upsert by order_id must keep one row.' );
$stored = array_values( $GLOBALS['wpdb']->rows )[0];
shipment_cost_analytics_storage_assert( 9000 === (int) $stored['actual_cost_kopecks'] && 'manual' === (string) $stored['actual_cost_source'], 'Second upsert must update the same row.' );

$repository->upsert( new ShipmentCostAnalyticsRecord( 11, 'WC-11', '2026-07-20 07:00:00', 'alpha', 'alpha_service', 'Alpha Service', 'alpha', 'A-11', null, null, 'RUB', '', '', null, null, null, 'not_comparable', '2026-07-21 07:10:00' ) );
$null_row = $GLOBALS['wpdb']->rows[2] ?? array();
foreach ( array( 'base_api_cost_kopecks', 'actual_cost_kopecks', 'actual_cost_updated_at', 'difference_kopecks', 'difference_percent_basis_points' ) as $nullable_field ) {
	shipment_cost_analytics_storage_assert( array_key_exists( $nullable_field, $null_row ) && null === $null_row[ $nullable_field ], $nullable_field . ' must be stored as SQL NULL, not zero or empty string.' );
}
$last_query = end( $GLOBALS['wpdb']->queries );
shipment_cost_analytics_storage_assert( is_array( $last_query ) && str_contains( (string) $last_query['sql'], 'base_api_cost_kopecks, actual_cost_kopecks' ) && str_contains( (string) $last_query['sql'], 'NULL, NULL' ) && str_contains( (string) $last_query['sql'], 'actual_cost_kopecks = NULL' ), 'Upsert SQL must use NULL literals for nullable insert and update values.' );

$GLOBALS['wpdb']->fail_next_query = true;
$failed = false;
try {
	$repository->upsert( new ShipmentCostAnalyticsRecord( 12, 'WC-12', '2026-07-20 07:00:00', 'alpha', 'alpha_service', 'Alpha Service', 'alpha', 'A-12', null, null, 'RUB', '', '', null, null, null, 'not_comparable', '2026-07-21 07:15:00' ) );
} catch ( RuntimeException $e ) {
	$failed = str_contains( $e->getMessage(), 'simulated analytics SQL failure' );
}
shipment_cost_analytics_storage_assert( $failed, 'Repository must throw RuntimeException with wpdb last_error when SQL query fails.' );

$repository->delete_by_order_id( 10 );
shipment_cost_analytics_storage_assert( ! isset( $GLOBALS['wpdb']->rows[1] ), 'delete_by_order_id must remove the requested analytics row.' );

echo "Shipment cost analytics storage smoke passed.\n";

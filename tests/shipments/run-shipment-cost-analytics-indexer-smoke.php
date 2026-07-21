<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();
require_once __DIR__ . '/shipment-cost-analytics-test-helpers.php';

function shipment_cost_analytics_indexer_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$context = shipment_cost_analytics_test_bootstrap();
$indexer = $context['indexer'];
$repository = new WallsShop\WDC\Shipments\Storage\OrderShipmentRepository();

$order = shipment_cost_analytics_register_order( new ShipmentCostAnalyticsFakeOrder( 101, 'WC-101', '2026-07-20 10:00:00', 10000, array(), 'alpha', 'alpha_service' ) );
$indexer->sync_order( $order );
shipment_cost_analytics_indexer_assert( 0 === count( $GLOBALS['wpdb']->rows ), 'Order without created shipment must not create analytics row.' );

$repository->save_for_carrier( $order, 'alpha', array( 'carrier_key' => 'alpha', 'service_key' => 'alpha_service', 'service_title' => 'Alpha Service', 'tracking_number' => 'A-101' ) );
$indexer->sync_order( $order );
shipment_cost_analytics_indexer_assert( 1 === count( $GLOBALS['wpdb']->rows ), 'Created matching shipment must create analytics row.' );
$row = array_values( $GLOBALS['wpdb']->rows )[0];
shipment_cost_analytics_indexer_assert( null === $row['actual_cost_kopecks'] && null === $row['difference_kopecks'] && 'not_comparable' === $row['threshold_status'], 'Missing actual cost must keep row not comparable.' );

$repository->save_for_carrier( $order, 'alpha', array_merge( $order->shipments['alpha'], array( 'actual_cost_kopecks' => 12000, 'actual_cost_currency' => 'RUB', 'actual_cost_source' => 'carrier_api', 'actual_cost_source_detail' => 'status', 'actual_cost_updated_at' => '2026-07-20 11:00:00' ) ) );
$indexer->sync_order( $order );
$row = array_values( $GLOBALS['wpdb']->rows )[0];
shipment_cost_analytics_indexer_assert( 12000 === (int) $row['actual_cost_kopecks'] && 2000 === (int) $row['difference_kopecks'] && 2000 === (int) $row['difference_percent_basis_points'] && 'over_threshold' === $row['threshold_status'], 'Actual cost update must refresh difference, percent and threshold.' );

$repository->save_for_carrier( $order, 'alpha', array_diff_key( $order->shipments['alpha'], array_flip( array( 'actual_cost_kopecks', 'actual_cost_currency', 'actual_cost_source', 'actual_cost_source_detail', 'actual_cost_updated_at' ) ) ) );
$indexer->sync_order( $order );
$row = array_values( $GLOBALS['wpdb']->rows )[0];
shipment_cost_analytics_indexer_assert( null === $row['actual_cost_kopecks'] && null === $row['difference_kopecks'] && 'not_comparable' === $row['threshold_status'], 'Manual clear must keep row and clear comparable values.' );

$order_with_two = shipment_cost_analytics_register_order( new ShipmentCostAnalyticsFakeOrder(
	102,
	'WC-102',
	'2026-07-20 12:00:00',
	15000,
	array(
		'alpha' => array( 'carrier_key' => 'alpha', 'service_key' => 'alpha_service', 'tracking_number' => 'A-102', 'actual_cost_kopecks' => 15000 ),
		'beta' => array( 'carrier_key' => 'beta', 'service_key' => 'beta_service', 'tracking_number' => 'B-102', 'actual_cost_kopecks' => 16000 ),
	),
	'alpha',
	'alpha_service'
) );
$indexer->sync_order( $order_with_two );
$row = $GLOBALS['wpdb']->rows[2] ?? array_values( $GLOBALS['wpdb']->rows )[1];
shipment_cost_analytics_indexer_assert( 'alpha' === (string) $row['carrier_key'], 'Initially selected delivery must index alpha shipment.' );

$changed = shipment_cost_analytics_register_order( new ShipmentCostAnalyticsFakeOrder( 102, 'WC-102', '2026-07-20 12:00:00', 15000, $order_with_two->shipments, 'beta', 'beta_service' ) );
$indexer->sync_order( $changed );
$rows = array_values( array_filter( $GLOBALS['wpdb']->rows, static fn( array $candidate ): bool => 102 === (int) $candidate['order_id'] ) );
shipment_cost_analytics_indexer_assert( 1 === count( $rows ) && 'beta' === (string) $rows[0]['carrier_key'], 'Selected delivery change must update analytics row to matching carrier.' );

$missing = shipment_cost_analytics_register_order( new ShipmentCostAnalyticsFakeOrder( 102, 'WC-102', '2026-07-20 12:00:00', 15000, $order_with_two->shipments, 'fresh', 'fresh_service' ) );
$indexer->sync_order( $missing );
$rows = array_values( array_filter( $GLOBALS['wpdb']->rows, static fn( array $candidate ): bool => 102 === (int) $candidate['order_id'] ) );
shipment_cost_analytics_indexer_assert( array() === $rows, 'Selected delivery without matching shipment must delete analytics row.' );

$indexer->delete_order( 101 );
shipment_cost_analytics_indexer_assert( array() === $GLOBALS['wpdb']->rows, 'Order deletion must delete analytics row.' );

echo "Shipment cost analytics indexer smoke passed.\n";

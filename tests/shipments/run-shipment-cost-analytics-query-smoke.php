<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();
require_once __DIR__ . '/shipment-cost-analytics-test-helpers.php';

use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsFilter;
use WallsShop\WDC\Shipments\Analytics\Storage\ShipmentCostAnalyticsRecord;

function shipment_cost_analytics_query_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$context = shipment_cost_analytics_test_bootstrap();
$repository = $context['repository'];
$service = $context['service'];
$options = $service->carrier_options();
shipment_cost_analytics_query_assert( isset( $options['fresh'] ) && 'Fresh Dynamic Carrier' === $options['fresh'], 'Dynamic carrier must appear in registry-driven filter options.' );

for ( $i = 1; $i <= 30; ++$i ) {
	$carrier = 0 === $i % 3 ? 'fresh' : ( 0 === $i % 2 ? 'beta' : 'alpha' );
	$actual = 0 === $i % 5 ? null : 10000 + ( $i * 100 );
	$base = 10000;
	$difference = null !== $actual ? $actual - $base : null;
	$percent = null !== $difference ? intdiv( $difference * 10000, $base ) : null;
	$repository->upsert(
		new ShipmentCostAnalyticsRecord(
			1000 + $i,
			'ORD-' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ),
			'2026-07-' . str_pad( (string) ( 1 + $i % 20 ), 2, '0', STR_PAD_LEFT ) . ' 07:00:00',
			$carrier,
			$carrier . '_service',
			ucfirst( $carrier ) . ' Service',
			$carrier,
			'S-' . $i,
			$base,
			$actual,
			'RUB',
			null !== $actual ? 'carrier_api' : '',
			null !== $actual ? 'fixture' : '',
			null !== $actual ? '2026-07-20 07:00:00' : null,
			$difference,
			$percent,
			null === $actual ? 'not_comparable' : ( $actual * 100 <= $base * 103 ? 'within_threshold' : 'over_threshold' ),
			'2026-07-21 07:00:00'
		)
	);
}

$filter = ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'per_page' => 25 ), $options, new DateTimeImmutable( '2026-07-21 00:00:00', new DateTimeZone( 'Europe/Moscow' ) ) );
$result = $service->result( $filter );
shipment_cost_analytics_query_assert( 30 === $result->total_rows && 25 === count( $result->rows ) && 2 === $result->total_pages && 1 === $result->current_page, 'Query must paginate SQL rows and count full dataset.' );
shipment_cost_analytics_query_assert( 30 === $result->summary->shipment_count && 24 === $result->summary->with_actual_count && 6 === $result->summary->without_actual_count, 'Summary must aggregate full filtered dataset, not current page only.' );

$page2 = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'per_page' => 25, 'paged' => 999 ), $options, new DateTimeImmutable( '2026-07-21 00:00:00', new DateTimeZone( 'Europe/Moscow' ) ) ) );
shipment_cost_analytics_query_assert( 2 === $page2->current_page && 5 === count( $page2->rows ), 'Requested page beyond total pages must clamp to current_page.' );

$actual_only = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month' ), $options, new DateTimeImmutable( '2026-07-21 00:00:00', new DateTimeZone( 'Europe/Moscow' ) ) ) );
shipment_cost_analytics_query_assert( 24 === $actual_only->total_rows, 'Default actual mode must filter rows without positive actual cost.' );

$carrier = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'carrier' => 'fresh' ), $options, new DateTimeImmutable( '2026-07-21 00:00:00', new DateTimeZone( 'Europe/Moscow' ) ) ) );
shipment_cost_analytics_query_assert( $carrier->total_rows > 0 && 'fresh' === $carrier->rows[0]->carrier_key && 'Fresh Dynamic Carrier' === $carrier->rows[0]->carrier_title, 'Concrete carrier filter must use indexed carrier key and registry title.' );

$search_id = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'order_search' => '1007' ), $options, new DateTimeImmutable( '2026-07-21 00:00:00', new DateTimeZone( 'Europe/Moscow' ) ) ) );
shipment_cost_analytics_query_assert( 1 === $search_id->total_rows && 1007 === $search_id->rows[0]->order_id, 'Order ID exact search must work.' );
$search_number = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'order_search' => 'ORD-08' ), $options, new DateTimeImmutable( '2026-07-21 00:00:00', new DateTimeZone( 'Europe/Moscow' ) ) ) );
shipment_cost_analytics_query_assert( 1 === $search_number->total_rows && 'ORD-08' === $search_number->rows[0]->order_number, 'Order number exact search must work.' );

foreach ( array( 'week', 'month', 'quarter', 'year', 'custom' ) as $period ) {
	$request = array( 'analytics_period' => $period, 'actual_cost_mode' => 'all' );
	if ( 'custom' === $period ) {
		$request['date_from'] = '2026-07-01';
		$request['date_to'] = '2026-07-21';
	}
	$preset = ShipmentCostAnalyticsFilter::from_request( $request, $options, new DateTimeImmutable( '2026-07-21 00:00:00', new DateTimeZone( 'Europe/Moscow' ) ) );
	shipment_cost_analytics_query_assert( $period === $preset->period, 'Period must normalize: ' . $period );
}

foreach ( array( 'order_number', 'date', 'carrier', 'base', 'actual', 'difference', 'difference_percent' ) as $orderby ) {
	$asc = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'orderby' => $orderby, 'order' => 'asc', 'per_page' => 100 ), $options, new DateTimeImmutable( '2026-07-21 00:00:00', new DateTimeZone( 'Europe/Moscow' ) ) ) );
	$desc = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'orderby' => $orderby, 'order' => 'desc', 'per_page' => 100 ), $options, new DateTimeImmutable( '2026-07-21 00:00:00', new DateTimeZone( 'Europe/Moscow' ) ) ) );
	shipment_cost_analytics_query_assert( 30 === count( $asc->rows ) && 30 === count( $desc->rows ), 'Sorting must keep full row set for: ' . $orderby );
}

$sorted_actual = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'orderby' => 'actual', 'order' => 'desc', 'per_page' => 100 ), $options, new DateTimeImmutable( '2026-07-21 00:00:00', new DateTimeZone( 'Europe/Moscow' ) ) ) );
shipment_cost_analytics_query_assert( null === $sorted_actual->rows[ count( $sorted_actual->rows ) - 1 ]->actual_cost_kopecks, 'Null actual values must sort last.' );

echo "Shipment cost analytics query smoke passed.\n";

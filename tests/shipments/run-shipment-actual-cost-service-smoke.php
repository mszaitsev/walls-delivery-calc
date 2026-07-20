<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Shipments\Application\ShipmentActualCost;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;
use WallsShop\WDC\Shipments\Presentation\ShipmentActualCostComparisonService;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function shipment_actual_cost_service_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class ShipmentActualCostServiceOrder {
	public int $save_count = 0;

	/** @param array<string,mixed> $meta */
	public function __construct( public array $meta ) {}

	public function get_meta( string $key, bool $single = true ): mixed {
		unset( $single );
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function save(): void {
		++$this->save_count;
	}
}

$repository = new OrderShipmentRepository();
$resolver = new ShipmentActualCostResolver( new ShipmentActualCostComparisonService(), new ShipmentBaseApiCostResolver() );
$service = new ShipmentActualCostService( $repository, $resolver );

$order = new ShipmentActualCostServiceOrder(
	array(
		OrderShipmentRepository::META_KEY => array(
			DpdSettings::CARRIER_KEY => array(
				'carrier_key' => DpdSettings::CARRIER_KEY,
				'status' => 'created',
				'tracking_number' => 'DPD-1',
			),
		),
	)
);

$manual = $service->manual_set( $order, DpdSettings::CARRIER_KEY, 123456 );
shipment_actual_cost_service_assert( 123456 === (int) ( $manual['actual_cost_kopecks'] ?? 0 ), 'Manual cost must be stored as integer kopecks.' );
shipment_actual_cost_service_assert( 'RUB' === (string) ( $manual['actual_cost_currency'] ?? '' ), 'Manual cost currency must be RUB.' );
shipment_actual_cost_service_assert( 'manual' === (string) ( $manual['actual_cost_source'] ?? '' ), 'Manual cost source must be manual.' );
shipment_actual_cost_service_assert( '' !== (string) ( $manual['actual_cost_updated_at'] ?? '' ), 'Manual cost updated_at must be set.' );
shipment_actual_cost_service_assert( 'DPD-1' === (string) ( $manual['tracking_number'] ?? '' ), 'Manual save must preserve existing shipment fields.' );

$locked = $service->apply_carrier_cost( $order, DpdSettings::CARRIER_KEY, new ShipmentActualCost( 99999, 'RUB', 'carrier_status', 'dpd_events' ) );
shipment_actual_cost_service_assert( 123456 === (int) ( $locked['actual_cost_kopecks'] ?? 0 ) && 'manual' === (string) ( $locked['actual_cost_source'] ?? '' ), 'Carrier update must not overwrite manual actual cost.' );

$cleared = $service->manual_clear( $order, DpdSettings::CARRIER_KEY );
shipment_actual_cost_service_assert( ! array_key_exists( 'actual_cost_kopecks', $cleared ) && ! array_key_exists( 'actual_cost_source', $cleared ), 'Manual clear must remove canonical actual cost fields.' );

$automatic = $service->apply_carrier_cost( $order, DpdSettings::CARRIER_KEY, new ShipmentActualCost( 99999, 'RUB', 'carrier_status', 'dpd_events' ) );
shipment_actual_cost_service_assert( 99999 === (int) ( $automatic['actual_cost_kopecks'] ?? 0 ) && 'carrier_status' === (string) ( $automatic['actual_cost_source'] ?? '' ), 'Carrier update must save actual cost after manual clear.' );

$zero = $service->apply_carrier_cost( $order, DpdSettings::CARRIER_KEY, new ShipmentActualCost( 0, 'RUB', 'carrier_status', 'dpd_events' ) );
shipment_actual_cost_service_assert( array_key_exists( 'actual_cost_kopecks', $zero ) && 0 === $zero['actual_cost_kopecks'], 'Canonical actual cost must preserve explicit zero.' );

$legacy = array( 'russian_post_actual_cost_kopecks' => 39998, 'russian_post_actual_cost_source' => 'shipment_search' );
$canonical = $resolver->with_legacy_canonical_fields( $legacy, '2026-07-21 10:00:00' );
shipment_actual_cost_service_assert( 39998 === (int) ( $canonical['actual_cost_kopecks'] ?? 0 ) && 'legacy_import' === (string) ( $canonical['actual_cost_source'] ?? '' ), 'Russian Post legacy actual cost must resolve to canonical fields lazily.' );

try {
	$service->manual_set( $order, DpdSettings::CARRIER_KEY, -1 );
	shipment_actual_cost_service_assert( false, 'Negative manual actual cost must be rejected.' );
} catch ( InvalidArgumentException ) {
}

try {
	$service->manual_set( $order, RussianPostDomesticSettings::CARRIER_KEY, 100 );
	shipment_actual_cost_service_assert( false, 'Unknown carrier shipment must be rejected.' );
} catch ( InvalidArgumentException ) {
}

echo "Shipment actual cost service smoke passed.\n";

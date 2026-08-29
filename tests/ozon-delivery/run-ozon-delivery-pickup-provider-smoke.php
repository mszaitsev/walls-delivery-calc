<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupPointProvider;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupRepository;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;
use WallsShop\WDC\Pickup\Rest\CheckoutPickupPointRestController;
use WallsShop\WDC\Pickup\Rest\PickupPointsRestController;

function oz_pickup_provider_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class OzonPickupProviderWpdb {
	public string $prefix = 'wp_';
	/** @var array<int,array<string,mixed>> */ public array $ozon_delivery_pickup_generations = array();
	/** @var array<int,array<string,mixed>> */ public array $ozon_delivery_pickup_points = array();
	public function prepare( string $query, mixed ...$values ): string {
		foreach ( $values as $value ) {
			$query = preg_replace( '/%[df]/', is_float( $value ) ? sprintf( '%.8F', $value ) : (string) (int) $value, $query, 1 ) ?? $query;
		}
		return $query;
	}
	public function get_row( string $query, mixed $output = null ): ?array {
		unset( $output );
		if ( str_contains( $query, "WHERE state='active'" ) ) {
			$rows = array_values( array_filter( $this->ozon_delivery_pickup_generations, static fn( array $row ): bool => 'active' === (string) $row['state'] ) );
			usort( $rows, static fn( array $left, array $right ): int => (int) $right['id'] <=> (int) $left['id'] );
			return $rows[0] ?? null;
		}
		if ( 1 === preg_match( '/generation_id=(\d+) AND point_id=(\d+)/', $query, $matches ) ) {
			foreach ( $this->ozon_delivery_pickup_points as $row ) { if ( (int) $row['generation_id'] === (int) $matches[1] && (int) $row['point_id'] === (int) $matches[2] ) { return $row; } }
		}
		return null;
	}
	/** @return array<int,array<string,mixed>> */
	public function get_results( string $query, mixed $output = null ): array {
		unset( $output );
		preg_match( '/generation_id=(\d+).*latitude BETWEEN ([0-9.\-]+) AND ([0-9.\-]+).*longitude BETWEEN ([0-9.\-]+) AND ([0-9.\-]+).*LIMIT (\d+)/', $query, $matches );
		if ( 7 !== count( $matches ) ) { return array(); }
		$rows = array_filter( $this->ozon_delivery_pickup_points, static fn( array $row ): bool => (int) $row['generation_id'] === (int) $matches[1] && 1 === (int) $row['is_active'] && (float) $row['latitude'] >= (float) $matches[2] && (float) $row['latitude'] <= (float) $matches[3] && (float) $row['longitude'] >= (float) $matches[4] && (float) $row['longitude'] <= (float) $matches[5] );
		usort( $rows, static fn( array $left, array $right ): int => (int) $left['point_id'] <=> (int) $right['point_id'] );
		return array_slice( array_values( $rows ), 0, (int) $matches[6] );
	}
}

/** @return CarrierPickupPointQuery */
function oz_pickup_provider_query( int $limit = 10, int $weight_g = 0 ): CarrierPickupPointQuery {
	return new CarrierPickupPointQuery( OzonDeliverySettings::CARRIER_KEY, 1, 'RU', '', 55.0300, 82.9200, new PickupCargoConstraints( $weight_g, 0, 0, $weight_g, 1 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 10, $limit );
}

/** @return array<string,mixed> */
function oz_pickup_provider_point( int $generation_id, int $point_id, string $name, float $latitude = 55.0300, float $longitude = 82.9200 ): array {
	return array( 'generation_id' => $generation_id, 'point_id' => $point_id, 'name' => $name, 'type' => 'pvz', 'full_address' => 'Новосибирск, Тестовая улица, ' . $point_id, 'latitude' => $latitude, 'longitude' => $longitude, 'schedule' => 'Ежедневно 09:00-21:00', 'is_active' => 1, 'min_weight_g' => null, 'max_weight_g' => null, 'max_width_mm' => null, 'max_length_mm' => null, 'max_height_mm' => null );
}

$wpdb = new OzonPickupProviderWpdb();
$wpdb->ozon_delivery_pickup_generations = array(
	array( 'id' => 1, 'state' => 'active' ),
	array( 'id' => 2, 'state' => 'building' ),
	array( 'id' => 3, 'state' => 'failed' ),
	array( 'id' => 4, 'state' => 'obsolete' ),
);
$wpdb->ozon_delivery_pickup_points = array(
	oz_pickup_provider_point( 1, 101, 'Пункт Ozon' ),
	oz_pickup_provider_point( 1, 102, 'Постамат Ozon', 55.0400, 82.9300 ),
	oz_pickup_provider_point( 2, 201, 'Строящийся пункт' ),
	oz_pickup_provider_point( 3, 301, 'Неудачный пункт' ),
	oz_pickup_provider_point( 4, 401, 'Устаревший пункт' ),
	oz_pickup_provider_point( 1, 501, 'Далёкий пункт', 56.0000, 83.0000 ),
);
$repository = new OzonDeliveryPickupRepository( $wpdb );
$provider = new OzonDeliveryPickupPointProvider( $repository );
$query = oz_pickup_provider_query( 2 );

oz_pickup_provider_assert( OzonDeliverySettings::CARRIER_KEY === $provider->carrier_key(), 'Provider carrier key must be ozon_delivery.' );
oz_pickup_provider_assert( OzonDeliverySettings::PICKUP_FAMILY === 'ozon_delivery:pickup', 'Ozon pickup family must stay stable.' );
$registry = new CarrierPickupPointProviderRegistry( array( $provider ) );
oz_pickup_provider_assert( $registry->get( OzonDeliverySettings::CARRIER_KEY ) === $provider, 'Provider must be registry-compatible.' );
try { new CarrierPickupPointProviderRegistry( array( $provider, $provider ) ); oz_pickup_provider_assert( false, 'Duplicate provider registration must be rejected.' ); } catch ( InvalidArgumentException ) {}

$points = $provider->search( $query );
oz_pickup_provider_assert( 2 === count( $points ) && '101' === $points[0]->code && '102' === $points[1]->code, 'Only bounded active-generation points in the trusted coordinate radius must be visible.' );
oz_pickup_provider_assert( 'Ежедневно 09:00-21:00' === $points[0]->work_time && 'Пункт Ozon' === $points[0]->raw_reference['point_name'], 'Provider must return the persisted presentation schedule and safe point name.' );
$dto = $points[0]->to_array();
oz_pickup_provider_assert( ! isset( $dto['generation_id'], $dto['fingerprint'], $dto['id'] ) && ! isset( $dto['raw_reference']['generation_id'], $dto['raw_reference']['fingerprint'] ), 'Provider DTO must not expose generation, fingerprint or database identity.' );
$rest = ( new ReflectionClass( PickupPointsRestController::class ) )->newInstanceWithoutConstructor();
$payload_method = new ReflectionMethod( PickupPointsRestController::class, 'registry_point_payload' );
$payload = $payload_method->invoke( $rest, $points[0], OzonDeliverySettings::CARRIER_KEY, OzonDeliverySettings::PICKUP_FAMILY, 'safe-fingerprint', 1, 'RU' );
oz_pickup_provider_assert( '101' === $payload['point_code'] && 'Пункт выдачи Ozon' === $payload['point_title'] && 'Пункт Ozon' === $payload['point_name'] && 'Ежедневно 09:00-21:00' === $payload['work_time'] && ! isset( $payload['generation_id'], $payload['fingerprint'], $payload['raw_reference'] ), 'Generic pickup REST presentation must return only the Ozon provider safe DTO.' );
$checkout_rest = ( new ReflectionClass( CheckoutPickupPointRestController::class ) )->newInstanceWithoutConstructor();
$selection_method = new ReflectionMethod( CheckoutPickupPointRestController::class, 'selection_from_provider_point' );
$selection_payload = $selection_method->invoke( $checkout_rest, $points[0], OzonDeliverySettings::CARRIER_KEY, OzonDeliverySettings::PICKUP_FAMILY, 'safe-fingerprint', 1, 'RU' );
oz_pickup_provider_assert( 'Пункт выдачи Ozon' === $selection_payload['point_title'] && 'Пункт Ozon' === $selection_payload['point_name'] && '101' === $selection_payload['point_code'], 'Generic server-side selection persistence must retain Ozon presentation without a PEK title.' );
oz_pickup_provider_assert( array() === $provider->search( new CarrierPickupPointQuery( OzonDeliverySettings::CARRIER_KEY, 1, 'RU', '', null, null, new PickupCargoConstraints(), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 10, 10 ) ), 'Provider must fail closed without trusted destination coordinates.' );

$wpdb->ozon_delivery_pickup_points[] = array_merge( oz_pickup_provider_point( 1, 601, 'Ограниченный пункт' ), array( 'max_weight_g' => 500 ) );
oz_pickup_provider_assert( ! in_array( '601', array_map( static fn( $point ): string => $point->code, $provider->search( oz_pickup_provider_query( 20, 1000 ) ) ), true ), 'Trusted cargo constraints must filter incompatible active points.' );
$selection = $provider->resolve_selection( new CarrierPickupPointSelectionQuery( $query, '101' ) );
oz_pickup_provider_assert( null !== $selection && '101' === $selection->code, 'Server-side selection must resolve the current active point by stable Ozon ID.' );
oz_pickup_provider_assert( null === $provider->resolve_selection( new CarrierPickupPointSelectionQuery( $query, '201' ) ) && null === $provider->resolve_selection( new CarrierPickupPointSelectionQuery( $query, '999999' ) ), 'Building and missing points must not resolve.' );

$wpdb->ozon_delivery_pickup_generations[0]['state'] = 'obsolete';
$wpdb->ozon_delivery_pickup_generations[1]['state'] = 'active';
$wpdb->ozon_delivery_pickup_points[] = array_merge( oz_pickup_provider_point( 2, 101, 'Пункт Ozon после синхронизации' ), array( 'schedule' => 'Пн-Пт 10:00-20:00' ) );
$switched = $provider->resolve_selection( new CarrierPickupPointSelectionQuery( $query, '101' ) );
oz_pickup_provider_assert( null !== $switched && 'Пункт Ozon после синхронизации' === $switched->raw_reference['point_name'] && 'Пн-Пт 10:00-20:00' === $switched->work_time, 'Snapshot switch must retain point identity while refreshing presentation from the new active row.' );
$wpdb->ozon_delivery_pickup_points = array_values( array_filter( $wpdb->ozon_delivery_pickup_points, static fn( array $row ): bool => ! ( 2 === (int) $row['generation_id'] && 101 === (int) $row['point_id'] ) ) );
oz_pickup_provider_assert( null === $provider->resolve_selection( new CarrierPickupPointSelectionQuery( $query, '101' ) ), 'A point removed from the active snapshot must become invalid.' );

$root = dirname( __DIR__, 2 );
$provider_source = (string) file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupPointProvider.php' );
$repository_source = (string) file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupRepository.php' );
$plugin_source = (string) file_get_contents( $root . '/src/Core/Plugin.php' );
$pickup_rest_source = (string) file_get_contents( $root . '/src/Pickup/Rest/PickupPointsRestController.php' );
$checkout_rest_source = (string) file_get_contents( $root . '/src/Pickup/Rest/CheckoutPickupPointRestController.php' );
oz_pickup_provider_assert( str_contains( $provider_source, 'CarrierPickupPointProviderInterface' ) && str_contains( $provider_source, 'find_active_in_radius' ) && ! str_contains( $provider_source, 'OzonDeliveryApiClient' ) && ! str_contains( $provider_source, 'pickup_list' ), 'Ozon provider must implement the canonical interface and read only local data.' );
oz_pickup_provider_assert( str_contains( $repository_source, 'generation_id=%d AND is_active=1') && str_contains( $repository_source, 'latitude BETWEEN %f AND %f') && str_contains( $repository_source, 'longitude BETWEEN %f AND %f'), 'Ozon radius lookup must filter the active generation in SQL.' );
oz_pickup_provider_assert( str_contains( $plugin_source, 'OzonDeliveryPickupPointProvider::class' ) && str_contains( $plugin_source, 'CarrierPickupPointProviderRegistry' ) && ! str_contains( $plugin_source, 'OzonDeliveryCarrier::class' ), 'Plugin must register only the Ozon pickup provider, not a runtime carrier.' );
oz_pickup_provider_assert( ! str_contains( $pickup_rest_source, 'ozon_delivery' ) && ! str_contains( $checkout_rest_source, 'ozon_delivery' ) && str_contains( $pickup_rest_source, 'registry_point_payload' ), 'Generic pickup REST must use provider presentation without Ozon-specific branches.' );

echo "Ozon Delivery pickup provider smoke passed.\n";

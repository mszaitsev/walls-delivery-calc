<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup;

use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderInterface;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryPickupPointProvider implements CarrierPickupPointProviderInterface {
	public function __construct( private OzonDeliveryPickupRepository $repository ) {}
	public function carrier_key(): string { return OzonDeliverySettings::CARRIER_KEY; }
	/** @return array<int,PickupPoint> */
	public function search( CarrierPickupPointQuery $query ): array {
		if ( array() !== $query->validate() || OzonDeliverySettings::CARRIER_KEY !== $query->normalized_carrier_key() || 'RU' !== $query->normalized_country_code() || null === $query->latitude || null === $query->longitude ) { return array(); }
		$latitude_delta = rad2deg( $query->radius_km / 6371.0088 );
		$longitude_delta = rad2deg( $query->radius_km / ( 6371.0088 * max( 0.01, abs( cos( deg2rad( $query->latitude ) ) ) ) ) );
		$points = array();
		foreach ( $this->repository->find_active_in_area( $query->latitude, $query->longitude, $latitude_delta, $longitude_delta ) as $row ) { $point = $this->point( $row, $query ); if ( $point instanceof PickupPoint && $this->within_radius( $point, $query ) ) { $points[] = $point; } }
		usort( $points, fn( PickupPoint $left, PickupPoint $right ): int => $this->distance_score( $left, $query ) <=> $this->distance_score( $right, $query ) );
		return $points;
	}
	public function resolve_selection( CarrierPickupPointSelectionQuery $query ): ?PickupPoint {
		if ( array() !== $query->validate() || OzonDeliverySettings::CARRIER_KEY !== $query->query->normalized_carrier_key() || ! ctype_digit( trim( $query->point_code ) ) ) { return null; }
		$row = $this->repository->find_active( (int) $query->point_code );
		$point = is_array( $row ) ? $this->point( $row, $query->query ) : null;
		return $point instanceof PickupPoint && $this->within_radius( $point, $query->query ) ? $point : null;
	}
	/** @param array<string,mixed> $snapshot */
	public function query_from_snapshot( array $snapshot ): ?CarrierPickupPointQuery {
		$cargo = is_array( $snapshot['cargo'] ?? null ) ? $snapshot['cargo'] : array();
		$query = new CarrierPickupPointQuery(
			(string) ( $snapshot['carrier_key'] ?? OzonDeliverySettings::CARRIER_KEY ),
			(int) ( $snapshot['location_id'] ?? 0 ),
			(string) ( $snapshot['country_code'] ?? 'RU' ),
			(string) ( $snapshot['fallback_address'] ?? '' ),
			is_numeric( $snapshot['latitude'] ?? null ) ? (float) $snapshot['latitude'] : null,
			is_numeric( $snapshot['longitude'] ?? null ) ? (float) $snapshot['longitude'] : null,
			new PickupCargoConstraints(
				(int) ( $cargo['weight_g'] ?? 0 ),
				(int) ( $cargo['volume_cm3'] ?? 0 ),
				(int) ( $cargo['max_dimension_cm'] ?? 0 ),
				(int) ( $cargo['max_place_weight_g'] ?? 0 ),
				max( 1, (int) ( $cargo['places_count'] ?? 1 ) ),
				$this->places_from_cargo( $cargo )
			),
			(string) ( $snapshot['purpose'] ?? CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP ),
			max( 1, (int) ( $snapshot['radius_km'] ?? 60 ) ),
			max( 1, (int) ( $snapshot['limit'] ?? 100 ) )
		);

		return array() === $query->validate() ? $query : null;
	}
	/** @param array<string,mixed> $row */
	private function point( array $row, CarrierPickupPointQuery $query ): ?PickupPoint {
		$point_id = (int) ( $row['point_id'] ?? 0 ); $name = trim( (string) ( $row['name'] ?? '' ) ); $address = trim( (string) ( $row['full_address'] ?? '' ) ); $type = trim( (string) ( $row['type'] ?? '' ) );
		$latitude = is_numeric( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null; $longitude = is_numeric( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null;
		if ( $point_id <= 0 || '' === $name || '' === $address || ! in_array( $type, array( 'pvz', 'postamat', 'unknown' ), true ) || 1 !== (int) ( $row['is_active'] ?? 0 ) || null === $latitude || null === $longitude || ! $this->cargo_passes( $row, $query ) ) { return null; }
		$title = 'postamat' === $type ? 'Постамат Ozon' : 'Пункт выдачи Ozon';
		return new PickupPoint( OzonDeliverySettings::CARRIER_KEY, (string) $point_id, $address, '', '', '', $latitude, $longitude, $type, trim( (string) ( $row['schedule'] ?? '' ) ), '', null, true, array( 'point_name' => $name, 'presentation_title' => $title, 'presentation_type' => $type, 'marker_type' => 'postamat' === $type ? 'postamat' : 'pickup', 'display_code' => '', 'requires_rate_refresh' => true ) );
	}
	/** @param array<string,mixed> $row */
	private function cargo_passes( array $row, CarrierPickupPointQuery $query ): bool {
		$places = $this->places_from_cargo( $query->cargo->to_array() );
		if ( array() === $places ) {
			$places[] = array(
				'weight_g' => $query->cargo->max_place_weight_g > 0 ? $query->cargo->max_place_weight_g : $query->cargo->weight_g,
				'length_cm' => $query->cargo->max_dimension_cm,
				'width_cm' => 0,
				'height_cm' => 0,
			);
		}
		foreach ( $places as $place ) {
			if ( ! $this->place_passes( $row, $place ) ) { return false; }
		}
		return true;
	}
	/** @param array<string,mixed> $row @param array{weight_g:int,length_cm:float,width_cm:float,height_cm:float} $place */
	private function place_passes( array $row, array $place ): bool {
		$weight = (int) $place['weight_g'];
		if ( $weight > 0 && ( ( null !== ( $row['min_weight_g'] ?? null ) && $weight < (int) $row['min_weight_g'] ) || ( null !== ( $row['max_weight_g'] ?? null ) && $weight > (int) $row['max_weight_g'] ) ) ) { return false; }
		$parcel = array_filter( array( (float) $place['length_cm'], (float) $place['width_cm'], (float) $place['height_cm'] ), static fn( float $value ): bool => $value > 0 );
		$limits = array_filter( array( $row['max_length_mm'] ?? null, $row['max_width_mm'] ?? null, $row['max_height_mm'] ?? null ), static fn( mixed $value ): bool => null !== $value && is_numeric( $value ) && (int) $value > 0 );
		if ( 3 === count( $parcel ) && 3 === count( $limits ) ) {
			$parcel_mm = array_map( static fn( float $value ): int => (int) ceil( $value * 10 ), array_values( $parcel ) );
			$limit_mm = array_map( 'intval', array_values( $limits ) );
			rsort( $parcel_mm, SORT_NUMERIC );
			rsort( $limit_mm, SORT_NUMERIC );
			foreach ( $parcel_mm as $index => $dimension ) {
				if ( $dimension > (int) $limit_mm[ $index ] ) { return false; }
			}
		}

		return true;
	}
	/** @param array<string,mixed> $cargo @return array<int,array{weight_g:int,length_cm:float,width_cm:float,height_cm:float}> */
	private function places_from_cargo( array $cargo ): array {
		$places = is_array( $cargo['places'] ?? null ) ? $cargo['places'] : array();
		$normalized = array();
		foreach ( $places as $place ) {
			if ( ! is_array( $place ) ) { continue; }
			$normalized[] = array(
				'weight_g' => max( 0, (int) ( $place['weight_g'] ?? 0 ) ),
				'length_cm' => max( 0.0, (float) ( $place['length_cm'] ?? $place['length'] ?? 0 ) ),
				'width_cm' => max( 0.0, (float) ( $place['width_cm'] ?? $place['width'] ?? 0 ) ),
				'height_cm' => max( 0.0, (float) ( $place['height_cm'] ?? $place['height'] ?? 0 ) ),
			);
		}

		return $normalized;
	}
	private function within_radius( PickupPoint $point, CarrierPickupPointQuery $query ): bool {
		if ( ! $point->has_coordinates() || null === $query->latitude || null === $query->longitude ) { return false; }
		$latitude = deg2rad( $point->latitude - $query->latitude ); $longitude = deg2rad( $point->longitude - $query->longitude ); $a = sin( $latitude / 2 ) ** 2 + cos( deg2rad( $query->latitude ) ) * cos( deg2rad( $point->latitude ) ) * sin( $longitude / 2 ) ** 2;
		return 6371.0088 * 2 * atan2( sqrt( $a ), sqrt( max( 0.0, 1 - $a ) ) ) <= $query->radius_km + 0.001;
	}
	private function distance_score( PickupPoint $point, CarrierPickupPointQuery $query ): float {
		if ( null === $query->latitude || null === $query->longitude || null === $point->latitude || null === $point->longitude ) { return INF; }
		return ( $point->latitude - $query->latitude ) ** 2 + ( $point->longitude - $query->longitude ) ** 2;
	}
}

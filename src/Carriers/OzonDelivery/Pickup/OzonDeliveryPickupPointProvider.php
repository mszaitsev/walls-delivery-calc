<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup;

use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderInterface;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryPickupPointProvider implements CarrierPickupPointProviderInterface {
	public function __construct( private OzonDeliveryPickupRepository $repository ) {}
	public function carrier_key(): string { return OzonDeliverySettings::CARRIER_KEY; }
	/** @return array<int,PickupPoint> */
	public function search( CarrierPickupPointQuery $query ): array {
		if ( array() !== $query->validate() || OzonDeliverySettings::CARRIER_KEY !== $query->normalized_carrier_key() || 'RU' !== $query->normalized_country_code() || null === $query->latitude || null === $query->longitude ) { return array(); }
		$latitude_delta = $query->radius_km / 111.32;
		$longitude_delta = $query->radius_km / max( 1.0, 111.32 * abs( cos( deg2rad( $query->latitude ) ) ) );
		$points = array();
		foreach ( $this->repository->find_active_in_radius( $query->latitude, $query->longitude, $latitude_delta, $longitude_delta, $query->limit ) as $row ) { $point = $this->point( $row, $query ); if ( $point instanceof PickupPoint && $this->within_radius( $point, $query ) ) { $points[] = $point; } }
		return $points;
	}
	public function resolve_selection( CarrierPickupPointSelectionQuery $query ): ?PickupPoint {
		if ( array() !== $query->validate() || OzonDeliverySettings::CARRIER_KEY !== $query->query->normalized_carrier_key() || ! ctype_digit( trim( $query->point_code ) ) ) { return null; }
		$row = $this->repository->find_active( (int) $query->point_code );
		$point = is_array( $row ) ? $this->point( $row, $query->query ) : null;
		return $point instanceof PickupPoint && $this->within_radius( $point, $query->query ) ? $point : null;
	}
	/** @param array<string,mixed> $row */
	private function point( array $row, CarrierPickupPointQuery $query ): ?PickupPoint {
		$point_id = (int) ( $row['point_id'] ?? 0 ); $name = trim( (string) ( $row['name'] ?? '' ) ); $address = trim( (string) ( $row['full_address'] ?? '' ) ); $type = trim( (string) ( $row['type'] ?? '' ) );
		$latitude = is_numeric( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null; $longitude = is_numeric( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null;
		if ( $point_id <= 0 || '' === $name || '' === $address || ! in_array( $type, array( 'pvz', 'postamat', 'unknown' ), true ) || 1 !== (int) ( $row['is_active'] ?? 0 ) || null === $latitude || null === $longitude || ! $this->cargo_passes( $row, $query ) ) { return null; }
		$title = 'postamat' === $type ? 'Постамат Ozon' : 'Пункт выдачи Ozon';
		return new PickupPoint( OzonDeliverySettings::CARRIER_KEY, (string) $point_id, $address, '', '', '', $latitude, $longitude, $type, trim( (string) ( $row['schedule'] ?? '' ) ), '', null, true, array( 'point_name' => $name, 'presentation_title' => $title, 'presentation_type' => $type, 'marker_type' => 'postamat' === $type ? 'postamat' : 'pickup', 'display_code' => '' ) );
	}
	/** @param array<string,mixed> $row */
	private function cargo_passes( array $row, CarrierPickupPointQuery $query ): bool {
		$weight = max( $query->cargo->weight_g, $query->cargo->max_place_weight_g );
		if ( $weight > 0 && ( ( null !== ( $row['min_weight_g'] ?? null ) && $weight < (int) $row['min_weight_g'] ) || ( null !== ( $row['max_weight_g'] ?? null ) && $weight > (int) $row['max_weight_g'] ) ) ) { return false; }
		$dimension_mm = $query->cargo->max_dimension_cm > 0 ? $query->cargo->max_dimension_cm * 10 : 0;
		if ( $dimension_mm > 0 ) { foreach ( array( 'max_width_mm', 'max_length_mm', 'max_height_mm' ) as $field ) { if ( null !== ( $row[ $field ] ?? null ) && $dimension_mm > (int) $row[ $field ] ) { return false; } } }
		return true;
	}
	private function within_radius( PickupPoint $point, CarrierPickupPointQuery $query ): bool {
		if ( ! $point->has_coordinates() || null === $query->latitude || null === $query->longitude ) { return false; }
		$latitude = deg2rad( $point->latitude - $query->latitude ); $longitude = deg2rad( $point->longitude - $query->longitude ); $a = sin( $latitude / 2 ) ** 2 + cos( deg2rad( $query->latitude ) ) * cos( deg2rad( $point->latitude ) ) * sin( $longitude / 2 ) ** 2;
		return 6371.0088 * 2 * atan2( sqrt( $a ), sqrt( max( 0.0, 1 - $a ) ) ) <= $query->radius_km;
	}
}

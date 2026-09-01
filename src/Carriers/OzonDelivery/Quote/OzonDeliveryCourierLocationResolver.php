<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Quote;

use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryCourierLocationResolver {
	public const ERROR_CODE = 'ozon_courier_location_coordinates_missing';
	public const PROXY_ERROR_CODE = 'ozon_courier_proxy_point_missing';
	private const FALLBACK_RADIUS_M = 1000.0;
	private const EARTH_RADIUS_M = 6371000.0;

	public function __construct( private LocationRepository $locations, private ?OzonDeliveryPickupRepository $pickup_points = null ) {
		$this->pickup_points ??= new OzonDeliveryPickupRepository();
	}

	public function resolve( QuoteRequest $request ): OzonDeliveryCourierLocation {
		$location_id = max( 0, (int) ( $request->customer_context['selected_location_id'] ?? 0 ) );
		if ( $location_id <= 0 ) {
			throw $this->missing( $location_id );
		}

		$exact = $this->exact_address_location( $request, $location_id );
		if ( null !== $exact ) {
			return $exact;
		}

		$location = $this->locations->find_by_id( $location_id );
		if ( ! $location instanceof Location || ! $location->active || 'RU' !== strtoupper( trim( $location->country_code ) ) ) {
			throw $this->missing( $location_id );
		}

		$latitude = $location->latitude;
		$longitude = $location->longitude;
		if (
			null === $latitude
			|| null === $longitude
			|| $latitude < -90.0
			|| $latitude > 90.0
			|| $longitude < -180.0
			|| $longitude > 180.0
			|| ( 0.0 === $latitude && 0.0 === $longitude )
		) {
			throw $this->missing( $location_id );
		}

		return $this->nearest_proxy_point( $location_id, $latitude, $longitude );
	}

	private function exact_address_location( QuoteRequest $request, int $location_id ): ?OzonDeliveryCourierLocation {
		$context = $request->customer_context;
		$status = strtolower( trim( (string) ( $context['dadata_status'] ?? '' ) ) );
		if ( ! in_array( $status, array( 'resolved', 'house_selected' ), true ) ) {
			return null;
		}
		$street = trim( (string) ( $context['dadata_street'] ?? $context['dadata_street_with_type'] ?? '' ) );
		$house = trim( (string) ( $context['dadata_house'] ?? $context['dadata_stead'] ?? '' ) );
		if ( '' === $street || '' === $house ) {
			return null;
		}
		$pair = $this->valid_coordinate_pair( $context['dadata_geo_lat'] ?? null, $context['dadata_geo_lon'] ?? $context['dadata_geo_lng'] ?? null );
		if ( null === $pair ) {
			return null;
		}
		$fingerprint = sha1( wp_json_encode( array(
			'location_id' => $location_id,
			'street' => $this->normalize_piece( $street ),
			'house' => $this->normalize_piece( $house ),
			'flat' => $this->normalize_piece( (string) ( $context['dadata_flat'] ?? '' ) ),
			'lat' => $this->coordinate( $pair['latitude'] ),
			'lng' => $this->coordinate( $pair['longitude'] ),
		) ) ?: '' );

		return new OzonDeliveryCourierLocation( 'dadata_address', $location_id, $pair['latitude'], $pair['longitude'], null, null, $fingerprint );
	}

	private function nearest_proxy_point( int $location_id, float $latitude, float $longitude ): OzonDeliveryCourierLocation {
		$latitude_delta = rad2deg( self::FALLBACK_RADIUS_M / self::EARTH_RADIUS_M );
		$cos = abs( cos( deg2rad( $latitude ) ) );
		$longitude_delta = rad2deg( self::FALLBACK_RADIUS_M / ( self::EARTH_RADIUS_M * max( $cos, 0.000001 ) ) );
		$rows = $this->pickup_points->find_active_in_area( $latitude, $longitude, $latitude_delta, $longitude_delta );
		$candidates = array();
		foreach ( $rows as $row ) {
			$point_id = max( 0, (int) ( $row['point_id'] ?? 0 ) );
			$pair = $this->valid_coordinate_pair( $row['latitude'] ?? null, $row['longitude'] ?? null );
			if ( $point_id <= 0 || null === $pair ) {
				continue;
			}
			$distance = $this->distance_m( $latitude, $longitude, $pair['latitude'], $pair['longitude'] );
			if ( $distance > self::FALLBACK_RADIUS_M ) {
				continue;
			}
			$candidates[] = array(
				'point_id' => $point_id,
				'latitude' => $pair['latitude'],
				'longitude' => $pair['longitude'],
				'distance_m' => $distance,
			);
		}
		if ( array() === $candidates ) {
			throw $this->proxy_missing( $location_id, $latitude, $longitude );
		}
		usort( $candidates, static function ( array $a, array $b ): int {
			$distance = $a['distance_m'] <=> $b['distance_m'];
			return 0 !== $distance ? $distance : ( $a['point_id'] <=> $b['point_id'] );
		} );
		$best = $candidates[0];

		return new OzonDeliveryCourierLocation( 'ozon_pickup_proxy', $location_id, $best['latitude'], $best['longitude'], $best['point_id'], (int) round( $best['distance_m'] ) );
	}

	/** @return array{latitude:float,longitude:float}|null */
	private function valid_coordinate_pair( mixed $latitude, mixed $longitude ): ?array {
		if ( ! is_numeric( $latitude ) || ! is_numeric( $longitude ) ) {
			return null;
		}
		$latitude = (float) $latitude;
		$longitude = (float) $longitude;
		if (
			! is_finite( $latitude )
			|| ! is_finite( $longitude )
			|| $latitude < -90.0
			|| $latitude > 90.0
			|| $longitude < -180.0
			|| $longitude > 180.0
			|| ( 0.0 === $latitude && 0.0 === $longitude )
		) {
			return null;
		}

		return array( 'latitude' => $latitude, 'longitude' => $longitude );
	}

	private function distance_m( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lng = deg2rad( $lng2 - $lng1 );
		$a = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lng / 2 ) ** 2;

		return 2 * self::EARTH_RADIUS_M * atan2( sqrt( $a ), sqrt( max( 0.0, 1 - $a ) ) );
	}

	private function coordinate( float $value ): string {
		$formatted = rtrim( rtrim( sprintf( '%.8F', $value ), '0' ), '.' );
		return '' === $formatted ? '0' : $formatted;
	}

	private function normalize_piece( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	private function missing( int $location_id ): OzonDeliveryQuoteException {
		return new OzonDeliveryQuoteException(
			self::ERROR_CODE,
			'order_checkout',
			0,
			'Для расчета Ozon курьером нужны координаты выбранного населенного пункта.',
			array_filter(
				array(
					'courier_coordinate_source' => 'location_repository',
					'courier_location_id' => $location_id,
				),
				static fn( mixed $value ): bool => 0 !== $value && '' !== $value
			)
		);
	}

	private function proxy_missing( int $location_id, float $latitude, float $longitude ): OzonDeliveryQuoteException {
		return new OzonDeliveryQuoteException(
			self::PROXY_ERROR_CODE,
			'order_checkout',
			0,
			'Для расчета Ozon курьером не найден ближайший активный ПВЗ Ozon.',
			array(
				'courier_coordinate_source' => 'ozon_pickup_proxy',
				'courier_location_id' => $location_id,
				'courier_search_latitude' => $latitude,
				'courier_search_longitude' => $longitude,
				'courier_proxy_radius_m' => (int) self::FALLBACK_RADIUS_M,
			)
		);
	}
}

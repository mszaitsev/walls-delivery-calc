<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Locations;

use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class LocationCoordinateEnricher {
	public function __construct(
		private LocationRepository $locations,
		private AddressSuggestionClientInterface $suggestions
	) {
	}

	/**
	 * @param array<string,mixed> $location
	 * @return array<string,mixed>
	 */
	public function enrich( array $location ): array {
		if ( 'RU' !== strtoupper( (string) ( $location['country_code'] ?? 'RU' ) ) ) {
			return $location;
		}

		$existing = $this->existing_location( $location );
		if ( $existing instanceof Location ) {
			$existing_data = $existing->to_array();
			foreach ( $existing_data as $key => $value ) {
				if ( ! array_key_exists( $key, $location ) || '' === (string) $location[ $key ] || null === $location[ $key ] ) {
					$location[ $key ] = $value;
				}
			}
			$existing_lat = $this->numeric_value( $existing_data['latitude'] ?? null );
			$existing_lng = $this->numeric_value( $existing_data['longitude'] ?? null );
			$current_lat = $this->numeric_value( $location['latitude'] ?? $location['lat'] ?? null );
			$current_lng = $this->numeric_value( $location['longitude'] ?? $location['lng'] ?? $location['lon'] ?? null );
			if ( $this->has_usable_coordinates( $existing_lat, $existing_lng ) && ! $this->has_usable_coordinates( $current_lat, $current_lng ) ) {
				$location['latitude'] = $existing_lat;
				$location['longitude'] = $existing_lng;
			}
		}

		$lat = $this->numeric_value( $location['latitude'] ?? $location['lat'] ?? null );
		$lng = $this->numeric_value( $location['longitude'] ?? $location['lng'] ?? $location['lon'] ?? null );
		if ( $this->has_usable_coordinates( $lat, $lng ) ) {
			$location['latitude'] = $lat;
			$location['longitude'] = $lng;
			$location['lat'] = $lat;
			$location['lng'] = $lng;
			return $location;
		}

		$query = $this->query( $location );
		if ( '' === $query ) {
			return $location;
		}

		$response = $this->suggestions->suggest( 'city', $query, array( 'country_code' => 'RU' ) );
		$suggestions = is_array( $response['suggestions'] ?? null ) ? $response['suggestions'] : array();
		$coordinates = $this->coordinates_from_suggestions( $suggestions );
		if ( null === $coordinates ) {
			return $location;
		}

		$location['latitude'] = $coordinates['lat'];
		$location['longitude'] = $coordinates['lng'];
		$location['lat'] = $coordinates['lat'];
		$location['lng'] = $coordinates['lng'];
		$location_id = (int) ( $location['id'] ?? 0 );
		if ( $location_id > 0 ) {
			$this->locations->update_coordinates( $location_id, $coordinates['lat'], $coordinates['lng'] );
		}

		return $location;
	}

	/**
	 * @param array<string,mixed> $location
	 */
	private function existing_location( array $location ): ?Location {
		$id = (int) ( $location['id'] ?? 0 );
		if ( $id > 0 ) {
			return $this->locations->find_by_id( $id );
		}

		$gar_object_id = (int) ( $location['gar_object_id'] ?? $location['gar_id'] ?? 0 );
		if ( $gar_object_id > 0 ) {
			return $this->locations->find_by_gar_object_id( $gar_object_id );
		}

		$fias_id = trim( (string) ( $location['fias_id'] ?? '' ) );
		if ( '' !== $fias_id ) {
			return $this->locations->find_by_fias_id( $fias_id );
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $location
	 */
	private function query( array $location ): string {
		return trim(
			implode(
				' ',
				array_filter(
					array(
						(string) ( $location['postal_code'] ?? '' ),
						(string) ( $location['display_name'] ?? '' ),
						(string) ( $location['city_name'] ?? '' ),
						(string) ( $location['settlement_name'] ?? $location['place_name'] ?? '' ),
						(string) ( $location['region_name'] ?? '' ),
					),
					static fn( string $part ): bool => '' !== trim( $part )
				)
			)
		);
	}

	/**
	 * @param array<int,mixed> $suggestions
	 * @return array{lat:float,lng:float}|null
	 */
	private function coordinates_from_suggestions( array $suggestions ): ?array {
		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}
			$data = is_array( $suggestion['data'] ?? null ) ? $suggestion['data'] : $suggestion;
			$lat = $this->numeric_value( $data['geo_lat'] ?? null );
			$lng = $this->numeric_value( $data['geo_lon'] ?? $data['geo_lng'] ?? null );
			if ( $this->has_usable_coordinates( $lat, $lng ) ) {
				return array( 'lat' => $lat, 'lng' => $lng );
			}
		}

		return null;
	}

	private function numeric_value( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}

	private function has_usable_coordinates( ?float $lat, ?float $lng ): bool {
		if ( null === $lat || null === $lng ) {
			return false;
		}
		if ( abs( $lat ) < 0.000001 && abs( $lng ) < 0.000001 ) {
			return false;
		}

		return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Search;

use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;

defined( 'ABSPATH' ) || exit;

final class PickupAddressSearchService {
	private const CACHE_TTL = 86400;

	public function __construct(
		private RussianPostPickupPointRepository $points,
		private AddressSuggestionClientInterface $address_client,
		private DaDataTokenPool $token_pool,
		private AddressSuggestionSettings $settings,
		private ?LocationRepository $locations = null
	) {
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<string,mixed>
	 */
	public function search( string $query, array $filters = array() ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return $this->failure( 'empty_query', true );
		}

		$location_id = max( 0, (int) ( $filters['location_id'] ?? 0 ) );
		$country_code = strtoupper( trim( (string) ( $filters['country_code'] ?? 'RU' ) ) );
		$types = is_array( $filters['point_types'] ?? null ) ? $filters['point_types'] : array();

		if ( preg_match( '/^\d{6}$/', $query ) ) {
			return $this->postcode_search( $query, $types );
		}

		$location = $this->location( $location_id );
		$cache_key = $this->cache_key( $query, $location_id, $country_code );
		$cached = $this->cache_get( $cache_key );
		if ( is_array( $cached ) ) {
			$cached['address_search_available'] = true;
			return $cached;
		}

		if ( ! $this->settings->enabled() || ! $this->settings->has_any_configured_token() || ! $this->token_pool->has_available_token() ) {
			return array( 'address_search_available' => false );
		}

		$response = $this->address_client->suggest( 'address', $this->scoped_query( $query, $location ), $this->dadata_context( $location, $country_code ) );
		if ( empty( $response['success'] ) ) {
			if ( 'dadata_daily_limit_exhausted' === (string) ( $response['error_code'] ?? '' ) || ! $this->token_pool->has_available_token() ) {
				return array( 'address_search_available' => false );
			}

			return $this->failure( (string) ( $response['error_code'] ?? 'dadata_api_failed' ), true );
		}

		$address = $this->address_from_suggestions( is_array( $response['suggestions'] ?? null ) ? $response['suggestions'] : array() );
		if ( null === $address ) {
			return $this->failure( 'address_not_found', true );
		}

		$points = $this->points->find_nearest_rows( (float) $address['lat'], (float) $address['lng'], array( 'point_types' => $types, 'limit' => 50 ) );
		$result = array(
			'search_type' => 'address',
			'address_search_available' => true,
			'address' => $address,
			'points' => array_map( fn( array $row ): array => $this->point_summary( $row ), $points ),
		);
		$this->cache_set( $cache_key, $result );

		return $result;
	}

	/**
	 * @param array<int,string> $types
	 * @return array<string,mixed>
	 */
	private function postcode_search( string $postcode, array $types ): array {
		$exact = $this->points->find_rows_by_postcode( $postcode, array( 'point_types' => $types, 'limit' => 50 ) );
		if ( array() !== $exact ) {
			$anchor = $this->average_point( $exact );
			$nearest = $this->points->find_nearest_rows( $anchor['lat'], $anchor['lng'], array( 'point_types' => $types, 'limit' => 50 ) );
			return array(
				'search_type' => 'postcode',
				'address_search_available' => $this->token_pool->has_available_token(),
				'address' => array(
					'value' => $postcode,
					'lat' => $anchor['lat'],
					'lng' => $anchor['lng'],
				),
				'points' => array_map( fn( array $row ): array => $this->point_summary( $row ), $nearest ),
			);
		}

		$location = $this->location_by_postcode( $postcode );
		if ( $location instanceof Location && null !== $location->latitude && null !== $location->longitude ) {
			$points = $this->points->find_nearest_rows( $location->latitude, $location->longitude, array( 'point_types' => $types, 'limit' => 50 ) );
			return array(
				'search_type' => 'postcode',
				'address_search_available' => $this->token_pool->has_available_token(),
				'address' => array(
					'value' => $location->resolved_display_name(),
					'lat' => $location->latitude,
					'lng' => $location->longitude,
				),
				'points' => array_map( fn( array $row ): array => $this->point_summary( $row ), $points ),
			);
		}

		return $this->failure( 'postcode_not_found', $this->token_pool->has_available_token(), 'postcode' );
	}

	/**
	 * @param array<int,array<string,mixed>> $suggestions
	 * @return array{value:string,lat:float,lng:float}|null
	 */
	private function address_from_suggestions( array $suggestions ): ?array {
		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}
			$data = is_array( $suggestion['data'] ?? null ) ? $suggestion['data'] : array();
			$lat = $data['geo_lat'] ?? null;
			$lng = $data['geo_lon'] ?? null;
			if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
				continue;
			}
			$value = trim( (string) ( $suggestion['unrestricted_value'] ?? $suggestion['value'] ?? '' ) );
			return array(
				'value' => '' !== $value ? $value : trim( (string) ( $suggestion['value'] ?? '' ) ),
				'lat' => (float) $lat,
				'lng' => (float) $lng,
			);
		}

		return null;
	}

	private function location( int $location_id ): ?Location {
		return $location_id > 0 && $this->locations instanceof LocationRepository ? $this->locations->find_by_id( $location_id ) : null;
	}

	private function location_by_postcode( string $postcode ): ?Location {
		return $this->locations instanceof LocationRepository ? $this->locations->find_first_by_postal_code( $postcode ) : null;
	}

	/**
	 * @return array<string,string>
	 */
	private function dadata_context( ?Location $location, string $country_code ): array {
		$context = array( 'country_code' => '' !== $country_code ? $country_code : 'RU' );
		if ( ! $location instanceof Location ) {
			return $context;
		}

		foreach ( array( 'fias_id', 'kladr_id', 'city_fias_id', 'city_kladr_id', 'display_name' ) as $key ) {
			$value = (string) $location->{$key};
			if ( '' !== trim( $value ) ) {
				$context[ 'location_' . $key ] = $value;
			}
		}

		return $context;
	}

	private function scoped_query( string $query, ?Location $location ): string {
		if ( ! $location instanceof Location ) {
			return $query;
		}
		$place = $location->resolved_place_name();
		return '' !== $place && ! str_contains( $this->normalize( $query ), $this->normalize( $place ) ) ? $place . ', ' . $query : $query;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{lat:float,lng:float}
	 */
	private function average_point( array $rows ): array {
		$lat = 0.0;
		$lng = 0.0;
		$count = 0;
		foreach ( $rows as $row ) {
			if ( is_numeric( $row['latitude'] ?? null ) && is_numeric( $row['longitude'] ?? null ) ) {
				$lat += (float) $row['latitude'];
				$lng += (float) $row['longitude'];
				++$count;
			}
		}

		return array( 'lat' => $count > 0 ? $lat / $count : 0.0, 'lng' => $count > 0 ? $lng / $count : 0.0 );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function point_summary( array $row ): array {
		return array(
			'id' => (int) ( $row['id'] ?? 0 ),
			'carrier' => 'russian_post',
			'point_type' => (string) ( $row['point_type'] ?? '' ),
			'title' => trim( (string) ( $row['point_type'] ?? '' ) . ' ' . (string) ( $row['postcode'] ?? '' ) ),
			'address' => (string) ( $row['address'] ?? '' ),
			'city' => (string) ( $row['city_name'] ?? '' ),
			'region' => (string) ( $row['region_name'] ?? '' ),
			'postal_code' => (string) ( $row['postcode'] ?? '' ),
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			'work_time' => (string) ( $row['work_time'] ?? '' ),
			'description' => (string) ( $row['description'] ?? '' ),
			'distance_meters' => isset( $row['distance_meters'] ) ? (int) $row['distance_meters'] : null,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function failure( string $code, bool $available, string $type = 'address' ): array {
		return array(
			'search_type' => $type,
			'address_search_available' => $available,
			'error_code' => $code,
			'address' => null,
			'points' => array(),
		);
	}

	private function cache_key( string $query, int $location_id, string $country_code ): string {
		return 'wdc_pickup_address_search_' . sha1( $this->normalize( $query ) . '|' . $location_id . '|' . $country_code );
	}

	private function cache_get( string $key ): mixed {
		return function_exists( 'get_transient' ) ? get_transient( $key ) : get_option( $key, false );
	}

	/**
	 * @param array<string,mixed> $value
	 */
	private function cache_set( string $key, array $value ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $value, self::CACHE_TTL );
			return;
		}
		update_option( $key, $value, false );
	}

	private function normalize( string $value ): string {
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $value ), 'UTF-8' ) : strtolower( trim( $value ) );
		return preg_replace( '/\s+/u', ' ', $value ) ?? $value;
	}
}

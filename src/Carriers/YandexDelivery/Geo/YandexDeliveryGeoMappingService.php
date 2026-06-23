<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoMappingService {
	private const CANDIDATE_STORAGE_PRIMARY_ONLY = 'primary_only';
	private const CANDIDATE_STORAGE_AMBIGUOUS_ONLY = 'ambiguous_only';
	private const CANDIDATE_STORAGE_ALL = 'all';
	private const DEFAULT_CANDIDATE_STORAGE_POLICY = self::CANDIDATE_STORAGE_AMBIGUOUS_ONLY;

	public function __construct(
		private LocationRepository $locations,
		private YandexDeliveryApiClient $api,
		private YandexDeliveryGeoMappingRepository $repository,
		private YandexDeliveryGeoMatchScorer $scorer,
		private YandexDeliveryGeoResolutionPolicy $resolution_policy,
		private string $candidate_storage_policy = self::DEFAULT_CANDIDATE_STORAGE_POLICY
	) {
	}

	/** @return array<string,mixed> */
	public function detect_for_location_id( int $location_id ): array {
		$location = $this->locations->find_by_id( $location_id );
		if ( ! $location instanceof Location ) {
			return array( 'success' => false, 'status' => YandexDeliveryGeoMappingStatus::NOT_FOUND, 'message' => 'Населенный пункт WDC не найден.', 'mappings' => array() );
		}
		$query = $this->build_search_query( $location );

		try {
			$response = $this->api->locationDetect( array( 'location' => $query ) );
			$mappings = $this->normalize_detect_response( $location, $query, $response );
			$this->repository->delete_location_mappings( $location_id );
			$saved = $this->repository->save_mappings( $mappings );
			return array(
				'success' => true,
				'status' => (string) ( $saved[0]['status'] ?? YandexDeliveryGeoMappingStatus::NOT_FOUND ),
				'query' => $query,
				'location' => $location->to_array(),
				'mappings' => $saved,
			);
		} catch ( YandexDeliveryApiException $exception ) {
			$mapping = $this->repository->save_mapping(
				array(
					'location_id' => $location_id,
					'yandex_geo_id' => null,
					'source_query' => $query,
					'status' => YandexDeliveryGeoMappingStatus::ERROR,
					'confidence' => 0,
					'raw_json' => $this->json( array( 'message' => $exception->getMessage(), 'details' => $exception->details() ) ),
				)
			);
			return array( 'success' => false, 'status' => YandexDeliveryGeoMappingStatus::ERROR, 'query' => $query, 'message' => $exception->getMessage(), 'mappings' => array( $mapping ) );
		}
	}
	/** @return array<string,mixed> */
	public function detect_for_runner( int $location_id ): array {
		$location = $this->locations->find_by_id( $location_id );
		if ( ! $location instanceof Location ) {
			return array( 'success' => false, 'status' => YandexDeliveryGeoMappingStatus::NOT_FOUND, 'message' => 'Населенный пункт WDC не найден.', 'mappings' => array(), 'technical_error' => false );
		}
		$query = $this->build_search_query( $location );
		$result = $this->detect_for_location_id( $location_id );
		$status = (string) ( $result['status'] ?? '' );
		if ( YandexDeliveryGeoMappingStatus::ERROR === $status ) {
			$marker = $this->repository->save_technical_error_marker( $location_id, $query, (string) ( $result['message'] ?? 'Yandex location/detect technical error.' ) );
			$result['mappings'] = array( $marker );
			$result['technical_error'] = true;
			$result['query'] = $query;
			return $result;
		}

		$result['technical_error'] = false;
		return $result;
	}

	/** @return array<int,Location> */
	public function search_locations( string $query, int $limit = 10 ): array {
		return $this->locations->search( $query, $limit, 'RU' );
	}

	public function build_search_query( Location $location ): string {
		$display_name = preg_replace( '/\s+/u', ' ', trim( $location->display_name ) );
		$display_name = is_string( $display_name ) ? $display_name : '';
		if ( '' !== $display_name ) {
			return $display_name;
		}

		$place = $location->resolved_place_name();
		$parts = array(
			'Россия',
			$location->region_name,
			$this->typed_part( $location->district_type, $location->district_name ),
			$place !== $location->city_name ? $this->typed_part( $location->city_type, $location->city_name ) : '',
			$this->typed_part( $location->resolved_place_type(), $place ),
		);
		$parts = array_values( array_unique( array_filter( array_map( 'trim', $parts ), static fn( string $part ): bool => '' !== $part ) ) );

		return implode( ', ', $parts );
	}

	/** @param array<string,mixed> $response @return array<int,array<string,mixed>> */
	public function normalize_detect_response( Location $location, string $query, array $response ): array {
		$body = is_array( $response['body'] ?? null ) ? $response['body'] : $response;
		$variants = $this->extract_variants( $body );
		if ( array() === $variants ) {
			return array( $this->empty_mapping( $location, $query, $body ) );
		}
		$rows = array();
		foreach ( $variants as $variant ) {
			$geo_id = $this->extract_geo_id( $variant );
			if ( $geo_id <= 0 ) {
				continue;
			}
			$locality = $this->extract_text( $variant, array( 'locality', 'city', 'name', 'title' ), array( 'address' => array( 'locality', 'city', 'name' ) ) );
			if ( '' === $locality && is_string( $variant['address'] ?? null ) ) {
				$locality = trim( $variant['address'] );
			}
			$region = $this->extract_text( $variant, array( 'region', 'region_name' ), array( 'address' => array( 'region', 'region_name' ) ) );
			$scoring = $this->scorer->score( $location, $variant, count( $variants ) );
			$rows[] = array(
				'location_id' => (int) $location->id,
				'yandex_geo_id' => $geo_id,
				'yandex_locality' => $locality,
				'yandex_region' => $region,
				'source_query' => $query,
				'scoring' => $scoring,
				'status' => YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES,
				'confidence' => (float) $scoring['confidence'],
				'is_primary' => 0,
				'raw_json' => $this->json( array( 'variant' => $variant, 'scoring' => $scoring ) ),
			);
		}
		if ( array() === $rows ) {
			return array( $this->empty_mapping( $location, $query, $body ) );
		}
		usort( $rows, static fn( array $left, array $right ): int => (float) $right['confidence'] <=> (float) $left['confidence'] );
		$resolution = $this->resolution_policy->resolve( $rows );
		if ( YandexDeliveryGeoMappingStatus::NOT_FOUND === $resolution['resolution'] ) {
			return array( $this->empty_mapping( $location, $query, array( 'response' => $body, 'resolution' => $resolution ) ) );
		}

		$confident_primary = YandexDeliveryGeoMappingStatus::MAPPED === $resolution['resolution'];
		foreach ( $rows as $index => $row ) {
			$is_primary = $confident_primary && null !== $resolution['primary_geo_id'] && (int) ( $row['yandex_geo_id'] ?? 0 ) === (int) $resolution['primary_geo_id'];
			$rows[ $index ]['status'] = $is_primary ? YandexDeliveryGeoMappingStatus::MAPPED : ( $confident_primary ? YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES : YandexDeliveryGeoMappingStatus::NEEDS_REVIEW );
			$rows[ $index ]['is_primary'] = $is_primary ? 1 : 0;
		}
		$rows = $this->apply_candidate_storage_policy( $rows, $confident_primary );
		foreach ( $rows as $index => $row ) {
			unset( $rows[ $index ]['scoring'] );
		}

		return $rows;
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	private function apply_candidate_storage_policy( array $rows, bool $confident_primary ): array {
		$policy = $this->candidate_storage_policy;
		if ( ! in_array( $policy, array( self::CANDIDATE_STORAGE_PRIMARY_ONLY, self::CANDIDATE_STORAGE_AMBIGUOUS_ONLY, self::CANDIDATE_STORAGE_ALL ), true ) ) {
			$policy = self::DEFAULT_CANDIDATE_STORAGE_POLICY;
		}
		if ( self::CANDIDATE_STORAGE_ALL === $policy ) {
			return $rows;
		}
		if ( $confident_primary ) {
			foreach ( $rows as $row ) {
				if ( ! empty( $row['is_primary'] ) ) {
					return array( $row );
				}
			}
		}
		if ( self::CANDIDATE_STORAGE_PRIMARY_ONLY === $policy ) {
			return array_slice( $rows, 0, 1 );
		}

		return $rows;
	}
	public function confidence( Location $location, string $locality, string $region, int $variant_count = 1 ): float {
		if ( $variant_count > 1 ) {
			return 40.00;
		}
		$location_place = $this->normalize_text( $location->resolved_place_name() );
		$location_region = $this->normalize_text( $location->region_name );
		$locality = $this->normalize_text( $locality );
		$region = $this->normalize_text( $region );
		if ( '' !== $location_place && $location_place === $locality && '' !== $location_region && $location_region === $region ) {
			return 100.00;
		}
		if ( '' !== $location_place && $location_place === $locality ) {
			return 70.00;
		}

		return 40.00;
	}

	/** @param array<string,mixed> $body @return array<int,array<string,mixed>> */
	private function extract_variants( array $body ): array {
		foreach ( array( 'locations', 'variants', 'results', 'items' ) as $key ) {
			if ( isset( $body[ $key ] ) && is_array( $body[ $key ] ) ) {
				return array_values( array_filter( $body[ $key ], 'is_array' ) );
			}
		}
		if ( isset( $body['result'] ) && is_array( $body['result'] ) ) {
			return array_is_list( $body['result'] ) ? array_values( array_filter( $body['result'], 'is_array' ) ) : array( $body['result'] );
		}
		if ( $this->extract_geo_id( $body ) > 0 ) {
			return array( $body );
		}

		return array();
	}

	/** @param array<string,mixed> $variant */
	private function extract_geo_id( array $variant ): int {
		foreach ( array( $variant['geo_id'] ?? null, $variant['geoId'] ?? null, $variant['id'] ?? null ) as $value ) {
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				return (int) $value;
			}
		}
		$address = is_array( $variant['address'] ?? null ) ? $variant['address'] : array();
		foreach ( array( $address['geo_id'] ?? null, $address['geoId'] ?? null ) as $value ) {
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				return (int) $value;
			}
		}

		return 0;
	}

	/** @param array<string,mixed> $variant @param array<int,string> $keys @param array<string,array<int,string>> $nested */
	private function extract_text( array $variant, array $keys, array $nested = array() ): string {
		foreach ( $keys as $key ) {
			if ( is_scalar( $variant[ $key ] ?? null ) && '' !== trim( (string) $variant[ $key ] ) ) {
				return trim( (string) $variant[ $key ] );
			}
		}
		foreach ( $nested as $parent => $child_keys ) {
			$value = is_array( $variant[ $parent ] ?? null ) ? $variant[ $parent ] : array();
			foreach ( $child_keys as $key ) {
				if ( is_scalar( $value[ $key ] ?? null ) && '' !== trim( (string) $value[ $key ] ) ) {
					return trim( (string) $value[ $key ] );
				}
			}
		}

		return '';
	}

	/** @param array<string,mixed> $body @return array<string,mixed> */
	private function empty_mapping( Location $location, string $query, array $body ): array {
		return array(
			'location_id' => (int) $location->id,
			'yandex_geo_id' => null,
			'source_query' => $query,
			'status' => YandexDeliveryGeoMappingStatus::NOT_FOUND,
			'confidence' => 0,
			'is_primary' => 0,
			'raw_json' => $this->json( $body ),
		);
	}

	private function typed_part( string $type, string $name ): string {
		$type = trim( $type );
		$name = trim( $name );
		return '' === $name ? '' : ( '' !== $type ? trim( $type . ' ' . $name ) : $name );
	}

	private function normalize_text( string $value ): string {
		$value = str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), $value );
		$value = preg_replace( '/\b(область|обл\.|край|республика|респ\.|город|г\.|село|с\.|деревня|д\.|поселок|посёлок|п\.)\b/iu', '', $value ) ?? $value;
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) );

		return is_string( $value ) ? $value : '';
	}

	/** @param array<string,mixed> $value */
	private function json( array $value ): string {
		return ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_UNICODE ) ) ?: '{}';
	}
}

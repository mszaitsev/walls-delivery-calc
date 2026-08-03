<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Geography;

use RuntimeException;
use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class PekLocationResolver {
	public function __construct(
		private LocationRepository $locations,
		private PekAddressBuilder $addresses,
		private PekLocationMappingRepository $mappings,
		private PekApiClient $api,
		private PekSettings $settings
	) {
	}

	/** @return array<string,mixed> */
	public function resolve( int $location_id ): array {
		$location = $this->locations->find_by_id( $location_id );
		if ( ! $location instanceof Location || ! $location->active ) {
			throw new RuntimeException( 'Canonical location is not available.' );
		}
		$country = strtoupper( trim( $location->country_code ) );
		if ( ! in_array( $country, PekSettings::PLANNED_COUNTRIES, true ) ) {
			return $this->unsupported_mapping( $location, 'unsupported_country', 'Страна не запланирована для PEK.' );
		}
		$fingerprint = $this->fingerprint( $location );
		$existing = $this->mappings->find_by_location_id( $location_id );
		if ( $this->mappings->is_fresh( $existing, $fingerprint, $this->settings->pek_location_mapping_ttl_days() ) ) {
			$existing['cache_hit'] = true;
			return $existing;
		}
		$method = $this->has_usable_location_coordinates( $location ) ? 'coordinates' : 'address';
		try {
			$response = 'coordinates' === $method
				? $this->api->find_zone_by_coordinates( (float) $location->latitude, (float) $location->longitude )
				: $this->api->find_zone_by_address( $this->addresses->build( $location ) );
		} catch ( PekApiException $exception ) {
			if (
				hash_equals( $fingerprint, (string) ( $existing['address_fingerprint'] ?? '' ) )
				&& in_array( (string) ( $existing['mapping_state'] ?? '' ), array( 'resolved', 'near' ), true )
			) {
				return array_merge(
					$existing,
					array(
						'cache_hit' => false,
						'stale_fallback' => true,
						'warning' => 'PEK API error; previous mapping for the same canonical location inputs preserved.',
					)
				);
			}
			throw $exception;
		}
		$mapping = $this->normalize_response( $location, $fingerprint, $method, $response );
		$this->mappings->upsert( $mapping );
		$mapping['cache_hit'] = false;

		return $mapping;
	}

	public function fingerprint( Location $location ): string {
		return hash( 'sha256', wp_json_encode( $this->addresses->fingerprint_inputs( $location ) ) ?: serialize( $this->addresses->fingerprint_inputs( $location ) ) );
	}

	/** @param array<string,mixed>|array<int,mixed> $response @return array<string,mixed> */
	private function normalize_response( Location $location, string $fingerprint, string $method, array $response ): array {
		$row = array_is_list( $response ) ? ( is_array( $response[0] ?? null ) ? $response[0] : array() ) : $response;
		if ( array() === $row ) {
			return $this->unsupported_mapping( $location, 'empty_response', 'PEK returned no zone.', $fingerprint, $method );
		}
		$geo = is_array( $row['GeoData'] ?? null ) ? $row['GeoData'] : array();
		$response_country = $this->response_country_code( $geo );
		$canonical_country = strtoupper( trim( $location->country_code ) );
		if ( $response_country['present'] && ! $response_country['valid'] ) {
			return $this->unsupported_mapping(
				$location,
				'invalid_response_country',
				'PEK returned malformed resolved address country.',
				$fingerprint,
				$method
			);
		}
		if ( $response_country['present'] && $response_country['code'] !== $canonical_country ) {
			return $this->unsupported_mapping(
				$location,
				'country_mismatch',
				'PEK resolved address country does not match canonical location country.',
				$fingerprint,
				$method,
				array(
					'expected_country' => $canonical_country,
					'actual_country' => $response_country['code'],
				)
			);
		}
		$zone_id = trim( (string) ( $row['zoneId'] ?? '' ) );
		$branch_id = trim( (string) ( $row['branchUID'] ?? '' ) );
		$has_zone_context = '' !== $zone_id && '' !== $branch_id;
		$precision_result = $this->precision( $row, $geo );
		$precision = $precision_result['value'];
		$state = 'unsupported';
		$diagnostic_code = '';
		if ( 'coordinates' === $method ) {
			if ( $has_zone_context ) {
				$state = 'resolved';
			} else {
				$diagnostic_code = 'incomplete_zone_context';
			}
		} elseif ( ! $precision_result['scalar'] ) {
			$diagnostic_code = 'unexpected_precision';
		} elseif ( '' === $precision ) {
			$diagnostic_code = 'bad_precision';
		} elseif ( 'bad' === $precision ) {
			$diagnostic_code = 'bad_precision';
		} elseif ( in_array( $precision, array( 'exact', 'near' ), true ) ) {
			if ( $has_zone_context ) {
				$state = 'exact' === $precision ? 'resolved' : 'near';
			} else {
				$diagnostic_code = 'incomplete_zone_context';
			}
		} else {
			$diagnostic_code = 'unexpected_precision';
		}
		if ( 'unsupported' === $state && '' === $diagnostic_code ) {
			$diagnostic_code = 'incomplete_zone_context';
		}
		$has_coordinates = $this->has_usable_location_coordinates( $location );
		return array(
			'location_id' => (int) $location->id,
			'country_code' => $canonical_country,
			'address_fingerprint' => $fingerprint,
			'resolution_method' => $method,
			'zone_id' => $zone_id,
			'zone_name' => trim( (string) ( $row['zoneName'] ?? '' ) ),
			'branch_id' => $branch_id,
			'branch_title' => trim( (string) ( $row['branchTitle'] ?? '' ) ),
			'main_warehouse_id' => trim( (string) ( $row['mainWarehouseId'] ?? $row['warehouseId'] ?? '' ) ),
			'normalized_address' => $this->normalized_address( $row, $location ),
			'latitude' => $has_coordinates ? (float) $location->latitude : null,
			'longitude' => $has_coordinates ? (float) $location->longitude : null,
			'precision' => $precision,
			'mapping_state' => $state,
			'safe_diagnostic_json' => $this->json( array_filter( array( 'precision' => $precision, 'state' => $state, 'code' => $diagnostic_code ) ) ),
			'checked_at' => $this->now(),
			'created_at' => $this->now(),
			'updated_at' => $this->now(),
		);
	}

	/** @param array<string,mixed> $row */
	private function normalized_address( array $row, Location $location ): string {
		$geo = is_array( $row['GeoData'] ?? null ) ? $row['GeoData'] : array();
		$address = is_array( $geo['Address'] ?? null ) ? $geo['Address'] : array();
		if ( '' !== trim( (string) ( $address['formatted'] ?? '' ) ) ) {
			return trim( (string) $address['formatted'] );
		}
		if ( is_array( $row['address'] ?? null ) ) {
			return implode( ', ', array_map( 'strval', $row['address'] ) );
		}

		return $this->addresses->build( $location );
	}

	/** @return array<string,mixed> */
	private function unsupported_mapping( Location $location, string $code, string $message, string $fingerprint = '', string $method = 'address', array $extra_diagnostic = array() ): array {
		return array(
			'location_id' => (int) $location->id,
			'country_code' => strtoupper( trim( $location->country_code ) ),
			'address_fingerprint' => '' !== $fingerprint ? $fingerprint : $this->fingerprint( $location ),
			'resolution_method' => $method,
			'zone_id' => '',
			'zone_name' => '',
			'branch_id' => '',
			'branch_title' => '',
			'main_warehouse_id' => '',
			'normalized_address' => $this->addresses->build( $location ),
			'latitude' => $this->has_usable_location_coordinates( $location ) ? (float) $location->latitude : null,
			'longitude' => $this->has_usable_location_coordinates( $location ) ? (float) $location->longitude : null,
			'precision' => 'bad',
			'mapping_state' => 'unsupported',
			'safe_diagnostic_json' => $this->json( array_merge( array( 'code' => $code, 'message' => $message ), $extra_diagnostic ) ),
			'checked_at' => $this->now(),
			'created_at' => $this->now(),
			'updated_at' => $this->now(),
			'cache_hit' => false,
		);
	}

	/** @param array<string,mixed> $geo @return array{present:bool,valid:bool,code:string} */
	private function response_country_code( array $geo ): array {
		$address = is_array( $geo['Address'] ?? null ) ? $geo['Address'] : array();
		if ( ! array_key_exists( 'country_code', $address ) ) {
			return array( 'present' => false, 'valid' => false, 'code' => '' );
		}
		if ( ! is_scalar( $address['country_code'] ) ) {
			return array( 'present' => true, 'valid' => false, 'code' => '' );
		}
		$country = strtoupper( trim( (string) $address['country_code'] ) );
		if ( '' === $country ) {
			return array( 'present' => false, 'valid' => false, 'code' => '' );
		}

		return preg_match( '/^[A-Z]{2}$/', $country )
			? array( 'present' => true, 'valid' => true, 'code' => $country )
			: array( 'present' => true, 'valid' => false, 'code' => '' );
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $geo @return array{scalar:bool,value:string} */
	private function precision( array $row, array $geo ): array {
		$value = $row['precision'] ?? $row['Precision'] ?? $geo['precision'] ?? '';
		if ( ! is_scalar( $value ) && null !== $value ) {
			return array( 'scalar' => false, 'value' => '' );
		}

		return array( 'scalar' => true, 'value' => strtolower( trim( (string) $value ) ) );
	}

	private function has_usable_location_coordinates( Location $location ): bool {
		if ( null === $location->latitude || null === $location->longitude || ! is_numeric( $location->latitude ) || ! is_numeric( $location->longitude ) ) {
			return false;
		}
		$latitude = (float) $location->latitude;
		$longitude = (float) $location->longitude;

		return $latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180;
	}

	private function json( array $value ): string {
		return function_exists( 'wp_json_encode' ) ? ( wp_json_encode( $value ) ?: '{}' ) : json_encode( $value, JSON_UNESCAPED_UNICODE );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}

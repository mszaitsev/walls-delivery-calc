<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

use WallsShop\WDC\Locations\Storage\LocationCarrierCodeRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class DpdCityResolver {
	public const CARRIER_KEY = DpdSettings::CARRIER_KEY;

	public function __construct(
		private LocationCarrierCodeRepository $carrier_codes,
		private DpdApiClient $api,
		private DpdDuplicateCityResolver $duplicate_resolver
	) {
	}

	/**
	 * @return array{city_id:string,source:string,confidence:string,saved:bool,multiple:bool,resolver_applied:bool,matched_by:array<int,string>,diagnostics:array<string,mixed>}|null
	 */
	public function resolve( Location $location ): ?array {
		$stored = $this->carrier_codes->find_best( self::CARRIER_KEY, $location );
		if ( null !== $stored && '' !== trim( $stored['external_code'] ) ) {
			return array(
				'city_id' => $stored['external_code'],
				'source' => 'mapping',
				'confidence' => 'stored',
				'saved' => false,
				'multiple' => false,
				'resolver_applied' => false,
				'matched_by' => array( 'stored_mapping' ),
				'diagnostics' => array( 'mapping_id' => $stored['id'], 'meta' => $stored['meta'] ),
			);
		}

		$attempts = array(
			array( 'source' => 'geography_api', 'payload' => $this->payload_from_location( $location, false ) ),
		);
		$fias = $this->location_fias( $location );
		if ( '' !== $fias ) {
			$attempts[] = array( 'source' => 'fias_lookup', 'payload' => $this->payload_from_location( $location, true ) );
		}

		foreach ( $attempts as $attempt ) {
			$response = $this->api->getPossibleExtraService( $attempt['payload'] );
			$result = $this->city_from_response( $response, $location );
			if ( null === $result['city_id'] ) {
				continue;
			}

			$mapping = $this->carrier_codes->save(
				$location,
				self::CARRIER_KEY,
				$result['city_id'],
				array(
					'source' => $attempt['source'],
					'matched_by' => $result['matched_by'],
					'multiple' => $result['multiple'],
					'resolver_applied' => $result['resolver_applied'],
				)
			);

			return array(
				'city_id' => $result['city_id'],
				'source' => $attempt['source'],
				'confidence' => $result['confidence'],
				'saved' => $mapping['id'] > 0,
				'multiple' => $result['multiple'],
				'resolver_applied' => $result['resolver_applied'],
				'matched_by' => $result['matched_by'],
				'diagnostics' => array(
					'mapping_id' => $mapping['id'],
					'api_method' => 'getPossibleExtraService',
					'response_keys' => array_keys( $response ),
				),
			);
		}

		return null;
	}

	/**
	 * @return array{city_id:string|null,confidence:string,multiple:bool,resolver_applied:bool,matched_by:array<int,string>}
	 */
	public function city_from_response( array $response, Location $location ): array {
		$flat = $this->flatten_return( $response );
		$duplicate = $this->duplicate_resolver->resolve_from_response( $flat, $location );
		if ( null !== $duplicate['city'] ) {
			$city_id = $this->extract_city_id( $duplicate['city'] );
			if ( null !== $city_id ) {
				return array(
					'city_id' => $city_id,
					'confidence' => 'duplicate_resolved',
					'multiple' => $duplicate['multiple'],
					'resolver_applied' => $duplicate['resolver_applied'],
					'matched_by' => $duplicate['matched_by'],
				);
			}
		}

		$city_id = $this->extract_city_id( $flat );
		if ( null !== $city_id ) {
			return array(
				'city_id' => $city_id,
				'confidence' => 'direct',
				'multiple' => false,
				'resolver_applied' => false,
				'matched_by' => array( 'direct_city_id' ),
			);
		}

		foreach ( $flat as $value ) {
			if ( is_array( $value ) && $this->is_list( $value ) ) {
				foreach ( $value as $item ) {
					if ( is_array( $item ) ) {
						$city_id = $this->extract_city_id( $item );
						if ( null !== $city_id ) {
							return array(
								'city_id' => $city_id,
								'confidence' => 'first_city',
								'multiple' => count( $value ) > 1,
								'resolver_applied' => false,
								'matched_by' => array( 'first_response_city' ),
							);
						}
					}
				}
			}
		}

		return array(
			'city_id' => null,
			'confidence' => 'none',
			'multiple' => $duplicate['multiple'],
			'resolver_applied' => $duplicate['resolver_applied'],
			'matched_by' => $duplicate['matched_by'],
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function payload_from_location( Location $location, bool $fias_only ): array {
		$fias = $this->location_fias( $location );
		if ( $fias_only ) {
			return '' !== $fias ? array( 'fiasGuid' => $fias ) : array();
		}

		$payload = array_filter(
			array(
				'countryCode' => $location->country_code,
				'regionCode' => $location->region_code,
				'cityName' => $location->resolved_place_name(),
				'postalCode' => $location->postal_code,
				'cityCode' => $location->kladr_id,
				'fiasGuid' => $fias,
				'garId' => $location->gar_object_id > 0 ? (string) $location->gar_object_id : $location->gar_id,
			),
			static fn( mixed $value ): bool => null !== $value && '' !== trim( (string) $value )
		);

		return $payload;
	}

	private function location_fias( Location $location ): string {
		return '' !== trim( $location->fias_id ) ? trim( $location->fias_id ) : trim( $location->city_fias_id );
	}

	/**
	 * @param array<string,mixed> $response
	 * @return array<string,mixed>
	 */
	private function flatten_return( array $response ): array {
		$current = $response;
		foreach ( array( 'body', 'return', 'result' ) as $key ) {
			if ( isset( $current[ $key ] ) && is_array( $current[ $key ] ) ) {
				$current = $current[ $key ];
			}
		}

		return $current;
	}

	/**
	 * @param array<string,mixed> $value
	 */
	private function extract_city_id( array $value ): ?string {
		foreach ( array( 'cityId', 'cityID', 'city_id', 'dpdCityId', 'dpd_city_id' ) as $key ) {
			if ( isset( $value[ $key ] ) && '' !== trim( (string) $value[ $key ] ) ) {
				return trim( (string) $value[ $key ] );
			}
		}

		return null;
	}

	/**
	 * @param array<mixed> $value
	 */
	private function is_list( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}

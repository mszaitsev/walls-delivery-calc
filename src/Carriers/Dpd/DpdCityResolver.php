<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

use WallsShop\WDC\Locations\Storage\LocationCarrierCodeRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class DpdCityResolver {
	public const CARRIER_KEY = DpdSettings::CARRIER_KEY;

	private string $last_error = '';

	public function __construct(
		private LocationCarrierCodeRepository $carrier_codes,
		private DpdApiClient $api,
		private DpdDuplicateCityResolver $duplicate_resolver
	) {
	}

	public function last_error(): string {
		return $this->last_error;
	}

	/**
	 * @return array{city_id:string,source:string,confidence:string,saved:bool,multiple:bool,resolver_applied:bool,matched_by:array<int,string>,diagnostics:array<string,mixed>}|null
	 */
	public function resolve( Location $location ): ?array {
		$this->last_error = '';
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

		try {
			$response = $this->api->getCitiesCashPay( $this->cities_cash_pay_payload( $location ) );
			$result = $this->city_from_cities_cash_pay_response( $response, $location );
			if ( null === $result['city_id'] ) {
				return null;
			}

			$mapping = $this->carrier_codes->save(
				$location,
				self::CARRIER_KEY,
				$result['city_id'],
				array(
					'source' => 'getCitiesCashPay',
					'matched_by' => $result['matched_by'],
					'multiple' => $result['multiple'],
					'resolver_applied' => $result['resolver_applied'],
				)
			);

			return array(
				'city_id' => $result['city_id'],
				'source' => 'getCitiesCashPay',
				'confidence' => $result['confidence'],
				'saved' => $mapping['id'] > 0,
				'multiple' => $result['multiple'],
				'resolver_applied' => $result['resolver_applied'],
				'matched_by' => $result['matched_by'],
				'diagnostics' => array(
					'mapping_id' => $mapping['id'],
					'api_method' => 'getCitiesCashPay',
					'response_keys' => array_keys( $response ),
				),
			);
		} catch ( \Throwable $throwable ) {
			$this->last_error = $throwable->getMessage();
			return null;
		}
	}

	/**
	 * @return array{city_id:string|null,confidence:string,multiple:bool,resolver_applied:bool,matched_by:array<int,string>}
	 */
	public function city_from_cities_cash_pay_response( array $response, Location $location ): array {
		$cities = $this->cities_from_response( $response );
		if ( array() === $cities ) {
			return array(
				'city_id' => null,
				'confidence' => 'none',
				'multiple' => false,
				'resolver_applied' => false,
				'matched_by' => array(),
			);
		}

		$selected = $this->duplicate_resolver->resolve( $cities, $location );
		if ( null === $selected ) {
			return array(
				'city_id' => null,
				'confidence' => 'none',
				'multiple' => count( $cities ) > 1,
				'resolver_applied' => count( $cities ) > 1,
				'matched_by' => array(),
			);
		}

		$city_id = $this->extract_city_id( $selected['city'] );
		if ( null === $city_id ) {
			return array(
				'city_id' => null,
				'confidence' => 'none',
				'multiple' => count( $cities ) > 1,
				'resolver_applied' => count( $cities ) > 1,
				'matched_by' => $selected['matched_by'],
			);
		}

		return array(
			'city_id' => $city_id,
			'confidence' => count( $cities ) > 1 ? 'city_list_resolved' : 'direct',
			'multiple' => count( $cities ) > 1,
			'resolver_applied' => count( $cities ) > 1,
			'matched_by' => $selected['matched_by'],
		);
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
	/**
	 * @return array<string,mixed>
	 */
	private function cities_cash_pay_payload( Location $location ): array {
		return array( 'countryCode' => '' !== trim( $location->country_code ) ? strtoupper( trim( $location->country_code ) ) : 'RU' );
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
	 * @param array<string,mixed> $response
	 * @return array<int,array<string,mixed>>
	 */
	private function cities_from_response( array $response ): array {
		$flat = $this->flatten_return( $response );
		foreach ( array( 'city', 'cities', 'items' ) as $key ) {
			if ( isset( $flat[ $key ] ) && is_array( $flat[ $key ] ) ) {
				if ( $this->is_list( $flat[ $key ] ) ) {
					return array_values( array_filter( $flat[ $key ], 'is_array' ) );
				}

				return array( $flat[ $key ] );
			}
		}
		if ( $this->extract_city_id( $flat ) !== null ) {
			return array( $flat );
		}
		if ( $this->is_list( $flat ) ) {
			return array_values( array_filter( $flat, 'is_array' ) );
		}

		return array();
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

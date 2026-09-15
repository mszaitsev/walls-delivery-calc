<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Pickup;

use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;

defined( 'ABSPATH' ) || exit;

final class DpdPickupPointService {
	public function __construct(
		private DpdPickupPointRepository $repository,
		private LocationDeliveryCodeRepository $delivery_codes
	) {
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_points_for_location_id( int $location_id ): array {
		$city_id = $this->delivery_codes->get_dpd_city_id( $location_id );
		if ( null === $city_id ) {
			return array();
		}

		return $this->get_points_by_city_id( (int) $city_id );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_points_by_city_id( int $city_id ): array {
		return $this->deduplicate_consumer_points( $this->repository->find_by_city_id( $city_id ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_parcel_shops_by_city_id( int $city_id, int $limit = 500 ): array {
		$rows = $this->repository->search( array( 'city_id' => $city_id, 'type' => 'parcel_shop', 'limit' => $limit ) );

		return array_values(
			array_filter(
				$rows,
				static fn( array $point ): bool => 'parcel_shop' === (string) ( $point['type'] ?? '' )
			)
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function search_parcel_shops( string $query, array $filters = array() ): array {
		$limit = max( 1, min( 500, (int) ( $filters['limit'] ?? 100 ) ) );
		$rows = $this->repository->search(
			array_filter(
				array(
					'type' => 'parcel_shop',
					'city_id' => isset( $filters['city_id'] ) && (int) $filters['city_id'] > 0 ? (int) $filters['city_id'] : null,
					'city_name' => (string) ( $filters['city_name'] ?? '' ),
					'limit' => $limit,
				),
				static fn( mixed $value ): bool => null !== $value && '' !== (string) $value
			)
		);
		$needle = $this->normalize_search_text( $query );
		if ( '' === $needle ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				fn( array $point ): bool => str_contains(
					$this->normalize_search_text(
						implode(
							' ',
							array(
								(string) ( $point['terminal_code'] ?? '' ),
								(string) ( $point['name'] ?? '' ),
								(string) ( $point['address'] ?? '' ),
								(string) ( $point['city_name'] ?? '' ),
							)
						)
					),
					$needle
				)
			)
		);
	}

	public function get_point_by_terminal_code( string $terminal_code ): ?array {
		$points = $this->deduplicate_consumer_points( $this->repository->search( array( 'terminal_code' => $terminal_code, 'limit' => 20 ) ) );

		return $points[0] ?? null;
	}

	/**
	 * @return array{point:?array<string,mixed>,selected_terminal_code:string,selected_type:string,selected_name:string,selected_address:string,fallback_duplicate_was_used:bool,ambiguous:bool,warnings:array<int,string>}
	 */
	public function find_runtime_parcel_shop_for_city_id( int $city_id ): array {
		return $this->parcel_shop_selection_for_city_id( $city_id );
	}

	public function find_runtime_parcel_shop_by_terminal_code( string $terminal_code, ?int $city_id = null ): ?array {
		$terminal_code = trim( $terminal_code );
		if ( '' === $terminal_code ) {
			return null;
		}
		$filters = array( 'terminal_code' => $terminal_code, 'limit' => 50 );
		if ( null !== $city_id && $city_id > 0 ) {
			$filters['city_id'] = $city_id;
		}
		foreach ( $this->repository->search( $filters ) as $point ) {
			if ( 'parcel_shop' === (string) ( $point['type'] ?? '' ) && $terminal_code === trim( (string) ( $point['terminal_code'] ?? '' ) ) ) {
				return $point;
			}
		}

		return null;
	}

	/**
	 * @return array{point:?array<string,mixed>,selected_terminal_code:string,selected_type:string,selected_name:string,selected_address:string,fallback_duplicate_was_used:bool,ambiguous:bool,warnings:array<int,string>}
	 */
	public function find_runtime_parcel_shop_for_location_id( int $location_id ): array {
		$city_id = $this->delivery_codes->get_dpd_city_id( $location_id );
		if ( null === $city_id ) {
			return $this->terminal_selection(
				null,
				false,
				array( 'DPD cityId was not found for location_id. DPD terminalCode pricing is unavailable.' ),
				false
			);
		}

		return $this->find_runtime_parcel_shop_for_city_id( (int) $city_id );
	}

	/**
	 * @return array{point:?array<string,mixed>,selected_terminal_code:string,selected_type:string,selected_name:string,selected_address:string,fallback_duplicate_was_used:bool,ambiguous:bool,warnings:array<int,string>}
	 */
	private function parcel_shop_selection_for_city_id( int $city_id ): array {
		$rows = $this->repository->search( array( 'city_id' => $city_id, 'limit' => 500 ) );
		$terminal_duplicates = array();
		$parcel_shops = array();

		foreach ( $rows as $row ) {
			$code = trim( (string) ( $row['terminal_code'] ?? '' ) );
			if ( '' === $code ) {
				continue;
			}
			$type = (string) ( $row['type'] ?? '' );
			if ( 'terminal_self_delivery' === $type ) {
				$terminal_duplicates[ $code ] = true;
				continue;
			}
			if ( 'parcel_shop' === $type ) {
				$parcel_shops[] = $row;
			}
		}

		$unambiguous = array_values(
			array_filter(
				$parcel_shops,
				static fn( array $point ): bool => ! isset( $terminal_duplicates[ trim( (string) ( $point['terminal_code'] ?? '' ) ) ] )
			)
		);
		if ( array() !== $unambiguous ) {
			return $this->terminal_selection( $unambiguous[0], false, array(), false );
		}
		if ( count( $parcel_shops ) > 0 ) {
			return $this->terminal_selection(
				$parcel_shops[0],
				true,
				array( 'Selected parcel_shop fallback even though terminal_self_delivery duplicate exists for terminalCode.' ),
				false
			);
		}

		return $this->terminal_selection(
			null,
			false,
			array( 'No parcel_shop terminalCode found for cityId. DPD terminalCode pricing is unavailable.' ),
			false
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $points
	 * @return array<int,array<string,mixed>>
	 */
	private function deduplicate_consumer_points( array $points ): array {
		$by_terminal_code = array();
		foreach ( $points as $point ) {
			$terminal_code = trim( (string) ( $point['terminal_code'] ?? '' ) );
			if ( '' === $terminal_code ) {
				continue;
			}
			if ( ! isset( $by_terminal_code[ $terminal_code ] ) || $this->type_priority( $point ) < $this->type_priority( $by_terminal_code[ $terminal_code ] ) ) {
				$by_terminal_code[ $terminal_code ] = $point;
			}
		}

		return array_values( $by_terminal_code );
	}

	/**
	 * @param array<string,mixed> $point
	 */
	private function type_priority( array $point ): int {
		return 'parcel_shop' === (string) ( $point['type'] ?? '' ) ? 1 : 2;
	}

	private function normalize_search_text( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? '' );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	/**
	 * @param array<string,mixed>|null $point
	 * @param array<int,string> $warnings
	 * @return array{point:?array<string,mixed>,selected_terminal_code:string,selected_type:string,selected_name:string,selected_address:string,fallback_duplicate_was_used:bool,ambiguous:bool,warnings:array<int,string>}
	 */
	private function terminal_selection( ?array $point, bool $fallback_duplicate_was_used, array $warnings, bool $ambiguous ): array {
		return array(
			'point' => $point,
			'selected_terminal_code' => null === $point ? '' : (string) ( $point['terminal_code'] ?? '' ),
			'selected_type' => null === $point ? '' : (string) ( $point['type'] ?? '' ),
			'selected_name' => null === $point ? '' : (string) ( $point['name'] ?? '' ),
			'selected_address' => null === $point ? '' : (string) ( $point['address'] ?? '' ),
			'fallback_duplicate_was_used' => $fallback_duplicate_was_used,
			'ambiguous' => $ambiguous,
			'warnings' => $warnings,
		);
	}
}

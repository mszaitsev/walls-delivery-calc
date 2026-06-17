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

	public function get_point_by_terminal_code( string $terminal_code ): ?array {
		$points = $this->deduplicate_consumer_points( $this->repository->search( array( 'terminal_code' => $terminal_code, 'limit' => 20 ) ) );

		return $points[0] ?? null;
	}

	/**
	 * @return array{point:?array<string,mixed>,selected_terminal_code:string,selected_type:string,selected_name:string,selected_address:string,fallback_duplicate_was_used:bool,ambiguous:bool,warnings:array<int,string>}
	 */
	public function find_diagnostic_parcel_shop_for_city_id( int $city_id ): array {
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
			return $this->diagnostic_selection( $unambiguous[0], false, array(), false );
		}
		if ( 1 === count( $parcel_shops ) ) {
			return $this->diagnostic_selection(
				$parcel_shops[0],
				true,
				array( 'Selected only available parcel_shop even though terminal_self_delivery duplicate exists for terminalCode.' ),
				false
			);
		}
		if ( count( $parcel_shops ) > 1 ) {
			return $this->diagnostic_selection(
				null,
				false,
				array( 'No unambiguous parcel_shop terminalCode found for cityId. Choose terminalCode manually.' ),
				true
			);
		}

		return $this->diagnostic_selection(
			null,
			false,
			array( 'No parcel_shop terminalCode found for cityId. Choose terminalCode manually.' ),
			false
		);
	}

	/**
	 * @return array{point:?array<string,mixed>,selected_terminal_code:string,selected_type:string,selected_name:string,selected_address:string,fallback_duplicate_was_used:bool,ambiguous:bool,warnings:array<int,string>}
	 */
	public function find_diagnostic_parcel_shop_for_location_id( int $location_id ): array {
		$city_id = $this->delivery_codes->get_dpd_city_id( $location_id );
		if ( null === $city_id ) {
			return $this->diagnostic_selection(
				null,
				false,
				array( 'DPD cityId was not found for receiver location_id. Choose delivery cityId or terminalCode manually.' ),
				false
			);
		}

		return $this->find_diagnostic_parcel_shop_for_city_id( (int) $city_id );
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

	/**
	 * @param array<string,mixed>|null $point
	 * @param array<int,string> $warnings
	 * @return array{point:?array<string,mixed>,selected_terminal_code:string,selected_type:string,selected_name:string,selected_address:string,fallback_duplicate_was_used:bool,ambiguous:bool,warnings:array<int,string>}
	 */
	private function diagnostic_selection( ?array $point, bool $fallback_duplicate_was_used, array $warnings, bool $ambiguous ): array {
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

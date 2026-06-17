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
}

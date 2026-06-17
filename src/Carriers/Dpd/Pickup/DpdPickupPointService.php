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
		return $this->repository->find_by_city_id( $city_id );
	}

	public function get_point_by_terminal_code( string $terminal_code ): ?array {
		return $this->repository->find_by_terminal_code( $terminal_code );
	}
}

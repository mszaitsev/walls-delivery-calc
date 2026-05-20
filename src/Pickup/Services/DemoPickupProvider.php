<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Services;

use WallsShop\WDC\Carriers\Runtime\DemoCarrier;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Pickup\PickupPoint;

defined( 'ABSPATH' ) || exit;

final class DemoPickupProvider implements PickupProviderInterface {
	public function __construct(
		private string $dataset_path = ''
	) {
	}

	public function supports_carrier( string $carrierKey ): bool {
		return DemoCarrier::KEY === trim( $carrierKey );
	}

	/**
	 * @return array<int,PickupPoint>
	 */
	public function get_points( string $carrierKey, Address $destination ): array {
		if ( ! $this->supports_carrier( $carrierKey ) || 'RU' !== strtoupper( trim( $destination->country_code ) ) ) {
			return array();
		}

		$city = trim( $destination->city ?: $destination->settlement );

		return array_values(
			array_filter(
				$this->load_points(),
				static fn ( PickupPoint $point ): bool => '' === $city || 0 === strcasecmp( $point->city, $city )
			)
		);
	}

	/**
	 * @return array<int,PickupPoint>
	 */
	public function load_points(): array {
		$path = '' !== $this->dataset_path ? $this->dataset_path : dirname( __DIR__, 3 ) . '/database/demo/pickup-points-demo.json';
		if ( ! is_readable( $path ) ) {
			return array();
		}

		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$points = array();
		foreach ( $data as $item ) {
			if ( is_array( $item ) ) {
				$item['raw_reference'] = array_merge( $item, array( 'country_code' => (string) ( $item['country_code'] ?? 'RU' ) ) );
				$points[] = PickupPoint::from_array( $item );
			}
		}

		return $points;
	}
}

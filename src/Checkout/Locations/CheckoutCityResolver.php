<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Locations;

use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class CheckoutCityResolver {
	public function __construct(
		private LocationRepository $repository,
		private CheckoutLocationSearch $location_search
	) {
	}

	public function resolve_city( string $input ): ?Location {
		$input = trim( $input );
		if ( '' === $input ) {
			return null;
		}

		$location = $this->location_search->best_match( $input );
		if ( $location instanceof Location ) {
			return $location;
		}

		$locations = $this->repository->search( $input, 1 );

		return $locations[0] ?? null;
	}

	public function resolve_postcode( string $input ): ?string {
		$location = $this->resolve_city( $input );
		if ( ! $location instanceof Location ) {
			return null;
		}

		$postcode = trim( $location->postal_code );

		return '' !== $postcode ? $postcode : null;
	}
}

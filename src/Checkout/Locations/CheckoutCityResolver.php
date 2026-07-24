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

	public function resolve_city( string $input, string $country_code = '' ): ?Location {
		$input = trim( $input );
		$country_code = $this->normalize_country_code( $country_code );
		if ( '' === $input ) {
			return null;
		}

		$location = $this->location_search->best_match( $input, $country_code );
		if ( $location instanceof Location ) {
			return $location;
		}

		$locations = $this->repository->search( $input, 1, $country_code );

		return $locations[0] ?? null;
	}

	public function resolve_postcode( string $input, string $country_code = '' ): ?string {
		$location = $this->resolve_city( $input, $country_code );
		if ( ! $location instanceof Location ) {
			return null;
		}

		$postcode = trim( $location->postal_code );

		return '' !== $postcode ? $postcode : null;
	}

	private function normalize_country_code( string $country_code ): string {
		$country_code = strtoupper( trim( $country_code ) );

		return preg_match( '/^[A-Z]{2}$/', $country_code ) ? $country_code : '';
	}
}

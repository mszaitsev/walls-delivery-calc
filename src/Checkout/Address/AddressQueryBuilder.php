<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Address;

defined( 'ABSPATH' ) || exit;

final class AddressQueryBuilder {
	/**
	 * @param array<string,mixed> $context
	 */
	public function build( array $context ): string {
		$country = $this->country_name( (string) ( $context['country_code'] ?? $context['country'] ?? '' ) );
		$region  = (string) ( $context['region_name'] ?? $context['selected_region_name'] ?? '' );
		$city    = (string) ( $context['city'] ?? $context['city_name'] ?? '' );
		$street  = (string) ( $context['address_1'] ?? '' );
		$house   = (string) ( $context['address_2'] ?? '' );

		return implode(
			', ',
			array_values(
				array_filter(
					array_map( static fn ( string $value ): string => trim( $value ), array( $country, $region, $city, $street, $house ) ),
					static fn ( string $value ): bool => '' !== $value
				)
			)
		);
	}

	private function country_name( string $country_code ): string {
		return match ( strtoupper( trim( $country_code ) ) ) {
			'RU', 'RUS' => 'Россия',
			default => trim( $country_code ),
		};
	}
}

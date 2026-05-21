<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Address;

defined( 'ABSPATH' ) || exit;

final class AddressQueryBuilder {
	/**
	 * @param array<string,mixed> $context
	 */
	public function build( array $context ): string {
		return (string) $this->debug( $context )['query'];
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,string>
	 */
	public function debug( array $context ): array {
		$country = $this->country_name( (string) ( $context['country_code'] ?? $context['country'] ?? '' ) );
		$region  = (string) ( $context['region_name'] ?? $context['selected_region_name'] ?? '' );
		$city    = (string) ( $context['city'] ?? $context['city_name'] ?? '' );
		$street  = (string) ( $context['address_1'] ?? '' );
		$house   = (string) ( $context['address_2'] ?? '' );

		$query = implode(
			', ',
			array_values(
				array_filter(
					array_map( static fn ( string $value ): string => trim( $value ), array( $country, $region, $city, $street, $house ) ),
					static fn ( string $value ): bool => '' !== $value
				)
			)
		);

		return array(
			'country'   => trim( $country ),
			'region'    => trim( $region ),
			'city'      => trim( $city ),
			'address_1' => trim( $street ),
			'address_2' => trim( $house ),
			'query'     => $query,
		);
	}

	private function country_name( string $country_code ): string {
		return match ( strtoupper( trim( $country_code ) ) ) {
			'RU', 'RUS' => 'Россия',
			default => trim( $country_code ),
		};
	}
}

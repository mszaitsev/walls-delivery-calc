<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Geography;

use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class PekAddressBuilder {
	private const COUNTRY_NAMES = array(
		'RU' => 'Россия',
		'AM' => 'Армения',
		'BY' => 'Беларусь',
		'KG' => 'Кыргызстан',
		'KZ' => 'Казахстан',
	);

	public function build( Location $location ): string {
		$country = self::COUNTRY_NAMES[ strtoupper( trim( $location->country_code ) ) ] ?? '';
		$parts = array(
			$country,
			$this->typed( $location->region_type, $location->region_name, true ),
			$this->typed( $location->district_type, $location->district_name, false ),
			$this->typed( $location->city_type, $location->city_name, false ),
			$this->typed( $location->resolved_place_type(), $location->resolved_place_name(), false ),
		);
		$normalized = array();
		$seen = array();
		foreach ( $parts as $part ) {
			$part = $this->normalize_whitespace( $part );
			if ( '' === $part ) {
				continue;
			}
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $part, 'UTF-8' ) : strtolower( $part );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$normalized[] = $part;
		}

		return implode( ', ', $normalized );
	}

	/** @return array<string,mixed> */
	public function fingerprint_inputs( Location $location ): array {
		return array(
			'location_id' => $location->id,
			'country_code' => strtoupper( trim( $location->country_code ) ),
			'region_name' => $location->region_name,
			'region_type' => $location->region_type,
			'district_name' => $location->district_name,
			'district_type' => $location->district_type,
			'city_name' => $location->city_name,
			'city_type' => $location->city_type,
			'place_name' => $location->resolved_place_name(),
			'place_type' => $location->resolved_place_type(),
			'latitude' => $location->latitude,
			'longitude' => $location->longitude,
			'address' => $this->build( $location ),
		);
	}

	private function typed( string $type, string $name, bool $type_after ): string {
		$name = $this->normalize_whitespace( $name );
		$type = $this->normalize_whitespace( $type );
		if ( '' === $name ) {
			return '';
		}
		if ( '' === $type ) {
			return $name;
		}
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $type, 'UTF-8' ) : strtolower( $type );
		$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
		if ( str_contains( $haystack, $needle ) ) {
			return $name;
		}

		return $type_after ? trim( $name . ' ' . $type ) : trim( $type . ' ' . $name );
	}

	private function normalize_whitespace( string $value ): string {
		return trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
	}
}

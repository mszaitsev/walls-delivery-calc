<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Services;

use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class LocationDisplayNameFormatter {
	/**
	 * @param array<string,array<string,array{display?:string,position?:string}>> $rules
	 */
	public function __construct( private array $rules = array() ) {
	}

	/**
	 * @param array<string,array<string,array{display?:string,position?:string}>> $rules
	 */
	public static function from_rules( array $rules ): self {
		return new self( $rules );
	}

	public function format_location( Location $location ): string {
		$parts = array(
			$this->format_part( 'region', $location->region_type, $location->region_name ),
			$this->format_district( $location->district_type, $location->district_name ),
			$this->format_part( 'city', $location->city_type, $location->city_name ),
			$this->format_part( 'place', $location->resolved_place_type(), $location->resolved_place_name() ),
		);

		$parts = array_values( array_unique( array_filter( array_map( 'trim', $parts ) ) ) );
		return implode( ', ', $parts );
	}

	public function format_part( string $scope, string $type, string $name ): string {
		$name = trim( $name );
		$type = trim( $type );
		if ( '' === $name ) {
			return '';
		}

		$rule = $this->rules[ $scope ][ $type ] ?? array();
		$display = trim( (string) ( $rule['display'] ?? $type ) );
		$position = (string) ( $rule['position'] ?? $this->default_position( $scope ) );
		if ( 'hidden' === $position || '' === $display ) {
			return $name;
		}

		return 'after' === $position ? trim( $name . ' ' . $display ) : trim( $display . ' ' . $name );
	}

	public function format_region_group_header( Location|string $location_or_name, string $region_type = '' ): string {
		if ( $location_or_name instanceof Location ) {
			$region_name = $location_or_name->region_name;
			$region_type = $location_or_name->region_type;
		} else {
			$region_name = $location_or_name;
		}

		$region_name = trim( $region_name );
		$region_type = trim( $region_type );
		if ( '' === $region_name || '' === $region_type ) {
			return $region_name;
		}

		$rule = $this->rules['region'][ $region_type ] ?? array();
		if ( 'hidden' === (string) ( $rule['position'] ?? '' ) ) {
			return $region_name;
		}

		$display = trim( (string) ( $rule['display'] ?? '' ) );
		if ( '' === $display ) {
			$display = $region_type;
		}

		$normalized_name = Location::normalize_search_text( $region_name );
		$normalized_display = Location::normalize_search_text( $display );
		$normalized_type = Location::normalize_search_text( $region_type );
		if ( '' !== $normalized_display && str_ends_with( $normalized_name, $normalized_display ) ) {
			return $region_name;
		}
		if ( '' !== $normalized_type && str_ends_with( $normalized_name, $normalized_type ) ) {
			return $region_name;
		}

		return trim( $region_name . ' ' . $display );
	}

	public function format_checkout_region_header( Location $location ): string {
		return $this->format_region_group_header( $location );
	}

	public function format_checkout_location_option( Location $location ): string {
		$main = $this->format_part( 'place', $location->resolved_place_type(), $location->resolved_place_name() );
		if ( '' === $main ) {
			$main = $this->format_part( 'city', $location->city_type, $location->city_name );
		}

		$context = array();
		$district = $this->format_district( $location->district_type, $location->district_name );
		$city = $this->format_part( 'city', $location->city_type, $location->city_name );
		$region = $this->format_part( 'region', $location->region_type, $location->region_name );

		foreach ( array( $district, $city, $region ) as $part ) {
			$part = trim( $part );
			if ( '' !== $part && $part !== $main && ! in_array( $part, $context, true ) ) {
				$context[] = $part;
			}
		}

		return trim( $main . ( array() !== $context ? ' - ' . implode( ', ', $context ) : '' ) );
	}

	public function format_checkout_state_value( Location $location ): string {
		return $this->format_part( 'region', $location->region_type, $location->region_name );
	}

	public function format_checkout_city_value( Location $location ): string {
		$place_name = '' !== trim( $location->place_name ) ? $location->place_name : $location->settlement_name;
		$place_type = '' !== trim( $location->place_type ) ? $location->place_type : $location->settlement_type;
		$place      = $this->format_part( 'place', $place_type, $place_name );

		if ( '' !== trim( $place ) ) {
			return $place;
		}

		return $this->format_part( 'city', $location->city_type, $location->city_name );
	}

	/**
	 * @return array<int,string>
	 */
	public function display_variants( string $scope, string $type ): array {
		$type = trim( $type );
		$rule = $this->rules[ $scope ][ $type ] ?? array();
		$display = trim( (string) ( $rule['display'] ?? '' ) );

		return array_values( array_unique( array_filter( array( $type, $display ) ) ) );
	}

	private function format_district( string $type, string $name ): string {
		$name = trim( $name );
		$type = trim( $type );
		if ( '' === $name ) {
			return '';
		}

		return trim( $name . ( '' !== $type ? ' ' . $type : '' ) );
	}

	private function default_position( string $scope ): string {
		return 'region' === $scope ? 'after' : 'before';
	}
}

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

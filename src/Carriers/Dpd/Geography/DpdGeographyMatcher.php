<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyMatcher {
	public function __construct(
		private LocationRepository $locations
	) {
	}

	/**
	 * @param array<string,string> $row
	 * @return array{status:string,method:string,location:?Location}
	 */
	public function match( array $row ): array {
		$fias = $this->normalize_guid( (string) ( $row['fias'] ?? '' ) );
		if ( '' !== $fias ) {
			$location = $this->locations->find_by_fias_or_city_fias_id( $fias );
			if ( $location instanceof Location ) {
				return $this->matched( 'fias', $location );
			}
		}

		foreach ( $this->kladr_variants( (string) ( $row['kladr'] ?? '' ) ) as $kladr ) {
			$location = $this->locations->find_unique_by_kladr_variant( $kladr );
			if ( $location instanceof Location ) {
				return $this->matched( 'kladr', $location );
			}
		}

		$candidates = $this->name_candidates( $row );
		if ( 1 === count( $candidates ) ) {
			return $this->matched( 'name', $candidates[0] );
		}
		if ( count( $candidates ) > 1 ) {
			return array( 'status' => 'ambiguous', 'method' => 'name', 'location' => null );
		}

		return array( 'status' => 'unmatched', 'method' => '', 'location' => null );
	}

	/**
	 * @return array{status:string,method:string,location:Location}
	 */
	private function matched( string $method, Location $location ): array {
		return array( 'status' => 'matched', 'method' => $method, 'location' => $location );
	}

	/**
	 * @param array<string,string> $row
	 * @return array<int,Location>
	 */
	private function name_candidates( array $row ): array {
		$name = trim( (string) ( $row['settlement'] ?? '' ) );
		if ( '' === $name ) {
			return array();
		}

		return $this->locations->find_conservative_name_matches(
			$name,
			(string) ( $row['region'] ?? '' ),
			(string) ( $row['district'] ?? '' ),
			$this->normalize_type( (string) ( $row['settlement_type'] ?? '' ) )
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function kladr_variants( string $value ): array {
		$digits = preg_replace( '/\D+/', '', strtoupper( preg_replace( '/^RU/i', '', trim( $value ) ) ) ) ?? '';
		if ( '' === $digits ) {
			return array();
		}

		$variants = array( $digits );
		$rtrim = rtrim( $digits, '0' );
		if ( '' !== $rtrim && $rtrim !== $digits ) {
			$variants[] = $rtrim;
		}
		if ( strlen( $digits ) < 13 ) {
			$variants[] = str_pad( $digits, 13, '0' );
		}

		return array_values( array_unique( $variants ) );
	}

	private function normalize_guid( string $value ): string {
		$normalized = strtolower( preg_replace( '/[^a-f0-9]/i', '', $value ) ?? '' );
		return 32 === strlen( $normalized ) ? $normalized : '';
	}

	private function normalize_text( string $value ): string {
		$value = str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), trim( $value ) );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		return preg_replace( '/\s+/u', ' ', $value ) ?? $value;
	}

	private function normalize_type( string $value ): string {
		$value = $this->normalize_text( $value );
		return match ( $value ) {
			'город' => 'г',
			'поселок', 'посёлок' => 'п',
			'село' => 'с',
			'деревня' => 'д',
			default => $value,
		};
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyLookupKeys {
	/** @var array<string,string> */
	private array $fias = array();
	/** @var array<string,string> */
	private array $kladr = array();
	/** @var array<string,string> */
	private array $names = array();

	/**
	 * @param array<int,array<string,string>> $rows
	 */
	public static function from_rows( array $rows ): self {
		$keys = new self();
		foreach ( $rows as $row ) {
			if ( 'RU' !== strtoupper( trim( (string) ( $row['country_code'] ?? '' ) ) ) ) {
				continue;
			}
			$fias = self::normalize_guid( (string) ( $row['fias'] ?? '' ) );
			if ( '' !== $fias ) {
				$keys->fias[ $fias ] = $fias;
			}
			foreach ( self::kladr_variants( (string) ( $row['kladr'] ?? '' ) ) as $kladr ) {
				$keys->kladr[ $kladr ] = $kladr;
			}
			$name = trim( (string) ( $row['settlement'] ?? '' ) );
			if ( '' !== $name ) {
				$keys->names[ self::normalize_text( $name ) ] = $name;
			}
		}

		return $keys;
	}

	/**
	 * @return array<int,string>
	 */
	public function fias_guids(): array {
		return array_values( $this->fias );
	}

	/**
	 * @return array<int,string>
	 */
	public function kladr_keys(): array {
		return array_values( $this->kladr );
	}

	/**
	 * @return array<int,string>
	 */
	public function names(): array {
		return array_values( $this->names );
	}

	public static function normalize_guid( string $value ): string {
		$normalized = strtolower( preg_replace( '/[^a-f0-9]/i', '', $value ) ?? '' );
		return 32 === strlen( $normalized ) ? $normalized : '';
	}

	/**
	 * @return array<int,string>
	 */
	public static function guid_variants( string $value ): array {
		$normalized = self::normalize_guid( $value );
		if ( '' === $normalized ) {
			return array();
		}
		$canonical = substr( $normalized, 0, 8 ) . '-' . substr( $normalized, 8, 4 ) . '-' . substr( $normalized, 12, 4 ) . '-' . substr( $normalized, 16, 4 ) . '-' . substr( $normalized, 20 );

		return array_values( array_unique( array( $canonical, strtoupper( $canonical ), $normalized, strtoupper( $normalized ) ) ) );
	}

	/**
	 * @return array<int,string>
	 */
	public static function kladr_variants( string $value ): array {
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

	public static function name_key( string $region, string $district, string $name, string $type ): string {
		$name = self::normalize_text( $name );
		if ( '' === $name ) {
			return '';
		}

		return implode( '|', array( self::normalize_text( $region ), self::normalize_text( $district ), $name, self::normalize_type( $type ) ) );
	}

	public static function normalize_text( string $value ): string {
		$value = str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), trim( $value ) );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		return preg_replace( '/\s+/u', ' ', $value ) ?? $value;
	}

	public static function normalize_type( string $value ): string {
		$value = self::normalize_text( $value );
		return match ( $value ) {
			'город' => 'г',
			'поселок', 'посёлок' => 'п',
			'село' => 'с',
			'деревня' => 'д',
			default => $value,
		};
	}
}

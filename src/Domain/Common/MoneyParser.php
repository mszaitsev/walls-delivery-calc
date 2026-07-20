<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Common;

defined( 'ABSPATH' ) || exit;

final class MoneyParser {
	public static function rubles_to_kopecks( string $decimal ): int {
		$normalized = self::normalized_decimal( $decimal );
		if ( null === $normalized ) {
			throw new \InvalidArgumentException( 'Money amount must be a decimal number.' );
		}

		return self::decimal_to_kopecks( $normalized );
	}

	public static function numeric_to_kopecks( int|float|string $value ): ?int {
		$normalized = self::normalized_decimal( (string) $value );
		if ( null === $normalized ) {
			return null;
		}

		return self::decimal_to_kopecks( $normalized );
	}

	public static function first_decimal_to_kopecks( mixed $value ): ?int {
		if ( is_int( $value ) || is_float( $value ) ) {
			return self::numeric_to_kopecks( $value );
		}
		$text = str_replace( "\xc2\xa0", ' ', (string) $value );
		if ( 1 !== preg_match( '/[-+]?\d+(?:[\s ]\d{3})*(?:[\.,]\d+)?|[-+]?\d+(?:[\.,]\d+)?/', $text, $matches ) ) {
			return null;
		}

		return self::numeric_to_kopecks( $matches[0] );
	}

	private static function normalized_decimal( string $value ): ?string {
		$value = trim( str_replace( array( "\xc2\xa0", ' ' ), '', $value ) );
		$value = str_replace( ',', '.', $value );
		if ( 1 !== preg_match( '/^[+-]?\d+(?:\.\d+)?$/', $value ) ) {
			return null;
		}

		return $value;
	}

	private static function decimal_to_kopecks( string $decimal ): int {
		$negative = str_starts_with( $decimal, '-' );
		$decimal = ltrim( $decimal, '+-' );
		list( $rubles, $fraction ) = array_pad( explode( '.', $decimal, 2 ), 2, '' );
		$kopecks = (int) substr( str_pad( $fraction, 2, '0' ), 0, 2 );
		if ( isset( $fraction[2] ) && (int) $fraction[2] >= 5 ) {
			++$kopecks;
		}
		$amount = (int) $rubles * 100 + $kopecks;

		return $negative ? -$amount : $amount;
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Phone;

defined( 'ABSPATH' ) || exit;

final class RussianPhoneNormalizer {
	public function normalize( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$raw = trim( (string) $value );
		if ( '' === $raw || preg_match( '/[\p{L}]/u', $raw ) ) {
			return '';
		}

		$value = preg_replace( '/[^\d+]+/u', '', $raw ) ?? '';
		if ( substr_count( $value, '+' ) > 1 || ( str_contains( $value, '+' ) && ! str_starts_with( $value, '+' ) ) ) {
			return '';
		}

		if ( preg_match( '/^8(\d{10})$/', $value, $matches ) || preg_match( '/^7(\d{10})$/', $value, $matches ) ) {
			return '+7' . $matches[1];
		}

		return preg_match( '/^\+7\d{10}$/', $value ) ? $value : '';
	}
}

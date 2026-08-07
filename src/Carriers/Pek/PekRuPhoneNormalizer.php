<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek;

defined( 'ABSPATH' ) || exit;

final class PekRuPhoneNormalizer {
	public function normalize( mixed $value ): string {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			throw new \InvalidArgumentException( 'Некорректный телефон ПЭК.' );
		}
		$raw = (string) $value;
		if ( 1 === preg_match( '/[\x00-\x1F\x7F-\x9F]/u', $raw ) ) {
			throw new \InvalidArgumentException( 'Некорректный телефон ПЭК.' );
		}
		$raw = trim( $raw );
		if ( '' === $raw || 1 !== preg_match( '/^[\d+ ()\-]+$/', $raw ) ) {
			throw new \InvalidArgumentException( 'Некорректный телефон ПЭК.' );
		}
		$normalized = preg_replace( '/[ ()\-]+/', '', $raw ) ?? '';
		if ( 1 === preg_match( '/^8(\d{10})$/', $normalized, $matches ) ) {
			return '+7' . $matches[1];
		}
		if ( 1 === preg_match( '/^7(\d{10})$/', $normalized, $matches ) ) {
			return '+7' . $matches[1];
		}
		if ( 1 === preg_match( '/^\+7\d{10}$/', $normalized ) ) {
			return $normalized;
		}

		throw new \InvalidArgumentException( 'Некорректный телефон ПЭК.' );
	}
}

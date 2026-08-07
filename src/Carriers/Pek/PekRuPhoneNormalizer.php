<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek;

defined( 'ABSPATH' ) || exit;

final class PekRuPhoneNormalizer {
	public function normalize( mixed $value ): string {
		if ( null === $value || is_bool( $value ) || is_array( $value ) || is_object( $value ) ) {
			throw new \InvalidArgumentException( 'Некорректный телефон ПЭК.' );
		}
		$raw = trim( (string) $value );
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

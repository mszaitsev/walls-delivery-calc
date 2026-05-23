<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Services;

use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class LocationAliasGenerator {
	public function generate( Location $location ): array {
		$name = trim( $location->resolved_place_name() );
		if ( '' === $name ) {
			return array();
		}

		$normalized = Location::normalize_search_text( $name );
		$aliases = array( $name, $normalized );

		if ( 'новосибирск' === $normalized || 'Новосибирск' === $name || 'РЅРѕРІРѕСЃРёР±РёСЂСЃРє' === $normalized ) {
			$aliases[] = 'новосиб';
			$aliases[] = 'нск';
			$aliases[] = 'новосибирская';
		}

		if ( 'бердск' === $normalized || 'Бердск' === $name || 'Р±РµСЂРґСЃРє' === $normalized ) {
			$aliases[] = 'бердск';
		}

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) && mb_strlen( $normalized, 'UTF-8' ) > 5 ) {
			$aliases[] = mb_substr( $normalized, 0, 5, 'UTF-8' );
		}

		return array_values( array_unique( array_filter( array_map( 'trim', $aliases ) ) ) );
	}
}

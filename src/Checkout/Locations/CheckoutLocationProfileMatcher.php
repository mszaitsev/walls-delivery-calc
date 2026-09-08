<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Locations;

use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class CheckoutLocationProfileMatcher {
	/** @var array<int,string> */
	private const SETTLEMENT_TYPE_TOKENS = array(
		'г',
		'город',
		'п',
		'пос',
		'поселок',
		'посёлок',
		'пгт',
		'рп',
		'с',
		'село',
		'д',
		'деревня',
		'ст',
		'станица',
		'х',
		'хутор',
		'аул',
	);

	/** @var array<int,string> */
	private const REGION_TYPE_TOKENS = array(
		'автономная область',
		'автономный округ',
		'обл',
		'область',
		'край',
		'респ',
		'республика',
		'ао',
		'округ',
		'район',
		'р н',
		'рн',
	);

	public function __construct( private LocationRepository $locations ) {
	}

	/**
	 * @return array{status:string,location:?Location}
	 */
	public function match( string $country_code, string $city_text, string $region_text = '' ): array {
		$country_code = $this->country_code( $country_code );
		$city = $this->normalize_settlement_name( $city_text );
		$region = $this->normalize_region_name( $region_text );
		if ( '' === $country_code || '' === $city ) {
			return array( 'status' => 'not_found', 'location' => null );
		}

		$matches = array();
		foreach ( $this->country_locations( $country_code ) as $location ) {
			if ( ! $location->active || $country_code !== $this->country_code( $location->country_code ) ) {
				continue;
			}
			if ( ! in_array( $city, $this->settlement_candidates( $location ), true ) ) {
				continue;
			}
			if ( '' !== $region && ! in_array( $region, $this->region_candidates( $location ), true ) ) {
				continue;
			}
			$matches[ (string) ( $location->id ?? $location->fias_id ?: $location->gar_object_id ?: spl_object_id( $location ) ) ] = $location;
		}

		$matches = array_values( $matches );
		if ( 1 === count( $matches ) ) {
			return array( 'status' => 'resolved', 'location' => $matches[0] );
		}

		return array( 'status' => count( $matches ) > 1 ? 'ambiguous' : 'not_found', 'location' => null );
	}

	public static function normalize_settlement_name( string $value ): string {
		return self::normalize_name( $value, self::SETTLEMENT_TYPE_TOKENS );
	}

	public static function normalize_region_name( string $value ): string {
		return self::normalize_name( $value, self::REGION_TYPE_TOKENS );
	}

	/**
	 * @return array<int,Location>
	 */
	private function country_locations( string $country_code ): array {
		$locations = array();
		$after_id = 0;
		do {
			$batch = $this->locations->find_batch_after_id( $after_id, 1000, $country_code, false );
			foreach ( $batch as $location ) {
				if ( null !== $location->id && $location->id > $after_id ) {
					$after_id = $location->id;
				}
				$locations[] = $location;
			}
		} while ( count( $batch ) >= 1000 );

		return $locations;
	}

	/**
	 * @return array<int,string>
	 */
	private function settlement_candidates( Location $location ): array {
		$place_name = $location->resolved_place_name();
		$city_name = trim( $location->city_name );
		$values = array(
			$place_name,
			trim( $location->resolved_place_type() . ' ' . $place_name ),
			$location->settlement_name,
			trim( $location->settlement_type . ' ' . $location->settlement_name ),
		);
		if ( '' !== $city_name && self::normalize_settlement_name( $city_name ) === self::normalize_settlement_name( $place_name ) ) {
			$values[] = $city_name;
			$values[] = trim( $location->city_type . ' ' . $city_name );
		}

		return $this->normalized_unique( $values, array( self::class, 'normalize_settlement_name' ) );
	}

	/**
	 * @return array<int,string>
	 */
	private function region_candidates( Location $location ): array {
		return $this->normalized_unique(
			array(
				$location->region_name,
				trim( $location->region_type . ' ' . $location->region_name ),
			),
			array( self::class, 'normalize_region_name' )
		);
	}

	/**
	 * @param array<int,string> $values
	 * @param callable(string):string $normalizer
	 * @return array<int,string>
	 */
	private function normalized_unique( array $values, callable $normalizer ): array {
		$normalized = array();
		foreach ( $values as $value ) {
			$value = $normalizer( $value );
			if ( '' !== $value ) {
				$normalized[] = $value;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * @param array<int,string> $type_tokens
	 */
	private static function normalize_name( string $value, array $type_tokens ): string {
		$value = trim( str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), $value ) );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = preg_replace( '/[.,\/\\\\()\-_–—]+/u', ' ', $value );
		$value = is_string( $value ) ? preg_replace( '/\s+/u', ' ', trim( $value ) ) : '';
		$value = is_string( $value ) ? $value : '';
		foreach ( $type_tokens as $token ) {
			$token = preg_replace( '/\s+/u', ' ', trim( str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), function_exists( 'mb_strtolower' ) ? mb_strtolower( $token, 'UTF-8' ) : strtolower( $token ) ) ) );
			if ( ! is_string( $token ) || '' === $token ) {
				continue;
			}
			$value = preg_replace( '/(?:^|\s)' . preg_quote( $token, '/' ) . '(?=\s|$)/u', ' ', $value ) ?? $value;
		}

		return preg_replace( '/\s+/u', ' ', trim( $value ) ) ?? '';
	}

	private function country_code( string $country_code ): string {
		$country_code = strtoupper( trim( $country_code ) );
		return preg_match( '/^[A-Z]{2}$/', $country_code ) ? $country_code : '';
	}
}

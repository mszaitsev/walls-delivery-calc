<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Cdek;

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Carriers\Cdek\CdekLocationResolver;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class CdekDeliveryPointService {
	private const ENDPOINT = '/v2/deliverypoints';
	private const DEFAULT_TYPE = 'ALL';
	private const CACHE_TTL = 21600;

	public function __construct(
		private CdekApiClient $client,
		private CdekSettings $settings,
		private CdekLocationResolver $locations,
		private Logger $logger
	) {
	}

	/**
	 * @param array<string,mixed> $location
	 * @param array<string,mixed> $options
	 * @return array<int,array<string,mixed>>
	 */
	public function pointsForLocation( array $location, array $options = array() ): array {
		$city_code = $this->city_code_from_location( $location );
		if ( $city_code <= 0 ) {
			$resolved = $this->resolve_location( $location );
			$city_code = (int) ( $resolved['city_code'] ?? 0 );
		}
		if ( $city_code <= 0 ) {
			return array();
		}

		return $this->pointsByCityCode( $city_code, $options );
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array<int,array<string,mixed>>
	 */
	public function pointsByCityCode( int $city_code, array $options = array() ): array {
		if ( $city_code <= 0 || ! $this->settings->credentials_are_complete() ) {
			return array();
		}

		$type = $this->normalize_type( (string) ( $options['type'] ?? self::DEFAULT_TYPE ) );
		$query = array(
			'city_code' => $city_code,
			'country_code' => 'RU',
			'type' => $type,
		);
		$cache_key = $this->cache_key( $query );
		$bypass_cache = ! empty( $options['refresh'] ) || ! empty( $options['bypass_cache'] );
		if ( ! $bypass_cache ) {
			$cached = $this->cached( $cache_key );
			if ( array() !== $cached ) {
				return $cached;
			}
		}

		try {
			$result = $this->client->deliveryPoints( $query );
		} catch ( CdekApiException $exception ) {
			$details = $exception->details();
			$this->logger->warning(
				'CDEK deliverypoints failed.',
				array(
					'carrier' => CdekSettings::CARRIER_KEY,
					'city_code' => $city_code,
					'endpoint' => self::ENDPOINT,
					'http_code' => (int) ( $details['http_code'] ?? 0 ),
					'cdek_error_code' => (string) ( $details['cdek_error_code'] ?? '' ),
					'cdek_error_message' => (string) ( $details['cdek_error_message'] ?? $exception->getMessage() ),
				)
			);
			return array();
		}

		$body = is_array( $result['body'] ?? null ) ? $result['body'] : array();
		$points = array_values( array_filter( array_map( array( $this, 'normalize' ), $body ) ) );
		$this->store( $cache_key, $points );

		return $points;
	}

	/**
	 * @param array<string,mixed> $point
	 * @return array<string,mixed>
	 */
	public function normalize( array $point ): array {
		$location = is_array( $point['location'] ?? null ) ? $point['location'] : array();
		$code = trim( (string) ( $point['code'] ?? '' ) );
		if ( '' === $code ) {
			return array();
		}
		$type = strtoupper( trim( (string) ( $point['type'] ?? 'PVZ' ) ) );
		$address = trim( (string) ( $location['address_full'] ?? $location['address'] ?? $point['address'] ?? $point['address_comment'] ?? '' ) );
		$city = trim( (string) ( $location['city'] ?? $location['city_name'] ?? $point['city'] ?? '' ) );
		$region = trim( (string) ( $location['region'] ?? $location['region_name'] ?? $point['region'] ?? '' ) );
		$postcode = preg_replace( '/\D+/', '', (string) ( $location['postal_code'] ?? $location['postcode'] ?? $point['postal_code'] ?? $point['postcode'] ?? '' ) ) ?? '';
		$lat = $this->float_or_null( $location['latitude'] ?? $point['latitude'] ?? null );
		$lng = $this->float_or_null( $location['longitude'] ?? $point['longitude'] ?? null );
		$name = trim( (string) ( $point['name'] ?? $point['address_comment'] ?? '' ) );
		if ( '' === $name ) {
			$name = 'PVZ ' . $code;
		}

		return array(
			'id' => 'cdek:' . $code,
			'carrier' => CdekSettings::CARRIER_KEY,
			'carrier_key' => CdekSettings::CARRIER_KEY,
			'point_code' => $code,
			'point_type' => $type,
			'point_name' => $name,
			'point_address' => $address,
			'point_postcode' => $postcode,
			'postal_code' => $postcode,
			'postcode' => $postcode,
			'city_name' => $city,
			'city' => $city,
			'region_name' => $region,
			'region' => $region,
			'latitude' => $lat,
			'longitude' => $lng,
			'lat' => $lat,
			'lng' => $lng,
			'work_time' => (string) ( $point['work_time'] ?? '' ),
			'description' => (string) ( $point['note'] ?? $point['address_comment'] ?? '' ),
			'cdek_code' => $code,
			'cdek_uuid' => (string) ( $point['uuid'] ?? '' ),
			'cdek_type' => $type,
			'cdek_owner_code' => (string) ( $point['owner_code'] ?? '' ),
			'cdek_nearest_station' => (string) ( $point['nearest_station'] ?? '' ),
			'cdek_note' => (string) ( $point['note'] ?? '' ),
			'raw' => $this->sanitize_raw( $point ),
		);
	}

	/**
	 * @param array<string,mixed> $location
	 */
	private function city_code_from_location( array $location ): int {
		foreach ( array( 'cdek_city_code', 'city_code' ) as $key ) {
			if ( isset( $location[ $key ] ) && is_numeric( $location[ $key ] ) ) {
				return max( 0, (int) $location[ $key ] );
			}
		}

		return 0;
	}

	/**
	 * @param array<string,mixed> $location
	 * @return array<string,mixed>
	 */
	private function resolve_location( array $location ): array {
		$address = new Address(
			country_code: (string) ( $location['country_code'] ?? 'RU' ),
			region_name: (string) ( $location['region_name'] ?? $location['state_value'] ?? '' ),
			city: (string) ( $location['city_name'] ?? $location['city_value'] ?? $location['settlement_name'] ?? $location['display_name'] ?? '' ),
			settlement: (string) ( $location['settlement_name'] ?? $location['place_name'] ?? '' ),
			postcode: (string) ( $location['postal_code'] ?? $location['postcode'] ?? '' ),
			fias_id: (string) ( $location['fias_id'] ?? $location['city_fias_id'] ?? '' ),
			gar_id: (string) ( $location['gar_id'] ?? $location['gar_object_id'] ?? '' ),
			normalized: true
		);
		$request = new QuoteRequest(
			'RU',
			$address,
			new Package( array(), Money::from_rubles( 0 ), Money::from_rubles( 0 ), 1, 0, 1, source: 'manual' ),
			'',
			Money::from_rubles( 0 ),
			gmdate( 'Y-m-d' ),
			array( 'delivery_type' => 'pickup' )
		);

		return $this->locations->resolve( $request );
	}

	private function normalize_type( string $type ): string {
		$type = strtoupper( trim( $type ) );

		return in_array( $type, array( 'ALL', 'PVZ', 'POSTAMAT' ), true ) ? $type : self::DEFAULT_TYPE;
	}

	/**
	 * @param array<string,mixed> $query
	 */
	private function cache_key( array $query ): string {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $query ) : json_encode( $query );

		return 'wdc_cdek_deliverypoints_' . sha1( $this->settings->environment() . '|' . (string) $encoded );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function cached( string $key ): array {
		$value = function_exists( 'get_transient' ) ? get_transient( $key ) : false;

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<int,array<string,mixed>> $points
	 */
	private function store( string $key, array $points ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $points, self::CACHE_TTL );
		}
	}

	private function float_or_null( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function sanitize_raw( array $raw ): array {
		foreach ( array_keys( $raw ) as $key ) {
			$key_text = strtolower( (string) $key );
			if (
				in_array( $key_text, array( 'email', 'phones', 'office_image_list' ), true )
				|| str_contains( $key_text, 'token' )
				|| str_contains( $key_text, 'secret' )
				|| str_contains( $key_text, 'password' )
				|| str_contains( $key_text, 'authorization' )
				|| 'account' === $key_text
			) {
				unset( $raw[ $key ] );
				continue;
			}

			if ( is_array( $raw[ $key ] ) ) {
				$raw[ $key ] = $this->sanitize_raw( $raw[ $key ] );
			}
		}

		return $raw;
	}
}

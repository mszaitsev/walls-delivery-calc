<?php
/**
 * Russian Post countries dictionary client.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Russian_Post_Countries {
	private const API_URL = 'https://tariff.pochta.ru/v2/dictionary/country';
	private const CACHE_KEY = 'wdc_russian_post_worldwide_countries';
	private const CACHE_TTL = 7 * DAY_IN_SECONDS;

	private WDC_Cache $cache;

	private WDC_Logger $logger;

	private WDC_Settings $settings;

	private string $last_error = '';

	public function __construct( ?WDC_Cache $cache = null, ?WDC_Logger $logger = null, ?WDC_Settings $settings = null ) {
		$this->cache = $cache ?? new WDC_Cache();
		$this->logger = $logger ?? new WDC_Logger();
		$this->settings = $settings ?? new WDC_Settings();
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function get_countries( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = $this->get_cached_payload();
			if ( ! empty( $cached['countries'] ) && is_array( $cached['countries'] ) ) {
				$this->debug_log( 'Russian Post countries cache hit.', array( 'cache_key' => self::CACHE_KEY ) );

				return $cached['countries'];
			}

			$this->debug_log( 'Russian Post countries cache miss.', array( 'cache_key' => self::CACHE_KEY ) );
		}

		$countries = $this->refresh_countries();
		if ( ! empty( $countries ) ) {
			return $countries;
		}

		$cached = $this->get_cached_payload();
		if ( ! empty( $cached['countries'] ) && is_array( $cached['countries'] ) ) {
			return $cached['countries'];
		}

		return array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_country_by_wc_code( string $wc_country_code ): array {
		$wc_country_code = strtoupper( sanitize_text_field( $wc_country_code ) );
		if ( '' === $wc_country_code ) {
			return array();
		}

		$countries = $this->get_countries();

		return isset( $countries[ $wc_country_code ] ) && is_array( $countries[ $wc_country_code ] )
			? $countries[ $wc_country_code ]
			: array();
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function refresh_countries(): array {
		$this->last_error = '';
		$this->debug_log( 'Russian Post countries refresh started.', array( 'url' => self::API_URL ) );

		$response = wp_remote_get(
			self::API_URL,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			$this->debug_log( 'Russian Post countries refresh failed.', array( 'error' => $this->last_error ) );

			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$this->last_error = 'Russian Post countries API returned HTTP ' . $code . '.';
			$this->debug_log( 'Russian Post countries refresh failed.', array( 'code' => $code, 'body' => $body ) );

			return array();
		}

		if ( ! is_array( $decoded ) ) {
			$this->last_error = 'Russian Post countries API returned invalid JSON.';
			$this->debug_log( 'Russian Post countries refresh failed.', array( 'error' => 'invalid_json', 'body' => $body ) );

			return array();
		}

		$countries = $this->normalize_countries( $decoded );
		if ( empty( $countries ) ) {
			$this->last_error = 'Russian Post countries API returned no usable country data.';
			$this->debug_log( 'Russian Post countries refresh failed.', array( 'error' => 'missing_country_data', 'raw' => $decoded ) );

			return array();
		}

		$payload = array(
			'updated_at' => current_time( 'mysql' ),
			'updated_at_gmt' => current_time( 'mysql', true ),
			'countries' => $countries,
		);

		$this->cache->set( self::CACHE_KEY, $payload, self::CACHE_TTL );
		$this->debug_log( 'Russian Post countries refresh completed.', array( 'count' => count( $countries ), 'cache_key' => self::CACHE_KEY ) );

		return $countries;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_cache_payload(): array {
		$cached = $this->get_cached_payload();

		return ! empty( $cached ) ? $cached : array(
			'updated_at' => '',
			'updated_at_gmt' => '',
			'countries' => array(),
		);
	}

	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_cached_payload(): array {
		$cached = $this->cache->get( self::CACHE_KEY );

		return is_array( $cached ) ? $cached : array();
	}

	/**
	 * @param array<string, mixed> $decoded Raw API response.
	 * @return array<string, array<string, mixed>>
	 */
	private function normalize_countries( array $decoded ): array {
		$items = $this->extract_country_items( $decoded );
		$countries = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$country = $this->normalize_country( $item );
			if ( empty( $country['iso2'] ) || empty( $country['carrier_country_id'] ) ) {
				continue;
			}

			if ( 'RU' === $country['iso2'] ) {
				continue;
			}

			if ( empty( $country['enabled'] ) ) {
				continue;
			}

			$countries[ $country['iso2'] ] = $country;
		}

		ksort( $countries );

		return $countries;
	}

	/**
	 * @param array<string, mixed> $decoded Raw API response.
	 * @return array<int, mixed>
	 */
	private function extract_country_items( array $decoded ): array {
		foreach ( array( 'countries', 'country', 'items', 'data', 'dictionary', 'records', 'list' ) as $key ) {
			if ( isset( $decoded[ $key ] ) && is_array( $decoded[ $key ] ) ) {
				return array_values( $decoded[ $key ] );
			}
		}

		if ( $this->is_list_array( $decoded ) ) {
			return $decoded;
		}

		return array_values( $decoded );
	}

	/**
	 * @param array<string, mixed> $raw Raw country record.
	 * @return array<string, mixed>
	 */
	private function normalize_country( array $raw ): array {
		$iso2 = strtoupper( $this->first_code( $raw, array( 'iso2', 'alpha2', 'a2', 'code2', 'countryCode2', 'country_code_iso2', 'country_iso2', 'country_code' ), 2 ) );
		$iso3 = strtoupper( $this->first_code( $raw, array( 'iso3', 'alpha3', 'a3', 'code3', 'countryCode3', 'country_code_iso3', 'country_iso3' ), 3 ) );
		$carrier_country_id = $this->first_scalar(
			$raw,
			array(
				'carrier_country_id',
				'country_id',
				'country-id',
				'countryId',
				'id',
				'Id',
				'code',
				'Code',
				'country',
				'country-to',
			)
		);
		$name = $this->first_scalar(
			$raw,
			array(
				'name',
				'Name',
				'country_name',
				'countryName',
				'fullname',
				'fullName',
				'nameRu',
				'nameRus',
				'russianName',
			)
		);

		if ( '' === $iso2 ) {
			$iso2 = strtoupper( $this->first_code( $raw, array( 'code', 'Code' ), 2 ) );
		}

		if ( '' === $iso2 ) {
			$iso2 = $this->extract_iso_from_altnames( $raw, 2, 2 );
		}

		if ( '' === $iso3 ) {
			$iso3 = strtoupper( $this->first_code( $raw, array( 'code', 'Code' ), 3 ) );
		}

		if ( '' === $iso3 ) {
			$iso3 = $this->extract_iso_from_altnames( $raw, 3, 3 );
		}

		if ( '' !== $carrier_country_id && ! is_numeric( $carrier_country_id ) ) {
			$carrier_country_id = $this->first_numeric_scalar( $raw, array( 'id', 'Id', 'country_id', 'countryId', 'code', 'Code' ) );
		}

		return array(
			'carrier_country_id' => sanitize_text_field( $carrier_country_id ),
			'name' => sanitize_text_field( '' !== $name ? $name : $iso2 ),
			'iso2' => sanitize_text_field( $iso2 ),
			'iso3' => sanitize_text_field( $iso3 ),
			'enabled' => $this->is_country_enabled( $raw ),
			'raw' => $raw,
		);
	}

	/**
	 * @param array<string, mixed> $raw Raw country record.
	 * @param array<int, string>  $keys Candidate field names.
	 */
	private function first_scalar( array $raw, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
				return trim( (string) $raw[ $key ] );
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $raw Raw country record.
	 * @param array<int, string>  $keys Candidate field names.
	 */
	private function first_numeric_scalar( array $raw, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) {
				return trim( (string) $raw[ $key ] );
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $raw Raw country record.
	 * @param array<int, string>  $keys Candidate field names.
	 */
	private function first_code( array $raw, array $keys, int $length ): string {
		foreach ( $keys as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
				continue;
			}

			$value = strtoupper( trim( (string) $raw[ $key ] ) );
			if ( preg_match( '/^[A-Z]{' . $length . '}$/', $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $raw Raw country record.
	 */
	private function extract_iso_from_altnames( array $raw, int $type, int $length ): string {
		if ( empty( $raw['altnames'] ) || ! is_array( $raw['altnames'] ) ) {
			return '';
		}

		foreach ( $raw['altnames'] as $altname ) {
			if ( ! is_array( $altname ) ) {
				continue;
			}

			if ( ! isset( $altname['type'], $altname['name'] ) || (int) $altname['type'] !== $type || ! is_scalar( $altname['name'] ) ) {
				continue;
			}

			$name = strtoupper( trim( (string) $altname['name'] ) );
			if ( preg_match( '/^[A-Z]{' . $length . '}$/', $name ) ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $raw Raw country record.
	 */
	private function is_country_enabled( array $raw ): bool {
		if (
			$this->is_truthy_flag( $raw['block'] ?? null )
			|| $this->is_truthy_flag( $raw['blocked'] ?? null )
			|| $this->is_truthy_flag( $raw['disabled'] ?? null )
			|| $this->is_truthy_flag( $raw['parcel_block'] ?? null )
			|| $this->is_truthy_flag( $raw['parcelBlock'] ?? null )
		) {
			return false;
		}

		if (
			isset( $raw['parcel'] )
			&& is_array( $raw['parcel'] )
			&& ( $this->is_truthy_flag( $raw['parcel']['block'] ?? null ) || $this->is_truthy_flag( $raw['parcel']['blocked'] ?? null ) )
		) {
			return false;
		}

		foreach ( array( 'parcel_enabled', 'parcelEnabled', 'parcel_available', 'parcelAvailable', 'enabled', 'available', 'support', 'supported', 'allowed' ) as $key ) {
			if ( array_key_exists( $key, $raw ) ) {
				return $this->is_truthy_flag( $raw[ $key ] );
			}
		}

		if ( array_key_exists( 'parcel', $raw ) ) {
			$parcel = $raw['parcel'];

			if ( is_array( $parcel ) ) {
				if ( $this->is_truthy_flag( $parcel['block'] ?? null ) || $this->is_truthy_flag( $parcel['blocked'] ?? null ) ) {
					return false;
				}

				foreach ( array( 'enabled', 'available', 'support', 'supported', 'allowed' ) as $key ) {
					if ( array_key_exists( $key, $parcel ) ) {
						return $this->is_truthy_flag( $parcel[ $key ] );
					}
				}
			} elseif ( ! $this->is_truthy_flag( $parcel ) ) {
				return false;
			}
		}

		return true;
	}

	private function is_truthy_flag( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return 1 === (int) $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'yes', 'true', 'y', 'on' ), true );
		}

		return false;
	}

	/**
	 * @param array<mixed> $items Candidate list.
	 */
	private function is_list_array( array $items ): bool {
		$index = 0;
		foreach ( array_keys( $items ) as $key ) {
			if ( $key !== $index ) {
				return false;
			}

			++$index;
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $context Debug context.
	 */
	private function debug_log( string $message, array $context = array() ): void {
		$settings = $this->settings->get();
		if ( isset( $settings['debug_enabled'] ) && 'yes' === $settings['debug_enabled'] ) {
			$this->logger->log( 'debug', $message, $context );
		}
	}
}

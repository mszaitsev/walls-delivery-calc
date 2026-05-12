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

	/**
	 * @var array<string, mixed>
	 */
	private array $last_diagnostics = array();

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
				$this->debug_log(
					'Russian Post countries cache hit.',
					array(
						'cache_key' => self::CACHE_KEY,
						'enabled_country_count' => count( $cached['countries'] ),
						'last_error' => $this->last_error,
					)
				);

				return $cached['countries'];
			}

			$this->debug_log(
				'Russian Post countries cache miss.',
				array(
					'cache_key' => self::CACHE_KEY,
					'last_error' => $this->last_error,
				)
			);
		}

		$countries = $this->refresh_countries();
		if ( ! empty( $countries ) ) {
			return $countries;
		}

		$cached = $this->get_cached_payload();
		if ( ! empty( $cached['countries'] ) && is_array( $cached['countries'] ) ) {
			$this->debug_log(
				'Russian Post countries using cached data after refresh error.',
				array(
					'cache_key' => self::CACHE_KEY,
					'enabled_country_count' => count( $cached['countries'] ),
					'last_error' => $this->last_error,
				)
			);

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
		$this->last_diagnostics = $this->create_empty_diagnostics();
		$this->debug_log( 'Russian Post countries refresh started.', array( 'request_url' => self::API_URL ) );

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
			$this->last_diagnostics['last_error'] = $this->last_error;
			$this->debug_refresh_diagnostics( 'Russian Post countries refresh failed.' );

			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$this->last_diagnostics['http_code'] = $code;
		$this->last_diagnostics['body_snippet'] = $this->get_body_snippet( $body );

		if ( $code < 200 || $code >= 300 ) {
			$this->last_error = 'Russian Post countries API returned HTTP ' . $code . '.';
			$this->last_diagnostics['last_error'] = $this->last_error;
			$this->debug_refresh_diagnostics( 'Russian Post countries refresh failed.' );

			return array();
		}

		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$this->last_error = 'JSON decode error: ' . json_last_error_msg();
			$this->last_diagnostics['json_error'] = json_last_error_msg();
			$this->last_diagnostics['last_error'] = $this->last_error;
			$this->debug_refresh_diagnostics( 'Russian Post countries refresh failed.' );

			return array();
		}

		if ( ! is_array( $decoded ) ) {
			$this->last_error = 'Russian Post countries API returned JSON that is not an array.';
			$this->last_diagnostics['last_error'] = $this->last_error;
			$this->debug_refresh_diagnostics( 'Russian Post countries refresh failed.' );

			return array();
		}

		$items = $this->extract_country_items( $decoded );
		if ( null === $items ) {
			$this->last_error = 'Country list not found in API response.';
			$this->last_diagnostics['last_error'] = $this->last_error;
			$this->debug_refresh_diagnostics( 'Russian Post countries refresh failed.' );

			return array();
		}

		$this->last_diagnostics['raw_country_count'] = count( $items );
		$normalized_count = 0;
		$enabled_count = 0;
		$countries = $this->normalize_countries( $items, $normalized_count, $enabled_count );
		$this->last_diagnostics['normalized_country_count'] = $normalized_count;
		$this->last_diagnostics['enabled_country_count'] = $enabled_count;

		if ( empty( $countries ) ) {
			$this->last_error = 'Russian Post countries API returned no usable country data.';
			$this->last_diagnostics['last_error'] = $this->last_error;
			$this->debug_refresh_diagnostics( 'Russian Post countries refresh failed.' );

			return array();
		}

		$payload = array(
			'updated_at' => current_time( 'mysql' ),
			'updated_at_gmt' => current_time( 'mysql', true ),
			'countries' => $countries,
		);

		$this->cache->set( self::CACHE_KEY, $payload, self::CACHE_TTL );
		$this->last_diagnostics['last_error'] = '';
		$this->debug_refresh_diagnostics( 'Russian Post countries refresh completed.' );

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
	public function get_last_diagnostics(): array {
		return $this->last_diagnostics;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_cached_payload(): array {
		$cached = $this->cache->get( self::CACHE_KEY );

		return is_array( $cached ) ? $cached : array();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function create_empty_diagnostics(): array {
		return array(
			'request_url' => self::API_URL,
			'http_code' => 0,
			'body_snippet' => '',
			'json_error' => '',
			'raw_country_count' => 0,
			'normalized_country_count' => 0,
			'matched_country_count' => 0,
			'enabled_country_count' => 0,
			'skipped_unmatched_count' => 0,
			'skipped_blocked_count' => 0,
			'skipped_ru_count' => 0,
			'last_error' => '',
		);
	}

	private function get_body_snippet( string $body ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $body, 0, 1000 );
		}

		return substr( $body, 0, 1000 );
	}

	private function debug_refresh_diagnostics( string $message ): void {
		$this->debug_log( $message, $this->last_diagnostics );
	}

	/**
	 * @param array<int, mixed> $items Raw country records.
	 * @return array<string, array<string, mixed>>
	 */
	private function normalize_countries( array $items, int &$normalized_count, int &$enabled_count ): array {
		$countries = array();
		$wc_country_names = $this->build_wc_country_name_map();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$country = $this->normalize_country( $item, $wc_country_names );
			if ( empty( $country['iso2'] ) || empty( $country['carrier_country_id'] ) ) {
				++$this->last_diagnostics['skipped_unmatched_count'];
				continue;
			}

			++$normalized_count;
			++$this->last_diagnostics['matched_country_count'];

			if ( $this->is_russian_country( $country ) ) {
				++$this->last_diagnostics['skipped_ru_count'];
				continue;
			}

			if ( empty( $country['enabled'] ) ) {
				++$this->last_diagnostics['skipped_blocked_count'];
				continue;
			}

			++$enabled_count;
			$countries[ $country['iso2'] ] = $country;
		}

		ksort( $countries );

		return $countries;
	}

	/**
	 * @param array<string, mixed> $decoded Raw API response.
	 * @return array<int, mixed>|null
	 */
	private function extract_country_items( array $decoded ): ?array {
		if ( isset( $decoded['country'] ) && is_array( $decoded['country'] ) ) {
			return array_values( $decoded['country'] );
		}

		if ( $this->is_list_array( $decoded ) ) {
			return $decoded;
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $raw Raw country record.
	 * @param array<string, string> $wc_country_names Normalized WooCommerce country name to ISO2 map.
	 * @return array<string, mixed>
	 */
	private function normalize_country( array $raw, array $wc_country_names = array() ): array {
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

		if ( '' === $iso2 && '' !== $name ) {
			$normalized_name = $this->normalize_country_name_for_match( $name );
			if ( isset( $wc_country_names[ $normalized_name ] ) ) {
				$iso2 = $wc_country_names[ $normalized_name ];
			}
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
	 * @return array<string, string>
	 */
	private function build_wc_country_name_map(): array {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}

		$woocommerce = WC();
		if ( ! is_object( $woocommerce ) || empty( $woocommerce->countries ) ) {
			return array();
		}

		$countries = $woocommerce->countries->get_countries();
		if ( ! is_array( $countries ) ) {
			return array();
		}

		$map = array();
		foreach ( $countries as $iso2 => $name ) {
			if ( ! is_scalar( $iso2 ) || ! is_scalar( $name ) ) {
				continue;
			}

			$iso2 = strtoupper( sanitize_text_field( (string) $iso2 ) );
			$normalized_name = $this->normalize_country_name_for_match( (string) $name );

			if ( '' !== $iso2 && '' !== $normalized_name && ! isset( $map[ $normalized_name ] ) ) {
				$map[ $normalized_name ] = $iso2;
			}
		}

		return $map;
	}

	private function normalize_country_name_for_match( string $name ): string {
		$name = trim( $name );
		$name = str_replace( array( 'ё', 'Ё' ), array( 'е', 'Е' ), $name );
		$name = str_replace( array( '"', "'", '«', '»', '.', ',' ), ' ', $name );
		$name = preg_replace( '/\s+/u', ' ', $name );
		$name = is_string( $name ) ? trim( $name ) : '';

		if ( function_exists( 'mb_strtoupper' ) ) {
			return mb_strtoupper( $name, 'UTF-8' );
		}

		return strtoupper( $name );
	}

	/**
	 * @param array<string, mixed> $country Normalized country record.
	 */
	private function is_russian_country( array $country ): bool {
		if ( 'RU' === (string) ( $country['iso2'] ?? '' ) ) {
			return true;
		}

		$name = $this->normalize_country_name_for_match( (string) ( $country['name'] ?? '' ) );

		return in_array( $name, array( 'РОССИЯ', 'РОССИЙСКАЯ ФЕДЕРАЦИЯ' ), true );
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
			return 0 !== (int) $value;
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

<?php
/**
 * Russian Post international export carrier.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Russian_Post_Carrier implements WDC_Carrier_Interface {
	public const INTERNATIONAL_OUTGOING_PARCEL_OBJECT_CODE = 4031;

	private const HARD_CODED_COUNTRIES = array(
		'BG' => 100,
	);

	private WDC_Settings $settings;

	private WDC_Quote_Normalizer $normalizer;

	private WDC_Weight_Calculator $weight_calculator;

	private WDC_Cache $cache;

	private WDC_Russian_Post_API $api;

	private WDC_Logger $logger;

	public function __construct(
		?WDC_Settings $settings = null,
		?WDC_Quote_Normalizer $normalizer = null,
		?WDC_Weight_Calculator $weight_calculator = null,
		?WDC_Cache $cache = null,
		?WDC_Russian_Post_API $api = null,
		?WDC_Logger $logger = null
	) {
		$this->settings = $settings ?? new WDC_Settings();
		$this->normalizer = $normalizer ?? new WDC_Quote_Normalizer();
		$this->weight_calculator = $weight_calculator ?? new WDC_Weight_Calculator();
		$this->cache = $cache ?? new WDC_Cache();
		$this->logger = $logger ?? new WDC_Logger();
		$this->api = $api ?? new WDC_Russian_Post_API( $this->logger );
	}

	public function get_id(): string {
		return WDC_Carrier_Registry::CARRIER_RUSSIAN_POST;
	}

	public function get_title(): string {
		return 'Почта России';
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function get_services(): array {
		$definitions = ( new WDC_Carrier_Registry() )->get_definitions();

		return $definitions[ WDC_Carrier_Registry::CARRIER_RUSSIAN_POST ]['services'];
	}

	/**
	 * @param array<string, mixed> $package WooCommerce package data.
	 * @param array<string, mixed> $context Additional calculation context.
	 * @return array<string, mixed>
	 */
	public function get_quote( array $package, array $context = array() ): array {
		$settings = $this->settings->get();
		$service_settings = $settings['services'][ WDC_Carrier_Registry::SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL ];
		$debug_enabled = 'yes' === $settings['debug_enabled'];
		$country_code = $this->get_destination_country_code( $package );
		$country_name = $this->get_destination_country_name( $country_code );
		$destination = $this->build_destination( $package, $country_code, $country_name );
		$context = array_merge(
			$context,
			array(
				'settings' => $settings,
				'destination' => $destination,
			)
		);

		if ( 'yes' !== $service_settings['enabled'] ) {
			return $this->fallback( 'service_disabled', $context, $debug_enabled );
		}

		if ( ! isset( self::HARD_CODED_COUNTRIES[ $country_code ] ) ) {
			return $this->fallback( 'unsupported_country_' . $country_code, $context, $debug_enabled );
		}

		$weight = $this->weight_calculator->calculate_package_weight( $package, $settings['packaging_tiers'] );
		$context['weight'] = $weight;
		$max_package_weight_g = absint( $service_settings['max_package_weight_g'] );

		if ( $weight['total_weight_g'] > $max_package_weight_g ) {
			$this->debug_log(
				$debug_enabled,
				'Russian Post package overweight.',
				array(
					'weight' => $weight,
					'max_package_weight_g' => $max_package_weight_g,
				)
			);

			return $this->fallback( 'overweight', $context, $debug_enabled );
		}

		$carrier_country_id = self::HARD_CODED_COUNTRIES[ $country_code ];
		$destination['carrier_country_id'] = (string) $carrier_country_id;
		$context['destination'] = $destination;
		$request_params = $this->build_request_params( $service_settings, $carrier_country_id, $weight['total_weight_g'] );
		$cache_key = $this->build_cache_key( $request_params );
		$api_result = $this->get_cached_api_result( $cache_key, $request_params, $service_settings, $debug_enabled );

		if ( empty( $api_result['success'] ) || empty( $api_result['raw'] ) || ! is_array( $api_result['raw'] ) ) {
			return $this->fallback( (string) ( $api_result['error_code'] ?? 'api_error' ), $context, $debug_enabled, $api_result );
		}

		$price_rub = $this->extract_price_rub( $api_result['raw'] );
		if ( null === $price_rub ) {
			return $this->fallback( 'missing_price', $context, $debug_enabled, $api_result );
		}

		$calculated_price = (int) ceil( $price_rub / (float) $service_settings['formula_divider'] + (float) $service_settings['formula_add_rub'] );
		$transport_type = $this->map_transport_type( $api_result['raw']['transtype'] ?? null );

		$this->debug_log(
			$debug_enabled,
			'Russian Post price calculated.',
			array(
				'price_rub' => $price_rub,
				'formula_divider' => $service_settings['formula_divider'],
				'formula_add_rub' => $service_settings['formula_add_rub'],
				'calculated_price' => $calculated_price,
				'cache_key' => $cache_key,
			)
		);

		return $this->normalizer->normalize_quote(
			array(
				'success' => true,
				'destination' => $destination,
				'weight' => $weight,
				'rates' => array(
					array(
						'rate_id' => 'russian_post_international_parcel_' . $transport_type,
						'rate_title' => 'Доставка Почтой России',
						'label_template' => (string) $service_settings['calculated_label_template'],
						'delivery_method' => 'post_office',
						'delivery_method_title' => 'до отделения / пункта выдачи',
						'transport_type' => $transport_type,
						'transport_type_title' => 'air' === $transport_type ? 'авиадоставка' : 'наземная доставка',
						'tariff_type' => 'standard',
						'tariff_type_title' => 'стандартный тариф',
						'price' => $calculated_price,
						'currency' => (string) $settings['currency'],
						'meta' => array(
							'base_price_rub' => $price_rub,
							'cache_key' => $cache_key,
							'request_url' => $api_result['url'] ?? '',
							'request_params' => $request_params,
						),
						'raw' => $api_result['raw'],
					),
				),
				'meta' => array(
					'cache_key' => $cache_key,
					'request_url' => $api_result['url'] ?? '',
					'request_params' => $request_params,
				),
				'raw' => $api_result['raw'],
			)
		);
	}

	/**
	 * @param array<string, mixed> $context Fallback context.
	 * @param array<string, mixed> $api_result Optional API result.
	 * @return array<string, mixed>
	 */
	private function fallback( string $reason, array $context, bool $debug_enabled, array $api_result = array() ): array {
		$this->debug_log( $debug_enabled, 'Russian Post fallback used.', array( 'reason' => $reason, 'api_result' => $api_result ) );

		$quote = $this->normalizer->create_fallback_quote( $context );
		$quote['meta']['fallback_reason'] = $reason;
		$quote['error_code'] = $reason;
		$quote['error_message'] = $reason;

		if ( ! empty( $api_result ) ) {
			$quote['raw'] = $api_result['raw'] ?? $api_result;
			$quote['meta']['api_result'] = $api_result;
		}

		return $quote;
	}

	/**
	 * @param array<string, mixed> $service_settings Service settings.
	 * @return array<string, scalar>
	 */
	private function build_request_params( array $service_settings, int $carrier_country_id, int $weight_g ): array {
		$date = function_exists( 'wp_date' ) ? wp_date( 'Ymd' ) : gmdate( 'Ymd' );

		return array(
			'object' => absint( $service_settings['object_code'] ),
			'from' => (string) $service_settings['origin_postcode'],
			'country-to' => $carrier_country_id,
			'weight' => $weight_g,
			'date' => $date,
			'date-discount' => $date,
			'isavia' => absint( $service_settings['isavia'] ),
		);
	}

	/**
	 * @param array<string, scalar> $request_params Request params.
	 * @param array<string, mixed>  $service_settings Service settings.
	 * @return array<string, mixed>
	 */
	private function get_cached_api_result( string $cache_key, array $request_params, array $service_settings, bool $debug_enabled ): array {
		$cached = $this->cache->get( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			$this->debug_log( $debug_enabled, 'Russian Post cache hit.', array( 'cache_key' => $cache_key ) );

			return $cached;
		}

		$this->debug_log( $debug_enabled, 'Russian Post cache miss.', array( 'cache_key' => $cache_key, 'params' => $request_params ) );

		$result = $this->api->calculate_tariff( $request_params, $debug_enabled );
		if ( ! empty( $result['success'] ) && 'yes' === $service_settings['cache_until_end_of_day'] ) {
			$this->cache->set( $cache_key, $result, $this->cache->get_seconds_until_end_of_day() );
		}

		return $result;
	}

	/**
	 * @param array<string, scalar> $request_params Request params.
	 */
	private function build_cache_key( array $request_params ): string {
		return 'rp_intl_' . md5( wp_json_encode( $request_params ) );
	}

	/**
	 * @param array<string, mixed> $raw Raw API response.
	 */
	private function extract_price_rub( array $raw ): ?float {
		$value = $raw['paynds'] ?? $raw['paymoneynds'] ?? null;

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		return (float) $value / 100;
	}

	private function map_transport_type( $transtype ): string {
		if ( 2 === (int) $transtype ) {
			return 'air';
		}

		return 'ground';
	}

	/**
	 * @param array<string, mixed> $package WooCommerce package data.
	 */
	private function get_destination_country_code( array $package ): string {
		$country = $package['destination']['country'] ?? '';

		return strtoupper( sanitize_text_field( (string) $country ) );
	}

	private function get_destination_country_name( string $country_code ): string {
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->get_countries();

			if ( isset( $countries[ $country_code ] ) ) {
				return (string) $countries[ $country_code ];
			}
		}

		return $country_code;
	}

	/**
	 * @param array<string, mixed> $package WooCommerce package data.
	 * @return array<string, string>
	 */
	private function build_destination( array $package, string $country_code, string $country_name ): array {
		$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : array();

		return array(
			'country' => $country_name,
			'country_code' => $country_code,
			'carrier_country_id' => '',
			'city' => isset( $destination['city'] ) ? sanitize_text_field( (string) $destination['city'] ) : '',
			'carrier_city_id' => '',
			'postal_code' => isset( $destination['postcode'] ) ? sanitize_text_field( (string) $destination['postcode'] ) : '',
		);
	}

	/**
	 * @param array<string, mixed> $context Debug context.
	 */
	private function debug_log( bool $debug_enabled, string $message, array $context = array() ): void {
		if ( $debug_enabled ) {
			$this->logger->log( 'debug', $message, $context );
		}
	}
}

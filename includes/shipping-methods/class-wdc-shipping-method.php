<?php
/**
 * WooCommerce shipping method.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Shipping_Method extends WC_Shipping_Method {
	private WDC_Logger $logger;

	private WDC_Settings $wdc_settings;

	public function __construct( int $instance_id = 0 ) {
		$this->id                 = 'wdc_dynamic_delivery';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Почта России — международная доставка', 'walls-delivery-calc' );
		$this->method_description = __( 'Расчет международной доставки Почтой России через API.', 'walls-delivery-calc' );
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
		);
		$this->logger             = new WDC_Logger();
		$this->wdc_settings       = new WDC_Settings();

		$this->init();
	}

	public function init(): void {
		$this->title = __( 'Почта России — международная доставка', 'walls-delivery-calc' );
	}

	/**
	 * @param array<string, mixed> $package WooCommerce package data.
	 */
	public function calculate_shipping( $package = array() ) {
		$carrier = new WDC_Russian_Post_Carrier( $this->wdc_settings, null, null, null, null, $this->logger );
		$quote = $carrier->get_quote( is_array( $package ) ? $package : array() );

		if ( empty( $quote['success'] ) ) {
			$this->debug_log( 'Shipping quote success=false.', array( 'quote' => $quote ) );
			return;
		}

		if ( empty( $quote['rates'] ) || ! is_array( $quote['rates'] ) ) {
			$this->debug_log( 'Shipping quote returned no rates.', array( 'quote' => $quote ) );
			return;
		}

		$this->add_quote_rates( $quote );
	}

	/**
	 * Add one WooCommerce rate per normalized quote rate.
	 *
	 * @param array<string, mixed> $quote Normalized carrier quote.
	 */
	protected function add_quote_rates( array $quote ): bool {
		if ( empty( $quote['rates'] ) || ! is_array( $quote['rates'] ) ) {
			$this->debug_log( 'No rates returned for WooCommerce shipping method.', array( 'quote' => $quote ) );
			return false;
		}

		$added = false;
		$destination_country = isset( $quote['destination']['country'] ) ? (string) $quote['destination']['country'] : '';

		foreach ( $quote['rates'] as $rate ) {
			if ( ! is_array( $rate ) ) {
				continue;
			}

			$rate_id = isset( $rate['rate_id'] ) ? sanitize_key( (string) $rate['rate_id'] ) : '';
			if ( '' === $rate_id ) {
				continue;
			}

			$label = isset( $rate['label_template'] ) && '' !== (string) $rate['label_template']
				? (string) $rate['label_template']
				: (string) ( $rate['rate_title'] ?? '' );
			$label = str_replace( '{country}', $destination_country, $label );

			$this->add_rate(
				array(
					'id'        => $this->get_rate_id( $rate_id ),
					'label'     => sanitize_text_field( $label ),
					'cost'      => isset( $rate['price'] ) ? (float) $rate['price'] : 0,
					'meta_data' => $this->build_rate_meta_data( $quote, $rate, $rate_id, $label ),
				)
			);
			$added = true;
		}

		if ( ! $added ) {
			$this->debug_log( 'No valid WooCommerce rates were added.', array( 'quote' => $quote ) );
		}

		return $added;
	}

	/**
	 * Build compact WooCommerce rate meta for later order persistence.
	 *
	 * @param array<string, mixed> $quote Normalized carrier quote.
	 * @param array<string, mixed> $rate Normalized carrier rate.
	 * @return array<string, mixed>
	 */
	private function build_rate_meta_data( array $quote, array $rate, string $rate_id, string $label ): array {
		$quote_meta = isset( $quote['meta'] ) && is_array( $quote['meta'] ) ? $quote['meta'] : array();
		$rate_meta = isset( $rate['meta'] ) && is_array( $rate['meta'] ) ? $rate['meta'] : array();
		$raw = isset( $rate['raw'] ) && is_array( $rate['raw'] ) ? $rate['raw'] : array();
		if ( empty( $raw ) && isset( $quote['raw'] ) && is_array( $quote['raw'] ) ) {
			$raw = $quote['raw'];
		}

		$api_result = isset( $quote_meta['api_result'] ) && is_array( $quote_meta['api_result'] ) ? $quote_meta['api_result'] : array();
		$request_params = isset( $rate_meta['request_params'] ) && is_array( $rate_meta['request_params'] )
			? $rate_meta['request_params']
			: ( isset( $quote_meta['request_params'] ) && is_array( $quote_meta['request_params'] ) ? $quote_meta['request_params'] : array() );
		$settings = $this->wdc_settings->get();
		$service_settings = $settings['services'][ WDC_Carrier_Registry::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL ];
		$api_original_price_kop = $this->first_numeric_value( $rate_meta, $raw, array( 'api_original_price_kop', 'paynds', 'paymoneynds' ) );
		$api_original_price_rub = isset( $rate_meta['api_original_price_rub'] ) && is_numeric( $rate_meta['api_original_price_rub'] )
			? (float) $rate_meta['api_original_price_rub']
			: ( null === $api_original_price_kop ? 0 : (float) $api_original_price_kop / 100 );

		return array(
			'wdc_quote_summary' => array(
				'carrier_id' => (string) ( $quote['carrier_id'] ?? '' ),
				'carrier_title' => (string) ( $quote['carrier_title'] ?? '' ),
				'service_id' => (string) ( $quote['service_id'] ?? '' ),
				'service_title' => (string) ( $quote['service_title'] ?? '' ),
				'destination' => isset( $quote['destination'] ) && is_array( $quote['destination'] ) ? $quote['destination'] : array(),
				'weight' => isset( $quote['weight'] ) && is_array( $quote['weight'] ) ? $quote['weight'] : array(),
				'error_code' => (string) ( $quote['error_code'] ?? '' ),
				'error_message' => (string) ( $quote['error_message'] ?? '' ),
				'fallback_reason' => (string) ( $quote_meta['fallback_reason'] ?? '' ),
			),
			'wdc_rate_summary' => array(
				'rate_id' => $rate_id,
				'rate_title' => (string) ( $rate['rate_title'] ?? '' ),
				'rate_label' => sanitize_text_field( $label ),
				'delivery_method' => (string) ( $rate['delivery_method'] ?? '' ),
				'delivery_method_title' => (string) ( $rate['delivery_method_title'] ?? '' ),
				'transport_type' => (string) ( $rate['transport_type'] ?? '' ),
				'transport_type_title' => (string) ( $rate['transport_type_title'] ?? '' ),
				'tariff_type' => (string) ( $rate['tariff_type'] ?? '' ),
				'tariff_type_title' => (string) ( $rate['tariff_type_title'] ?? '' ),
				'price' => ! empty( $rate['is_fallback'] ) ? 0 : (float) ( $rate['price'] ?? 0 ),
				'currency' => (string) ( $rate['currency'] ?? '' ),
				'is_fallback' => ! empty( $rate['is_fallback'] ),
				'base_price_rub' => isset( $rate_meta['base_price_rub'] ) ? (float) $rate_meta['base_price_rub'] : 0,
				'api_original_price_kop' => null === $api_original_price_kop ? 0 : (int) $api_original_price_kop,
				'api_original_price_rub' => $api_original_price_rub,
				'formula_divider' => (float) $service_settings['formula_divider'],
				'formula_add_rub' => (float) $service_settings['formula_add_rub'],
				'items_net_total_rub' => isset( $rate_meta['items_net_total_rub'] ) ? (float) $rate_meta['items_net_total_rub'] : (float) ( $quote_meta['items_net_total_rub'] ?? 0 ),
				'shipping_discount_percent_from_items_total' => isset( $rate_meta['shipping_discount_percent_from_items_total'] ) ? (float) $rate_meta['shipping_discount_percent_from_items_total'] : (float) $service_settings['shipping_discount_percent_from_items_total'],
				'shipping_discount_amount_rub' => ! empty( $rate['is_fallback'] ) ? 0 : (int) ( $rate_meta['shipping_discount_amount_rub'] ?? 0 ),
				'shipping_price_before_items_discount_rub' => ! empty( $rate['is_fallback'] ) ? 0 : (int) ( $rate_meta['shipping_price_before_items_discount_rub'] ?? $rate['price'] ?? 0 ),
				'request_params' => $request_params,
				'cache_hit' => ! empty( $api_result['cache_hit'] ) || ! empty( $rate_meta['cache_hit'] ),
			),
			'wdc_raw_meta' => $this->build_compact_raw_meta( $raw, $api_result, $rate_meta, $quote_meta, $request_params ),
		);
	}

	/**
	 * Keep only compact diagnostic fields from Russian Post API data.
	 *
	 * @param array<string, mixed> $raw Raw API data.
	 * @param array<string, mixed> $api_result API wrapper result.
	 * @param array<string, mixed> $rate_meta Rate meta.
	 * @param array<string, mixed> $quote_meta Quote meta.
	 * @param array<string, mixed> $request_params Request query params.
	 * @return array<string, mixed>
	 */
	private function build_compact_raw_meta( array $raw, array $api_result, array $rate_meta, array $quote_meta, array $request_params ): array {
		$compact = array(
			'api_url' => (string) ( $api_result['url'] ?? $rate_meta['request_url'] ?? $quote_meta['request_url'] ?? '' ),
			'api_http_code' => isset( $api_result['http_code'] ) ? (int) $api_result['http_code'] : (int) ( $rate_meta['http_code'] ?? $quote_meta['http_code'] ?? 0 ),
			'api_error_message' => (string) ( $api_result['error_message'] ?? '' ),
		);

		foreach ( array( 'paynds', 'paymoneynds', 'transtype', 'transname', 'date', 'date-discount' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
				$compact[ $key ] = $raw[ $key ];
			} elseif ( isset( $request_params[ $key ] ) && is_scalar( $request_params[ $key ] ) ) {
				$compact[ $key ] = $request_params[ $key ];
			}
		}

		return $compact;
	}

	/**
	 * @param array<string, mixed> $primary Primary source.
	 * @param array<string, mixed> $secondary Secondary source.
	 * @param array<int, string>  $keys Candidate keys.
	 */
	private function first_numeric_value( array $primary, array $secondary, array $keys ): ?float {
		foreach ( $keys as $key ) {
			if ( isset( $primary[ $key ] ) && is_numeric( $primary[ $key ] ) ) {
				return (float) $primary[ $key ];
			}

			if ( isset( $secondary[ $key ] ) && is_numeric( $secondary[ $key ] ) ) {
				return (float) $secondary[ $key ];
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $context Debug context.
	 */
	private function debug_log( string $message, array $context = array() ): void {
		$settings = $this->wdc_settings->get();
		if ( 'yes' === $settings['debug_enabled'] ) {
			$this->logger->log( 'debug', $message, $context );
		}
	}
}

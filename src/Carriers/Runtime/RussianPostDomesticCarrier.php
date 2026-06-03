<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\RussianPost\DomesticTariffVariant;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticApiClient;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticTariffVariantResolver;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Locations\Postcodes\DaDataPostcodeClient;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class RussianPostDomesticCarrier implements CarrierAdapterInterface {
	public const KEY = RussianPostDomesticSettings::CARRIER_KEY;

	public function __construct(
		private RussianPostDomesticSettings $settings,
		private RussianPostDomesticApiClient $client,
		private RussianPostDomesticTariffVariantResolver $variants,
		private Logger $logger,
		private ?DaDataPostcodeClient $postcode_client = null,
		private ?LocationRepository $locations = null
	) {
	}

	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( self::KEY, 'Почта России', 'api', $this->settings->enabled() );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities(
			supports_quotes: true,
			supports_courier_delivery: true,
			supports_pickup_delivery: true
		);
	}

	public function supports_country( string $countryCode ): bool {
		return 'RU' === strtoupper( trim( $countryCode ) ) && $this->settings->enabled();
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		$service_key = (string) ( $request->customer_context['service_key'] ?? RussianPostDomesticSettings::PICKUP_SERVICE_KEY );
		$delivery_type = RussianPostDomesticSettings::service_delivery_type( $service_key );
		$settings = $this->settings->all( $service_key );

		if ( ! $this->supports_country( $request->country_code ?: $request->destination->country_code ) ) {
			return $this->empty_quote( $request, 'unsupported_country' );
		}
		if ( empty( $settings['enabled'] ) ) {
			return $this->empty_quote( $request, 'service_disabled' );
		}

		$display_postcode = $this->resolve_postcode( $request );
		if ( '' === $display_postcode ) {
			return $this->empty_quote( $request, 'postcode_required' );
		}
		$postcode = DeliveryType::COURIER === $delivery_type ? $this->resolve_russianpost_courier_calc_postal_code( $display_postcode ) : $display_postcode;

		$package = $request->package;
		$variants = $this->variants->variants( $settings, $delivery_type, $package->get_total_weight_g() );
		if ( array() === $variants ) {
			return $this->empty_quote( $request, 'no_enabled_tariffs' );
		}

		$rates = array();
		$skipped = array();
		foreach ( $variants as $variant ) {
			$params = $this->request_params( $settings, $variant, $postcode, $package, $request );
			$cache_key = $this->api_cache_key( $service_key, $postcode, $params );
			$api_result = $this->cached_api_result( $cache_key, $params, $service_key );
			if ( empty( $api_result['success'] ) || ! is_array( $api_result['parsed'] ?? null ) ) {
				$skipped[] = $this->skipped_variant( $variant, $params, $api_result, 'api_error' );
				$this->debug( 'Russian Post domestic variant skipped after API error.', array( 'object_code' => $variant->object_code, 'request_params' => $params, 'api_result' => $api_result ), $service_key );
				continue;
			}
			$parsed = $api_result['parsed'];
			$price_kopecks = $this->price_kopecks( $parsed );
			if ( null === $price_kopecks || $price_kopecks <= 0 ) {
				$skipped[] = $this->skipped_variant( $variant, $params, $api_result, 'empty_price' );
				$this->debug( 'Russian Post domestic variant skipped after empty price.', array( 'object_code' => $variant->object_code, 'request_params' => $params, 'api_result' => $api_result ), $service_key );
				continue;
			}

			$rates[] = $this->rate_from_result( $service_key, $delivery_type, $variant, $postcode, $params, $api_result, $parsed, $price_kopecks, $package );
		}

		return new DeliveryQuote( $this->quote_id( $request, $package, $service_key ), self::KEY, $request->destination, $package, $rates, true, array() === $rates ? 'no_tariffs_available' : '', '', false, 'api', array( 'postcode' => $display_postcode, 'tariff_postcode' => $postcode, 'service_key' => $service_key, 'skipped_tariffs' => $skipped ) );
	}

	private function empty_quote( QuoteRequest $request, string $reason ): DeliveryQuote {
		return new DeliveryQuote( $this->quote_id( $request, $request->package, $reason ), self::KEY, $request->destination, $request->package, array(), false, $reason, $reason, false, 'api', array( 'fallback_reason' => $reason ) );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,scalar>
	 */
	private function request_params( array $settings, DomesticTariffVariant $variant, string $postcode, Package $package, QuoteRequest $request ): array {
		$calculation_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $request->calculation_date ) ? $request->calculation_date : '';
		$date = '' !== $calculation_date ? str_replace( '-', '', $calculation_date ) : ( function_exists( 'wp_date' ) ? wp_date( 'Ymd' ) : gmdate( 'Ymd' ) );
		$params = array(
			'object' => $variant->object_code,
			'from' => $this->from_postcode( $settings ),
			'to' => $postcode,
			'weight' => max( 1, $package->get_total_weight_g() ),
			'date' => $date,
			'pack' => 99,
		);
		if ( $variant->requires_declared_value ) {
			$params['sumoc'] = max( 1, $request->order_total->get_kopecks() );
		}

		return $params;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function from_postcode( array $settings ): string {
		$default = $this->valid_postcode( (string) ( $settings['default_from_postcode'] ?? '' ) );
		if ( '' !== $default ) {
			return $default;
		}
		$from = is_array( $settings['from_postcodes'] ?? null ) ? $settings['from_postcodes'] : array();
		foreach ( $from as $postcode ) {
			$postcode = $this->valid_postcode( (string) $postcode );
			if ( '' !== $postcode ) {
				return $postcode;
			}
		}

		return '630005';
	}

	private function resolve_postcode( QuoteRequest $request ): string {
		$candidates = array(
			$request->destination->postcode,
			(string) ( $request->customer_context['postcode'] ?? '' ),
			(string) ( $request->customer_context['resolved_postcode'] ?? '' ),
			(string) ( $request->customer_context['selected_location_postcode'] ?? '' ),
			(string) ( $request->customer_context['city_postcode'] ?? '' ),
		);
		if ( $this->has_no_index_marker( $candidates ) ) {
			return '';
		}
		foreach ( $candidates as $candidate ) {
			$postcode = $this->valid_postcode( $candidate );
			if ( '' !== $postcode ) {
				return $postcode;
			}
		}

		return $this->enrich_postcode( $request );
	}

	/**
	 * @param array<int,string> $candidates
	 */
	private function has_no_index_marker( array $candidates ): bool {
		foreach ( $candidates as $candidate ) {
			$digits = preg_replace( '/\D+/', '', $candidate ) ?? '';
			if ( '999999999' === $digits ) {
				return true;
			}
		}

		return false;
	}

	private function enrich_postcode( QuoteRequest $request ): string {
		if ( ! $this->postcode_client instanceof DaDataPostcodeClient ) {
			return '';
		}
		$fias_id = trim( (string) ( $request->destination->fias_id ?: ( $request->customer_context['selected_location_fias_id'] ?? $request->customer_context['fias_id'] ?? '' ) ) );
		if ( '' === $fias_id ) {
			return '';
		}
		$city = trim( (string) ( $request->destination->settlement ?: $request->destination->city ?: ( $request->customer_context['city'] ?? $request->customer_context['city_name'] ?? $request->customer_context['display_name'] ?? '' ) ) );
		$result = $this->postcode_client->find_postal_code(
			array(
				'fias_id' => $fias_id,
				'city_name' => $city,
				'settlement_name' => (string) ( $request->destination->settlement ?: $city ),
				'place_name' => (string) ( $request->destination->settlement ?: $city ),
				'display_name' => $city,
			)
		);
		if ( empty( $result['success'] ) ) {
			return '';
		}

		return $this->valid_postcode( (string) ( $result['postal_code'] ?? '' ) );
	}

	private function valid_postcode( string $postcode ): string {
		$postcode = preg_replace( '/\D+/', '', $postcode ) ?? '';
		if ( '' === $postcode || '999999999' === $postcode ) {
			return '';
		}

		return preg_match( '/^\d{6}$/', $postcode ) ? $postcode : '';
	}

	private function resolve_russianpost_courier_calc_postal_code( string $postal_code ): string {
		if ( ! $this->locations instanceof LocationRepository ) {
			return $postal_code;
		}

		$resolved = $this->locations->resolve_russianpost_courier_calc_postal_code_for_checkout_postcode( $postal_code );

		return '' !== $resolved ? $resolved : $postal_code;
	}

	/**
	 * @param array<string,scalar> $params
	 * @return array<string,mixed>
	 */
	private function cached_api_result( string $cache_key, array $params, string $service_key ): array {
		$settings = $this->settings->all( $service_key );
		$cached = function_exists( 'get_transient' ) ? get_transient( $cache_key ) : false;
		if ( is_array( $cached ) ) {
			$cached['cache_hit'] = true;
			return $cached;
		}

		$result = $this->client->calculate_tariff( $params, $service_key );
		$result['cache_hit'] = false;
		if ( ! empty( $result['success'] ) && ! empty( $settings['cache_until_end_of_day'] ) && function_exists( 'set_transient' ) ) {
			set_transient( $cache_key, $result, $this->seconds_until_end_of_day() );
		}

		return $result;
	}

	/**
	 * @param array<string,scalar> $params
	 */
	private function api_cache_key( string $service_key, string $postcode, array $params ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $params ) : json_encode( $params );

		return 'wdc_rp_domestic_' . sha1( implode( '|', array( self::KEY, $service_key, $postcode, is_string( $json ) ? $json : '' ) ) );
	}

	private function seconds_until_end_of_day(): int {
		if ( function_exists( 'wp_timezone' ) ) {
			$now = new \DateTimeImmutable( 'now', wp_timezone() );
			$end = $now->setTime( 23, 59, 59 );

			return max( 1, $end->getTimestamp() - $now->getTimestamp() );
		}

		$end = strtotime( 'today 23:59:59' );

		return is_int( $end ) ? max( 1, $end - time() ) : 86400;
	}

	/**
	 * @param array<string,mixed> $parsed
	 */
	private function price_kopecks( array $parsed ): ?int {
		foreach ( array( 'paynds', 'pay' ) as $key ) {
			if ( isset( $parsed[ $key ] ) && is_numeric( $parsed[ $key ] ) ) {
				return (int) $parsed[ $key ];
			}
		}

		return null;
	}

	/**
	 * @param array<string,scalar> $params
	 * @param array<string,mixed>  $api_result
	 * @param array<string,mixed>  $parsed
	 */
	private function rate_from_result( string $service_key, string $delivery_type, DomesticTariffVariant $variant, string $postcode, array $params, array $api_result, array $parsed, int $price_kopecks, Package $package ): DeliveryRate {
		$min = is_numeric( $parsed['delivery_min_days'] ?? null ) ? (int) $parsed['delivery_min_days'] : null;
		$max = is_numeric( $parsed['delivery_max_days'] ?? null ) ? (int) $parsed['delivery_max_days'] : $min;
		$range = DateRange::range( $min, $max, DateRange::UNIT_CALENDAR_DAYS );
		$meta = array(
			'tariff_selector_group' => true,
			'selected_tariff_object' => $variant->object_code,
			'selected_tariff_title' => $variant->title,
			'object_code' => $variant->object_code,
			'domestic_tariff_variant' => $this->public_variant_data( $variant ),
			'postcode' => $postcode,
			'pay' => $parsed['pay'] ?? null,
			'nds' => $parsed['nds'] ?? null,
			'paynds' => $parsed['paynds'] ?? null,
			'delivery_min_days' => $min,
			'delivery_max_days' => $max,
			'transtype' => $parsed['transtype'] ?? null,
			'delivery_to' => $parsed['delivery_to'] ?? '',
			'items_summary' => is_array( $parsed['items_summary'] ?? null ) ? $parsed['items_summary'] : array(),
			'request_params' => $params,
			'http_code' => (int) ( $api_result['http_code'] ?? 0 ),
			'cache_hit' => ! empty( $api_result['cache_hit'] ),
			'api_base_price_rub' => $price_kopecks / 100,
			'api_price_with_vat_rub' => $price_kopecks / 100,
			'api_price_has_vat' => null !== ( $parsed['paynds'] ?? null ),
			'package' => $package->to_array(),
		);

		return new DeliveryRate(
			$service_key . ':' . $variant->object_code,
			self::KEY,
			'Почта России',
			$service_key,
			RussianPostDomesticSettings::TITLE,
			(string) $variant->object_code,
			$variant->title,
			$delivery_type,
			$variant->title,
			Money::from_kopecks( $price_kopecks ),
			null,
			null,
			$range,
			'',
			$this->delivery_comment( $range ),
			array(),
			false,
			'',
			DeliveryType::PICKUP === $delivery_type,
			DeliveryType::COURIER === $delivery_type,
			$meta
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function public_variant_data( DomesticTariffVariant $variant ): array {
		$data = $variant->to_array();
		unset( $data['admin_comment'] );

		return $data;
	}

	private function delivery_comment( DateRange $range ): string {
		return DeliveryDaysFormatter::format( $range );
	}

	/**
	 * @param array<string,scalar> $params
	 * @param array<string,mixed>  $api_result
	 * @return array<string,mixed>
	 */
	private function skipped_variant( DomesticTariffVariant $variant, array $params, array $api_result, string $reason ): array {
		$raw = is_array( $api_result['raw'] ?? null ) ? $api_result['raw'] : array();

		return array_filter(
			array(
				'object_code' => $variant->object_code,
				'title' => $variant->title,
				'reason' => $reason,
				'request_params' => $params,
				'request_url' => (string) ( $api_result['url'] ?? '' ),
				'http_code' => (int) ( $api_result['http_code'] ?? 0 ),
				'error_code' => (string) ( $api_result['error_code'] ?? '' ),
				'error_message' => (string) ( $api_result['error_message'] ?? '' ),
				'errorcode' => $raw['errorcode'] ?? null,
				'errormsg' => $raw['errormsg'] ?? null,
				'api_errorcode' => $raw['errorcode'] ?? null,
				'api_errormsg' => $raw['errormsg'] ?? null,
				'raw_error_body' => (string) ( $raw['raw_body'] ?? $raw['body'] ?? '' ),
				'decoded_error_body' => is_array( $raw['decoded_body'] ?? null ) ? $raw['decoded_body'] : array(),
			),
			static fn ( mixed $value ): bool => null !== $value && '' !== $value && array() !== $value
		);
	}

	private function quote_id( QuoteRequest $request, Package $package, string $salt ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( array( $request->to_array(), $package->to_array(), $salt ) ) : json_encode( array( $request->to_array(), $package->to_array(), $salt ) );

		return self::KEY . '-' . substr( sha1( is_string( $json ) ? $json : '' ), 0, 12 );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function debug( string $message, array $context = array(), string $service_key = '' ): void {
		if ( $this->settings->debug_enabled( $service_key ) ) {
			$this->logger->debug( $message, $context );
		}
	}
}

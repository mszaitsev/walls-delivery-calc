<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdParcelBuilder;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffCalculationService;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class DpdQuoteCarrier implements CarrierAdapterInterface {
	public const KEY = DpdSettings::CARRIER_KEY;

	public function __construct(
		private DpdSettings $settings,
		private DpdTariffCalculationService $tariffs,
		private DpdParcelBuilder $parcels,
		private Logger $logger
	) {
	}

	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( self::KEY, DpdSettings::TITLE, 'api', $this->settings->credentials_are_complete() );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities(
			supports_quotes: true,
			supports_courier_delivery: true,
			supports_pickup_delivery: true
		);
	}

	public function supports_country( string $countryCode ): bool {
		return 'RU' === strtoupper( trim( $countryCode ) ) && $this->settings->credentials_are_complete();
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		$delivery_type = $this->normalize_delivery_type( (string) ( $request->customer_context['delivery_type'] ?? DeliveryType::PICKUP ) );
		if ( DeliveryType::COURIER === $delivery_type && ! $this->settings->runtime_courier_rates_enabled() ) {
			return $this->empty_quote( $request, 'courier_rates_disabled', array(), array(), array(), $delivery_type );
		}
		if ( ! $this->supports_country( $request->country_code ?: $request->destination->country_code ) ) {
			return $this->empty_quote( $request, 'unsupported_or_credentials_missing', array(), array(), array(), $delivery_type );
		}

		$receiver_location_id = $this->receiver_location_id( $request );
		if ( $receiver_location_id <= 0 ) {
			return $this->empty_quote( $request, 'receiver_location_id_required', array(), array(), array(), $delivery_type );
		}

		$parcel_build = $this->parcels->build( $request );
		$params = $this->tariff_params( $parcel_build, $delivery_type );
		$result = $this->tariffs->calculate( $receiver_location_id, $params );
		if ( ! $result->success ) {
			$this->logger->warning(
				'DPD checkout quote returned empty.',
				array(
					'reason' => 'tariff_calculation_failed',
					'errors' => $result->errors,
					'receiver_location_id' => $receiver_location_id,
				)
			);

			return $this->empty_quote( $request, 'tariff_calculation_failed', array( 'errors' => $result->errors ), $params, $result->meta, $delivery_type );
		}

		$enabled = $this->enabled_codes();
		$rates = array();
		$skipped_disallowed = 0;
		$skipped_no_cost = 0;
		if ( array() === $enabled ) {
			return $this->empty_quote(
				$request,
				'no_enabled_service_codes',
				array( 'raw_count' => count( $result->options ) ),
				$params,
				$result->meta,
				$delivery_type
			);
		}
		foreach ( $result->options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$code = strtoupper( trim( (string) ( $option['service_code'] ?? '' ) ) );
			if ( '' === $code ) {
				continue;
			}
			if ( ! isset( $enabled[ $code ] ) ) {
				++$skipped_disallowed;
				continue;
			}
			$rate = $this->rate_from_option( $request, $delivery_type, $option, $result->payload, $result->meta );
			if ( ! $rate instanceof DeliveryRate ) {
				++$skipped_no_cost;
				continue;
			}
			$rates[] = $rate;
		}
		$filter_result = $this->filter_rates_by_price_and_delivery_days( $rates );
		$rates = $filter_result['rates'];
		$removed_by_filter = $filter_result['removed'];

		if ( array() === $rates ) {
			$this->logger->warning(
				'DPD checkout quote returned empty.',
				array(
					'reason' => 'no_tariff_options_available',
					'raw_count' => count( $result->options ),
					'skipped_disallowed_count' => $skipped_disallowed,
					'skipped_no_cost_count' => $skipped_no_cost,
					'filter_removed_count' => count( $removed_by_filter ),
				)
			);
		}

		return new DeliveryQuote(
			$this->quote_id( $request, 'checkout', $params, $result->meta, $delivery_type ),
			self::KEY,
			$request->destination,
			$request->package,
			$rates,
			array() !== $rates,
			array() === $rates ? 'no_tariff_options_available' : '',
			'',
			false,
			'api',
			array(
				'receiver_location_id' => $receiver_location_id,
				'sender_city_id' => (string) ( $result->meta['sender_city_id'] ?? '' ),
				'receiver_city_id' => (string) ( $result->meta['receiver_city_id'] ?? '' ),
				'enabled_service_codes' => array_keys( $enabled ),
				'delivery_type' => $delivery_type,
				'parcels_count' => (int) ( $parcel_build['parcels_count'] ?? count( $params['parcels'] ?? array() ) ),
				'total_weight_g' => (int) ( $parcel_build['total_weight_g'] ?? 0 ),
				'dimensions' => is_array( $parcel_build['dimensions'] ?? null ) ? $parcel_build['dimensions'] : array(),
				'declared_value_rub' => (float) ( $parcel_build['declared_value_rub'] ?? 0 ),
				'package_builder_source' => (string) ( $parcel_build['package_builder_source'] ?? '' ),
				'dpd_filter_removed_count' => count( $removed_by_filter ),
				'dpd_filter_removed_tariffs' => $removed_by_filter,
			)
		);
	}

	/**
	 * @param array<string,mixed> $diagnostics
	 */
	private function empty_quote( QuoteRequest $request, string $reason, array $diagnostics = array(), array $params = array(), array $meta = array(), string $delivery_type = DeliveryType::PICKUP ): DeliveryQuote {
		$this->logger->warning( 'DPD checkout quote returned empty.', array_merge( array( 'reason' => $reason ), $diagnostics ) );

		return new DeliveryQuote( $this->quote_id( $request, $reason, $params, $meta, $delivery_type ), self::KEY, $request->destination, $request->package, array(), false, $reason, $reason, false, 'api', array_merge( array( 'fallback_reason' => $reason, 'delivery_type' => $delivery_type ), $diagnostics ) );
	}

	/**
	 * @return array<string,bool>
	 */
	private function enabled_codes(): array {
		$enabled = array();
		foreach ( $this->settings->runtime_enabled_service_codes() as $code ) {
			$enabled[ strtoupper( $code ) ] = true;
		}

		return $enabled;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function tariff_params( array $parcel_build, string $delivery_type ): array {
		return array(
			'parcels' => is_array( $parcel_build['parcels'] ?? null ) ? $parcel_build['parcels'] : array(),
			'declared_value_rub' => (float) ( $parcel_build['declared_value_rub'] ?? $this->settings->tariff_default_declared_value_rub() ),
			'self_pickup' => true,
			'self_delivery' => DeliveryType::PICKUP === $delivery_type,
			'package_builder_source' => (string) ( $parcel_build['package_builder_source'] ?? '' ),
			'parcels_count' => (int) ( $parcel_build['parcels_count'] ?? 0 ),
			'total_weight_g' => (int) ( $parcel_build['total_weight_g'] ?? 0 ),
			'dimensions' => is_array( $parcel_build['dimensions'] ?? null ) ? $parcel_build['dimensions'] : array(),
			'box_limit' => is_array( $parcel_build['box_limit'] ?? null ) ? $parcel_build['box_limit'] : array(),
		);
	}

	private function receiver_location_id( QuoteRequest $request ): int {
		$value = $request->customer_context['selected_location_id'] ?? $request->customer_context['location_id'] ?? 0;

		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * @param array<string,mixed> $option
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $meta
	 */
	private function rate_from_option( QuoteRequest $request, string $delivery_type, array $option, array $payload, array $meta ): ?DeliveryRate {
		if ( ! is_numeric( $option['cost'] ?? null ) ) {
			return null;
		}
		$cost = (float) $option['cost'];
		if ( $cost <= 0 ) {
			return null;
		}

		$code = strtoupper( trim( (string) ( $option['service_code'] ?? '' ) ) );
		$service_name = trim( (string) ( $option['service_name'] ?? '' ) );
		$tariff_name = $this->tariff_title( $code, $service_name );
		$range = DateRange::range(
			is_numeric( $option['delivery_period_min'] ?? null ) ? (int) $option['delivery_period_min'] : ( is_numeric( $option['days'] ?? null ) ? (int) $option['days'] : null ),
			is_numeric( $option['delivery_period_max'] ?? null ) ? (int) $option['delivery_period_max'] : ( is_numeric( $option['days'] ?? null ) ? (int) $option['days'] : null ),
			DateRange::UNIT_CALENDAR_DAYS
		);
		$days = DeliveryDaysFormatter::format( $range );
		$method_title = DeliveryType::PICKUP === $delivery_type ? $this->settings->runtime_pickup_title() : $this->settings->runtime_courier_title();
		$title = $this->method_title_from_parts( $method_title, $tariff_name, $days );
		$requires_pickup_point = false;
		$requires_courier_address = DeliveryType::COURIER === $delivery_type;

		return new DeliveryRate(
			rate_id: $this->checkout_group_id( $delivery_type ) . ':' . strtolower( preg_replace( '/[^A-Z0-9_\-]+/', '', $code ) ?? $code ),
			carrier_key: self::KEY,
			carrier_name: DpdSettings::TITLE,
			service_key: DpdSettings::SERVICE_KEY,
			service_name: DpdSettings::TITLE,
			tariff_key: $code,
			tariff_name: $tariff_name,
			delivery_type: $delivery_type,
			title: $title,
			price: Money::from_rubles( $cost, (string) ( $option['currency'] ?? 'RUB' ) ),
			original_price: null,
			crossed_price: null,
			delivery_days: $range,
			planned_delivery_date: (string) ( $option['delivery_date'] ?? '' ),
			planned_delivery_comment: $days,
			comments: array(),
			disabled: false,
			disabled_reason: '',
			requires_pickup_point: $requires_pickup_point,
			requires_courier_address: $requires_courier_address,
			meta: array(
				'tariff_selector_group' => true,
				'checkout_group_id' => $this->checkout_group_id( $delivery_type ),
				'pickup_method_title' => $this->settings->runtime_pickup_title(),
				'courier_method_title' => $this->settings->runtime_courier_title(),
				'carrier_key' => self::KEY,
				'service_key' => DpdSettings::SERVICE_KEY,
				'delivery_type' => $delivery_type,
				'tariff_code' => $code,
				'tariff_name' => $tariff_name,
				'selected_tariff_object' => $code,
				'selected_tariff_title' => $tariff_name,
				'api_base_price_rub' => $cost,
				'api_price_with_vat_rub' => $cost,
				'api_delivery_days_min' => $range->min_days,
				'api_delivery_days_max' => $range->max_days,
				'api_delivery_days_text' => $days,
				'delivery_min_days' => $range->min_days,
				'delivery_max_days' => $range->max_days,
				'dpd_service_code' => $code,
				'dpd_service_name' => $service_name,
				'dpd_sender_city_id' => (string) ( $meta['sender_city_id'] ?? '' ),
				'dpd_receiver_city_id' => (string) ( $meta['receiver_city_id'] ?? '' ),
				'dpd_self_pickup' => true,
				'dpd_self_delivery' => DeliveryType::PICKUP === $delivery_type,
				'dpd_courier_rates_enabled' => $this->settings->runtime_courier_rates_enabled(),
				'dpd_pickup_points_not_implemented' => DeliveryType::PICKUP === $delivery_type,
				'request_payload_sanitized' => $payload,
				'response_tariff_sanitized' => $this->sanitize_option( $option ),
				'package' => array(
					'parcels_count' => count( is_array( $payload['parcel'] ?? null ) ? $payload['parcel'] : array() ),
					'parcels' => is_array( $payload['parcel'] ?? null ) ? $payload['parcel'] : array(),
					'declared_value_rub' => (float) ( $payload['declaredValue'] ?? 0 ),
				),
			)
		);
	}

	private function tariff_title( string $code, string $service_name ): string {
		$title = $this->settings->runtime_tariff_title( $code, $service_name );
		if ( '' !== trim( $title ) ) {
			return trim( $title );
		}

		return '' !== trim( $service_name ) ? trim( $service_name ) : $code;
	}

	private function method_title_from_parts( string $method_title, string $tariff_title, string $days ): string {
		$title = trim( $method_title );
		$tariff_title = trim( $tariff_title );
		if ( '' !== $tariff_title && ! str_contains( $title, $tariff_title ) ) {
			$title = '' !== $title ? $title . ', ' . $tariff_title : $tariff_title;
		}
		$days = trim( $days );
		if ( '' !== $days && ! str_contains( $title, $days ) ) {
			$title = '' !== $title ? $title . ' - ' . $days : $days;
		}

		return $title;
	}

	private function checkout_group_id( string $delivery_type ): string {
		return self::KEY . ':' . $delivery_type;
	}

	/**
	 * @param array<int,DeliveryRate> $rates
	 * @return array{rates:array<int,DeliveryRate>,removed:array<int,array<string,mixed>>}
	 */
	private function filter_rates_by_price_and_delivery_days( array $rates ): array {
		$remove = array();
		$removed = array();
		foreach ( $rates as $index => $rate ) {
			foreach ( $rates as $candidate_index => $candidate ) {
				if ( $index === $candidate_index || isset( $remove[ $index ] ) ) {
					continue;
				}
				$reason = $this->dpd_filter_removal_reason( $candidate, $rate );
				if ( '' === $reason ) {
					continue;
				}
				$remove[ $index ] = true;
				$removed[] = array(
					'tariff_key' => $rate->tariff_key,
					'tariff_name' => $rate->tariff_name,
					'price_rub' => $rate->price->get_rubles(),
					'delivery_min_days' => $rate->delivery_days->min_days,
					'delivery_max_days' => $rate->delivery_days->max_days,
					'removed_by_tariff_key' => $candidate->tariff_key,
					'removed_by_price_rub' => $candidate->price->get_rubles(),
					'removed_by_delivery_min_days' => $candidate->delivery_days->min_days,
					'removed_by_delivery_max_days' => $candidate->delivery_days->max_days,
					'reason' => $reason,
				);
			}
		}

		if ( array() === $remove ) {
			return array( 'rates' => array_values( $rates ), 'removed' => array() );
		}

		return array(
			'rates' => array_values( array_filter( $rates, static fn ( DeliveryRate $rate, int $index ): bool => ! isset( $remove[ $index ] ), ARRAY_FILTER_USE_BOTH ) ),
			'removed' => $removed,
		);
	}

	private function dpd_filter_removal_reason( DeliveryRate $candidate, DeliveryRate $rate ): string {
		$candidate_min = $candidate->delivery_days->min_days;
		$candidate_max = $candidate->delivery_days->max_days;
		$rate_min = $rate->delivery_days->min_days;
		$rate_max = $rate->delivery_days->max_days;
		if ( null === $candidate_min || null === $candidate_max || null === $rate_min || null === $rate_max ) {
			return '';
		}

		$candidate_price = $candidate->price->get_kopecks();
		$rate_price = $rate->price->get_kopecks();
		if ( $candidate_min === $rate_min && $candidate_max === $rate_max && $candidate_price < $rate_price ) {
			return 'same_delivery_days_higher_price';
		}
		if (
			$candidate_min <= $rate_min
			&& $candidate_max <= $rate_max
			&& $candidate_price <= $rate_price
			&& ( $candidate_min < $rate_min || $candidate_max < $rate_max || $candidate_price < $rate_price )
		) {
			return 'dominated_by_price_and_delivery_days';
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $option
	 * @return array<string,mixed>
	 */
	private function sanitize_option( array $option ): array {
		return array_intersect_key(
			$option,
			array(
				'service_code' => true,
				'service_name' => true,
				'cost' => true,
				'currency' => true,
				'days' => true,
				'delivery_period_min' => true,
				'delivery_period_max' => true,
				'pickup_date' => true,
				'delivery_date' => true,
				'self_pickup' => true,
				'self_delivery' => true,
			)
		);
	}

	private function quote_id( QuoteRequest $request, string $suffix, array $params = array(), array $meta = array(), string $delivery_type = DeliveryType::PICKUP ): string {
		$delivery_type = $this->normalize_delivery_type( $delivery_type );
		$diagnostics = array(
			'country_code' => $request->country_code,
			'selected_location_id' => (string) ( $request->customer_context['selected_location_id'] ?? $request->customer_context['location_id'] ?? '' ),
			'sender_city_id' => (string) ( $meta['sender_city_id'] ?? $this->settings->tariff_sender_dpd_city_id() ),
			'receiver_city_id' => (string) ( $meta['receiver_city_id'] ?? '' ),
			'parcels' => $this->parcel_signature( is_array( $params['parcels'] ?? null ) ? $params['parcels'] : array() ),
			'parcels_count' => (int) ( $params['parcels_count'] ?? 0 ),
			'total_weight_g' => (int) ( $params['total_weight_g'] ?? $request->package->get_total_weight_g() ),
			'dimensions' => is_array( $params['dimensions'] ?? null ) ? $params['dimensions'] : array(),
			'declared_value_rub' => (float) ( $params['declared_value_rub'] ?? $this->settings->tariff_default_declared_value_rub() ),
			'package_builder_source' => (string) ( $params['package_builder_source'] ?? '' ),
			'delivery_type' => $delivery_type,
			'self_pickup' => true,
			'self_delivery' => DeliveryType::PICKUP === $delivery_type,
			'enable_courier_rates' => $this->settings->runtime_courier_rates_enabled(),
			'enabled_service_codes' => $this->settings->runtime_enabled_service_codes(),
			'calculation_date' => $request->calculation_date,
			'environment' => $this->settings->environment(),
			'suffix' => $suffix,
		);
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $diagnostics ) : json_encode( $diagnostics );

		return self::KEY . ':' . sha1( is_string( $json ) ? $json : '' );
	}

	private function normalize_delivery_type( string $delivery_type ): string {
		return DeliveryType::COURIER === $delivery_type ? DeliveryType::COURIER : DeliveryType::PICKUP;
	}

	/**
	 * @param array<int,mixed> $parcels
	 * @return array<int,array<string,mixed>>
	 */
	private function parcel_signature( array $parcels ): array {
		$signature = array();
		foreach ( $parcels as $parcel ) {
			if ( is_object( $parcel ) ) {
				$signature[] = get_object_vars( $parcel );
			} elseif ( is_array( $parcel ) ) {
				$signature[] = $parcel;
			}
		}

		return $signature;
	}
}

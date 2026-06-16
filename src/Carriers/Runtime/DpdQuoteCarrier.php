<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
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
			supports_pickup_delivery: false
		);
	}

	public function supports_country( string $countryCode ): bool {
		return 'RU' === strtoupper( trim( $countryCode ) ) && $this->settings->credentials_are_complete();
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		if ( ! $this->supports_country( $request->country_code ?: $request->destination->country_code ) ) {
			return $this->empty_quote( $request, 'unsupported_or_credentials_missing' );
		}

		$receiver_location_id = $this->receiver_location_id( $request );
		if ( $receiver_location_id <= 0 ) {
			return $this->empty_quote( $request, 'receiver_location_id_required' );
		}

		$params = $this->tariff_params( $request );
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

			return $this->empty_quote( $request, 'tariff_calculation_failed', array( 'errors' => $result->errors ) );
		}

		$allowed = $this->allowed_codes();
		$rates = array();
		$skipped_disallowed = 0;
		$skipped_no_cost = 0;
		foreach ( $result->options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$code = strtoupper( trim( (string) ( $option['service_code'] ?? '' ) ) );
			if ( '' === $code ) {
				continue;
			}
			if ( array() !== $allowed && ! isset( $allowed[ $code ] ) ) {
				++$skipped_disallowed;
				continue;
			}
			$rate = $this->rate_from_option( $request, $option, $result->payload, $result->meta );
			if ( ! $rate instanceof DeliveryRate ) {
				++$skipped_no_cost;
				continue;
			}
			$rates[] = $rate;
		}

		if ( array() === $rates ) {
			$this->logger->warning(
				'DPD checkout quote returned empty.',
				array(
					'reason' => 'no_tariff_options_available',
					'raw_count' => count( $result->options ),
					'skipped_disallowed_count' => $skipped_disallowed,
					'skipped_no_cost_count' => $skipped_no_cost,
				)
			);
		}

		return new DeliveryQuote(
			$this->quote_id( $request, 'checkout' ),
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
				'allowed_service_codes' => array_keys( $allowed ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $diagnostics
	 */
	private function empty_quote( QuoteRequest $request, string $reason, array $diagnostics = array() ): DeliveryQuote {
		$this->logger->warning( 'DPD checkout quote returned empty.', array_merge( array( 'reason' => $reason ), $diagnostics ) );

		return new DeliveryQuote( $this->quote_id( $request, $reason ), self::KEY, $request->destination, $request->package, array(), false, $reason, $reason, false, 'api', array_merge( array( 'fallback_reason' => $reason ), $diagnostics ) );
	}

	/**
	 * @return array<string,bool>
	 */
	private function allowed_codes(): array {
		$allowed = array();
		foreach ( $this->settings->runtime_allowed_service_codes() as $code ) {
			$allowed[ strtoupper( $code ) ] = true;
		}

		return $allowed;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function tariff_params( QuoteRequest $request ): array {
		$pickup_mode = $this->settings->runtime_pickup_mode();

		return array(
			'weight_g' => max( 1, $request->package->get_total_weight_g(), $this->settings->tariff_default_weight_g() ),
			'length_cm' => $this->dimension_or_default( $request->package->length_cm, $this->settings->tariff_default_length_cm() ),
			'width_cm' => $this->dimension_or_default( $request->package->width_cm, $this->settings->tariff_default_width_cm() ),
			'height_cm' => $this->dimension_or_default( $request->package->height_cm, $this->settings->tariff_default_height_cm() ),
			'declared_value_rub' => $this->declared_value_rub( $request ),
			'self_pickup' => 'terminal' === $pickup_mode,
			'self_delivery' => false,
		);
	}

	private function declared_value_rub( QuoteRequest $request ): float {
		$declared = $request->package->declared_value->get_rubles();
		if ( $declared > 0 ) {
			return $declared;
		}
		$total = $request->order_total->get_rubles();
		if ( $total > 0 ) {
			return $total;
		}

		return $this->settings->tariff_default_declared_value_rub();
	}

	private function dimension_or_default( ?int $value, float $default ): float {
		return null !== $value && $value > 0 ? (float) $value : $default;
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
	private function rate_from_option( QuoteRequest $request, array $option, array $payload, array $meta ): ?DeliveryRate {
		if ( ! is_numeric( $option['cost'] ?? null ) ) {
			return null;
		}
		$cost = (float) $option['cost'];
		if ( $cost <= 0 ) {
			return null;
		}

		$code = strtoupper( trim( (string) ( $option['service_code'] ?? '' ) ) );
		$service_name = trim( (string) ( $option['service_name'] ?? '' ) );
		$prefix = $this->settings->runtime_method_title_prefix();
		$tariff_name = '' !== $service_name ? $this->title_with_prefix( $prefix, $service_name ) : trim( $prefix . ' ' . $code );
		$range = DateRange::range(
			is_numeric( $option['delivery_period_min'] ?? null ) ? (int) $option['delivery_period_min'] : ( is_numeric( $option['days'] ?? null ) ? (int) $option['days'] : null ),
			is_numeric( $option['delivery_period_max'] ?? null ) ? (int) $option['delivery_period_max'] : ( is_numeric( $option['days'] ?? null ) ? (int) $option['days'] : null ),
			DateRange::UNIT_CALENDAR_DAYS
		);
		$days = DeliveryDaysFormatter::format( $range );

		return new DeliveryRate(
			rate_id: self::KEY . ':' . strtolower( preg_replace( '/[^A-Z0-9_\-]+/', '', $code ) ?? $code ),
			carrier_key: self::KEY,
			carrier_name: DpdSettings::TITLE,
			service_key: DpdSettings::SERVICE_KEY,
			service_name: DpdSettings::TITLE,
			tariff_key: $code,
			tariff_name: $tariff_name,
			delivery_type: DeliveryType::COURIER,
			title: $tariff_name,
			price: Money::from_rubles( $cost, (string) ( $option['currency'] ?? 'RUB' ) ),
			original_price: null,
			crossed_price: null,
			delivery_days: $range,
			planned_delivery_date: (string) ( $option['delivery_date'] ?? '' ),
			planned_delivery_comment: $days,
			comments: array(),
			disabled: false,
			disabled_reason: '',
			requires_pickup_point: false,
			requires_courier_address: true,
			meta: array(
				'preserve_rate_title' => true,
				'carrier_key' => self::KEY,
				'service_key' => DpdSettings::SERVICE_KEY,
				'delivery_type' => DeliveryType::COURIER,
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
				'dpd_runtime_pickup_mode' => $this->settings->runtime_pickup_mode(),
				'dpd_runtime_delivery_mode' => 'door',
				'request_payload_sanitized' => $payload,
				'response_tariff_sanitized' => $this->sanitize_option( $option ),
				'package' => array(
					'weight_g' => (int) ( $payload['parcel'][0]['weight'] ?? 0 ),
					'dimensions_cm' => array(
						'length' => (float) ( $payload['parcel'][0]['length'] ?? 0 ),
						'width' => (float) ( $payload['parcel'][0]['width'] ?? 0 ),
						'height' => (float) ( $payload['parcel'][0]['height'] ?? 0 ),
					),
					'declared_value_rub' => (float) ( $payload['declaredValue'] ?? 0 ),
				),
			)
		);
	}

	private function title_with_prefix( string $prefix, string $service_name ): string {
		return str_starts_with( strtolower( $service_name ), strtolower( $prefix ) )
			? $service_name
			: trim( $prefix . ' ' . $service_name );
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

	private function quote_id( QuoteRequest $request, string $suffix ): string {
		return self::KEY . ':' . sha1( $request->country_code . '|' . (string) ( $request->customer_context['selected_location_id'] ?? '' ) . '|' . $request->package->get_total_weight_g() . '|' . $suffix );
	}
}

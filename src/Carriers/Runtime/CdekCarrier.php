<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Carriers\Cdek\CdekLocationResolver;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class CdekCarrier implements CarrierAdapterInterface {
	public const KEY = CdekSettings::CARRIER_KEY;
	public const PICKUP_TITLE = 'СДЭК до пункта выдачи';
	public const COURIER_TITLE = 'СДЭК курьер';

	public function __construct(
		private CdekSettings $settings,
		private CdekApiClient $client,
		private CdekLocationResolver $locations,
		private Logger $logger
	) {
	}

	public static function checkout_group_id( string $delivery_type ): string {
		return self::KEY . ':' . ( DeliveryType::COURIER === $delivery_type ? DeliveryType::COURIER : DeliveryType::PICKUP );
	}

	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( self::KEY, CdekSettings::TITLE, 'api', $this->settings->credentials_are_complete() );
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
		if ( ! $this->supports_country( $request->country_code ?: $request->destination->country_code ) ) {
			return $this->empty_quote( $request, 'unsupported_or_credentials_missing' );
		}
		if ( $this->settings->sender_city_code() <= 0 ) {
			return $this->empty_quote( $request, 'sender_city_code_required' );
		}

		$to = $this->locations->resolve( $request );
		if ( empty( $to['success'] ) ) {
			return $this->empty_quote( $request, 'destination_city_not_resolved', $to );
		}

		$payload = $this->tariff_payload( $request, $to );
		try {
			$result = $this->client->tariffList( $payload );
		} catch ( CdekApiException $exception ) {
			$this->logger->warning( 'CDEK tarifflist failed.', array( 'message' => $exception->getMessage(), 'delivery_type' => $delivery_type ) );
			return $this->empty_quote( $request, 'api_error', array( 'message' => $exception->getMessage() ) );
		}

		$rates = array();
		foreach ( $this->tariffs_from_response( $result ) as $tariff ) {
			$type = $this->classify_delivery_type( $tariff );
			if ( $type !== $delivery_type ) {
				continue;
			}
			$rate = $this->rate_from_tariff( $request, $type, $tariff, $payload, $result, $to );
			if ( $rate instanceof DeliveryRate ) {
				$rates[] = $rate;
			}
		}

		return new DeliveryQuote(
			$this->quote_id( $request, $delivery_type ),
			self::KEY,
			$request->destination,
			$request->package,
			$rates,
			true,
			array() === $rates ? 'no_tariffs_available' : '',
			'',
			false,
			'api',
			array( 'delivery_type' => $delivery_type, 'location' => $to )
		);
	}

	/**
	 * @param array<string,mixed> $diagnostics
	 */
	private function empty_quote( QuoteRequest $request, string $reason, array $diagnostics = array() ): DeliveryQuote {
		return new DeliveryQuote( $this->quote_id( $request, $reason ), self::KEY, $request->destination, $request->package, array(), false, $reason, $reason, false, 'api', array_merge( array( 'fallback_reason' => $reason ), $diagnostics ) );
	}

	/**
	 * @param array<string,mixed> $to
	 * @return array<string,mixed>
	 */
	private function tariff_payload( QuoteRequest $request, array $to ): array {
		$dimensions = $this->dimensions( $request->package );
		$from = array( 'code' => $this->settings->sender_city_code() );
		$sender_postal_code = $this->settings->sender_postal_code();
		if ( '' !== $sender_postal_code ) {
			$from['postal_code'] = $sender_postal_code;
		}
		$to_location = array( 'code' => (int) $to['city_code'] );
		if ( '' !== trim( $request->destination->postcode ) ) {
			$to_location['postal_code'] = preg_replace( '/\D+/', '', $request->destination->postcode ) ?: $request->destination->postcode;
		}

		return array(
			'type' => 1,
			'currency' => 'RUB',
			'from_location' => $from,
			'to_location' => $to_location,
			'packages' => array(
				array(
					'weight' => max( 1, $request->package->get_total_weight_g() ),
					'length' => $dimensions['length'],
					'width' => $dimensions['width'],
					'height' => $dimensions['height'],
				),
			),
		);
	}

	/**
	 * @return array{length:int,width:int,height:int}
	 */
	private function dimensions( Package $package ): array {
		$defaults = $this->settings->default_package_dimensions_cm();

		return array(
			'length' => max( 1, (int) ( $package->length_cm ?: $defaults['length'] ) ),
			'width' => max( 1, (int) ( $package->width_cm ?: $defaults['width'] ) ),
			'height' => max( 1, (int) ( $package->height_cm ?: $defaults['height'] ) ),
		);
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<int,array<string,mixed>>
	 */
	private function tariffs_from_response( array $result ): array {
		$body = is_array( $result['body'] ?? null ) ? $result['body'] : array();
		$tariffs = is_array( $body['tariff_codes'] ?? null ) ? $body['tariff_codes'] : array();

		return array_values( array_filter( $tariffs, 'is_array' ) );
	}

	/**
	 * @param array<string,mixed> $tariff
	 */
	private function classify_delivery_type( array $tariff ): string {
		$mode = (int) ( $tariff['delivery_mode'] ?? ( is_array( $tariff['result'] ?? null ) ? ( $tariff['result']['delivery_mode'] ?? 0 ) : 0 ) );
		// CDEK delivery_mode: 1 warehouse-warehouse, 2 warehouse-door, 3 door-warehouse, 4 door-door.
		return match ( $mode ) {
			1, 3 => DeliveryType::PICKUP,
			2, 4 => DeliveryType::COURIER,
			default => DeliveryType::UNKNOWN,
		};
	}

	/**
	 * @param array<string,mixed> $tariff
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $result
	 * @param array<string,mixed> $to
	 */
	private function rate_from_tariff( QuoteRequest $request, string $delivery_type, array $tariff, array $payload, array $result, array $to ): ?DeliveryRate {
		$details = is_array( $tariff['result'] ?? null ) ? array_merge( $tariff, $tariff['result'] ) : $tariff;
		$price = is_numeric( $details['delivery_sum'] ?? null ) ? (float) $details['delivery_sum'] : 0.0;
		$code = (string) ( $details['tariff_code'] ?? '' );
		if ( $price <= 0 || '' === $code ) {
			return null;
		}
		$name = trim( (string) ( $details['tariff_name'] ?? $details['tariff_description'] ?? $code ) );
		$min = is_numeric( $details['period_min'] ?? null ) ? (int) $details['period_min'] : null;
		$max = is_numeric( $details['period_max'] ?? null ) ? (int) $details['period_max'] : $min;
		$range = DateRange::range( $min, $max, DateRange::UNIT_CALENDAR_DAYS );
		$days = DeliveryDaysFormatter::format( $range );
		$title = $this->method_title( $delivery_type );
		$label = '' !== $name ? $title . ', ' . $name : $title;
		if ( '' !== $days ) {
			$label .= ' - ' . $days;
		}
		$dimensions = $this->dimensions( $request->package );
		$meta = array(
			'tariff_selector_group' => true,
			'checkout_group_id' => self::checkout_group_id( $delivery_type ),
			'pickup_method_title' => self::PICKUP_TITLE,
			'courier_method_title' => self::COURIER_TITLE,
			'carrier_key' => self::KEY,
			'service_key' => CdekSettings::SERVICE_KEY,
			'delivery_type' => $delivery_type,
			'tariff_code' => $code,
			'tariff_name' => $name,
			'selected_tariff_object' => $code,
			'selected_tariff_title' => $name,
			'api_base_price_rub' => $price,
			'api_price_with_vat_rub' => $price,
			'api_delivery_days_min' => $min,
			'api_delivery_days_max' => $max,
			'api_delivery_days_text' => $days,
			'delivery_min_days' => $min,
			'delivery_max_days' => $max,
			'calendar_min' => $details['calendar_min'] ?? null,
			'calendar_max' => $details['calendar_max'] ?? null,
			'delivery_mode' => $details['delivery_mode'] ?? null,
			'request_payload_sanitized' => $payload,
			'response_tariff_sanitized' => $this->sanitize_tariff( $details ),
			'location' => array(
				'cdek_from_city_code' => $this->settings->sender_city_code(),
				'cdek_from_city_name' => $this->settings->sender_city_name(),
				'cdek_to_city_code' => (int) $to['city_code'],
				'cdek_to_city_name' => (string) $to['city_name'],
				'cdek_location_source' => (string) $to['source'],
				'cdek_location_confidence' => (float) $to['confidence'],
			),
			'package' => array(
				'weight_g' => max( 1, $request->package->get_total_weight_g() ),
				'items_weight_g' => $request->package->weight_g,
				'packaging_weight_g' => $request->package->packaging_weight_g,
				'total_weight_g' => $request->package->total_weight_g,
				'dimensions_cm' => $dimensions,
			),
			'http_code' => (int) ( $result['http_code'] ?? 0 ),
		);

		return new DeliveryRate(
			self::checkout_group_id( $delivery_type ) . ':' . preg_replace( '/\D+/', '', $code ),
			self::KEY,
			CdekSettings::TITLE,
			CdekSettings::SERVICE_KEY,
			CdekSettings::TITLE,
			$code,
			$name,
			$delivery_type,
			$label,
			Money::from_rubles( $price ),
			null,
			null,
			$range,
			'',
			$days,
			array(),
			false,
			'',
			false,
			DeliveryType::COURIER === $delivery_type,
			$meta
		);
	}

	/**
	 * @param array<string,mixed> $tariff
	 * @return array<string,mixed>
	 */
	private function sanitize_tariff( array $tariff ): array {
		return array_intersect_key(
			$tariff,
			array(
				'tariff_code' => true,
				'tariff_name' => true,
				'tariff_description' => true,
				'delivery_mode' => true,
				'delivery_sum' => true,
				'period_min' => true,
				'period_max' => true,
				'calendar_min' => true,
				'calendar_max' => true,
				'currency' => true,
			)
		);
	}

	private function method_title( string $delivery_type ): string {
		return DeliveryType::COURIER === $delivery_type ? self::COURIER_TITLE : self::PICKUP_TITLE;
	}

	private function normalize_delivery_type( string $delivery_type ): string {
		return DeliveryType::COURIER === $delivery_type ? DeliveryType::COURIER : DeliveryType::PICKUP;
	}

	private function quote_id( QuoteRequest $request, string $suffix ): string {
		return self::KEY . ':' . sha1( $suffix . '|' . $request->country_code . '|' . $request->destination->postcode . '|' . $request->destination->city . '|' . $request->package->get_total_weight_g() );
	}
}

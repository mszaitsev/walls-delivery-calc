<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Carriers\Cdek\CdekLocationResolver;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Cdek\Tariffs\CdekTariffRepository;
use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class CdekCarrier implements CarrierAdapterInterface {
	public const KEY = CdekSettings::CARRIER_KEY;
	public const PICKUP_TITLE = CdekSettings::DEFAULT_PICKUP_METHOD_TITLE;
	public const COURIER_TITLE = CdekSettings::DEFAULT_COURIER_METHOD_TITLE;

	public function __construct(
		private CdekSettings $settings,
		private CdekApiClient $client,
		private CdekLocationResolver $locations,
		private Logger $logger,
		private ?CdekTariffRepository $tariffs = null
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
		$this->logger->debug( 'CDEK location resolved.', $this->sanitize_location_result( $to ) );
		if ( empty( $to['success'] ) ) {
			return $this->empty_quote( $request, 'destination_city_not_resolved', $to );
		}

		$payload = $this->tariff_payload( $request, $to );
		try {
			$result = $this->client->tariffList( $payload );
		} catch ( CdekApiException $exception ) {
			$details = array_merge( $exception->details(), array( 'delivery_type' => $delivery_type ) );
			$this->logger->warning( 'CDEK tarifflist failed.', $details );
			return $this->empty_quote( $request, 'api_error', array( 'message' => $exception->getMessage(), 'api_error_details' => $details ) );
		}

		$tariffs = $this->tariffs_from_response( $result );

		$rates = array();
		$skipped_unknown = 0;
		$skipped_other_type = 0;
		foreach ( $tariffs as $tariff ) {
			$managed_tariff = $this->managed_tariff( $tariff );
			if ( is_array( $managed_tariff ) && empty( $managed_tariff['is_active'] ) ) {
				++$skipped_other_type;
				continue;
			}
			$type = is_array( $managed_tariff ) ? $this->normalize_delivery_type( (string) ( $managed_tariff['delivery_type'] ?? DeliveryType::PICKUP ) ) : $this->classify_delivery_type( $tariff );
			if ( DeliveryType::UNKNOWN === $type ) {
				++$skipped_unknown;
				continue;
			}
			if ( $type !== $delivery_type ) {
				++$skipped_other_type;
				continue;
			}
			$rate = $this->rate_from_tariff( $request, $type, $tariff, $payload, $result, $to, $managed_tariff );
			if ( $rate instanceof DeliveryRate ) {
				$rates[] = $rate;
			}
		}
		$filter_diagnostics = array(
			'requested_delivery_type' => $delivery_type,
			'matched_rates_count' => count( $rates ),
			'skipped_unknown_count' => $skipped_unknown,
			'skipped_other_type_count' => $skipped_other_type,
		);
		$this->logger->debug( 'CDEK tariff filter completed.', $filter_diagnostics );
		if ( array() !== $tariffs && array() === $rates ) {
			$this->logger->warning( 'CDEK tariff response has no matching tariffs for delivery type.', $filter_diagnostics );
		}
		if ( array() === $rates ) {
			$this->logger->warning( 'CDEK quote returned empty.', array_merge( array( 'reason' => 'no_tariffs_available' ), $filter_diagnostics ) );
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
		$this->logger->warning( 'CDEK quote returned empty.', array_merge( array( 'reason' => $reason ), $this->sanitize_empty_quote_diagnostics( $diagnostics ) ) );
		return new DeliveryQuote( $this->quote_id( $request, $reason ), self::KEY, $request->destination, $request->package, array(), false, $reason, $reason, false, 'api', array_merge( array( 'fallback_reason' => $reason ), $diagnostics ) );
	}

	/**
	 * @param array<string,mixed> $to
	 * @return array<string,mixed>
	 */
	private function tariff_payload( QuoteRequest $request, array $to ): array {
		$packages = $this->packages_payload( $request->package );
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
			'currency' => 1,
			'from_location' => $from,
			'to_location' => $to_location,
			'packages' => $packages,
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
	 * @return array<int,array{weight:int,length:int,width:int,height:int}>
	 */
	private function packages_payload( Package $package ): array {
		$packages = array();
		foreach ( $package->get_items() as $item ) {
			if ( ! $item instanceof PackageItem ) {
				continue;
			}
			$quantity = max( 0, $item->quantity );
			for ( $index = 0; $index < $quantity; ++$index ) {
				$packages[] = $this->package_payload_from_item( $item );
			}
		}
		if ( array() !== $packages && $package->packaging_weight_g > 0 && ! $this->has_packaging_item( $package ) ) {
			$packages[0]['weight'] = max( 1, (int) $packages[0]['weight'] + $package->packaging_weight_g );
		}

		if ( array() !== $packages ) {
			return $packages;
		}

		return array( $this->aggregated_package_payload( $package ) );
	}

	/**
	 * @return array{weight:int,length:int,width:int,height:int}
	 */
	private function package_payload_from_item( PackageItem $item ): array {
		$defaults = $this->settings->default_package_dimensions_cm();

		return array(
			'weight' => max( 1, $item->weight_g ),
			'length' => $this->dimension_or_default( $item->length_cm, $defaults['length'] ),
			'width' => $this->dimension_or_default( $item->width_cm, $defaults['width'] ),
			'height' => $this->dimension_or_default( $item->height_cm, $defaults['height'] ),
		);
	}

	/**
	 * @return array{weight:int,length:int,width:int,height:int}
	 */
	private function aggregated_package_payload( Package $package ): array {
		$dimensions = $this->dimensions( $package );

		return array(
			'weight' => max( 1, $package->get_total_weight_g() ),
			'length' => $dimensions['length'],
			'width' => $dimensions['width'],
			'height' => $dimensions['height'],
		);
	}

	private function has_packaging_item( Package $package ): bool {
		foreach ( $package->get_items() as $item ) {
			if ( $item instanceof PackageItem && 'WDC_PACKAGING' === strtoupper( trim( $item->sku ) ) ) {
				return true;
			}
		}

		return false;
	}

	private function dimension_or_default( int $value, int $default ): int {
		return max( 1, $value > 0 ? $value : $default );
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
		$raw_mode = $tariff['delivery_mode'] ?? ( is_array( $tariff['result'] ?? null ) ? ( $tariff['result']['delivery_mode'] ?? 0 ) : 0 );
		$mode_text = strtolower( str_replace( '_', '-', trim( (string) $raw_mode ) ) );
		if ( '' !== $mode_text && ! is_numeric( $raw_mode ) ) {
			if ( str_ends_with( $mode_text, '-warehouse' ) || str_ends_with( $mode_text, '-pickup' ) ) {
				return DeliveryType::PICKUP;
			}
			if ( str_ends_with( $mode_text, '-door' ) || str_ends_with( $mode_text, '-courier' ) ) {
				return DeliveryType::COURIER;
			}
		}
		$mode = (int) $raw_mode;
		// CDEK delivery_mode: 1 door-door, 2 door-warehouse, 3 warehouse-door, 4 warehouse-warehouse.
		return match ( $mode ) {
			2, 4 => DeliveryType::PICKUP,
			1, 3 => DeliveryType::COURIER,
			default => DeliveryType::UNKNOWN,
		};
	}

	/**
	 * @param array<string,mixed> $tariff
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $result
	 * @param array<string,mixed> $to
	 */
	private function rate_from_tariff( QuoteRequest $request, string $delivery_type, array $tariff, array $payload, array $result, array $to, ?array $managed_tariff = null ): ?DeliveryRate {
		$details = is_array( $tariff['result'] ?? null ) ? array_merge( $tariff, $tariff['result'] ) : $tariff;
		$price = is_numeric( $details['delivery_sum'] ?? null ) ? (float) $details['delivery_sum'] : 0.0;
		$code = (string) ( $details['tariff_code'] ?? '' );
		if ( $price <= 0 || '' === $code ) {
			return null;
		}
		$api_name = trim( (string) ( $details['tariff_name'] ?? $details['tariff_description'] ?? $code ) );
		$name = $this->display_tariff_title( $api_name, $managed_tariff, $code );
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
			'pickup_method_title' => $this->settings->pickup_method_title(),
			'courier_method_title' => $this->settings->courier_method_title(),
			'carrier_key' => self::KEY,
			'service_key' => CdekSettings::SERVICE_KEY,
			'delivery_type' => $delivery_type,
			'tariff_code' => $code,
			'tariff_name' => $name,
			'tariff_name_from_cdek' => is_array( $managed_tariff ) ? (string) ( $managed_tariff['tariff_name_from_cdek'] ?? $api_name ) : $api_name,
			'tariff_custom_title' => is_array( $managed_tariff ) ? (string) ( $managed_tariff['custom_title'] ?? '' ) : '',
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
			rate_id: self::checkout_group_id( $delivery_type ) . ':' . preg_replace( '/\D+/', '', $code ),
			carrier_key: self::KEY,
			carrier_name: CdekSettings::TITLE,
			service_key: CdekSettings::SERVICE_KEY,
			service_name: CdekSettings::TITLE,
			tariff_key: $code,
			tariff_name: $name,
			delivery_type: $delivery_type,
			title: $label,
			price: Money::from_rubles( $price ),
			original_price: null,
			crossed_price: null,
			delivery_days: $range,
			planned_delivery_date: '',
			planned_delivery_comment: $days,
			comments: array(),
			disabled: false,
			disabled_reason: '',
			requires_pickup_point: DeliveryType::PICKUP === $delivery_type,
			requires_courier_address: DeliveryType::COURIER === $delivery_type,
			meta: $meta
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

	/**
	 * @param array<string,mixed> $result
	 * @param array<int,array<string,mixed>> $tariffs
	 * @return array<string,mixed>
	 */
	private function tarifflist_summary( array $result, array $tariffs ): array {
		$modes = array();
		$items = array();
		foreach ( $tariffs as $tariff ) {
			$details = is_array( $tariff['result'] ?? null ) ? array_merge( $tariff, $tariff['result'] ) : $tariff;
			if ( isset( $details['delivery_mode'] ) ) {
				$modes[] = (int) $details['delivery_mode'];
			}
			$items[] = array(
				'tariff_code' => (string) ( $details['tariff_code'] ?? '' ),
				'tariff_name' => (string) ( $details['tariff_name'] ?? $details['tariff_description'] ?? '' ),
				'delivery_sum' => is_numeric( $details['delivery_sum'] ?? null ) ? (float) $details['delivery_sum'] : null,
				'period_min' => is_numeric( $details['period_min'] ?? null ) ? (int) $details['period_min'] : null,
				'period_max' => is_numeric( $details['period_max'] ?? null ) ? (int) $details['period_max'] : null,
			);
		}

		return array(
			'http_code' => (int) ( $result['http_code'] ?? 0 ),
			'tariff_codes_count' => count( $tariffs ),
			'delivery_mode_values' => array_values( array_unique( $modes ) ),
			'tariffs' => $items,
		);
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	private function sanitize_location_result( array $result ): array {
		return array(
			'success' => (bool) ( $result['success'] ?? false ),
			'city_code' => isset( $result['city_code'] ) ? (int) $result['city_code'] : null,
			'city_name' => (string) ( $result['city_name'] ?? '' ),
			'region' => (string) ( $result['region'] ?? '' ),
			'source' => (string) ( $result['source'] ?? '' ),
			'confidence' => isset( $result['confidence'] ) ? (float) $result['confidence'] : null,
			'reason' => (string) ( $result['reason'] ?? '' ),
			'attempts_count' => isset( $result['attempts_count'] ) ? (int) $result['attempts_count'] : 0,
			'attempts_labels' => is_array( $result['attempts_labels'] ?? null ) ? $result['attempts_labels'] : array(),
			'attempts' => is_array( $result['attempts'] ?? null ) ? $result['attempts'] : array(),
			'selected_attempt_label' => (string) ( $result['selected_attempt_label'] ?? '' ),
			'final_reason' => (string) ( $result['final_reason'] ?? '' ),
		);
	}

	/**
	 * @param array<string,mixed> $diagnostics
	 * @return array<string,mixed>
	 */
	private function sanitize_empty_quote_diagnostics( array $diagnostics ): array {
		if ( isset( $diagnostics['success'] ) || isset( $diagnostics['city_code'] ) || isset( $diagnostics['confidence'] ) ) {
			return array( 'location' => $this->sanitize_location_result( $diagnostics ) );
		}
		if ( isset( $diagnostics['api_error_details'] ) && is_array( $diagnostics['api_error_details'] ) ) {
			return array( 'api_error_details' => $diagnostics['api_error_details'] );
		}

		return array();
	}

	private function method_title( string $delivery_type ): string {
		return $this->settings->method_title( $delivery_type );
	}

	/**
	 * @param array<string,mixed> $tariff
	 * @return array<string,mixed>|null
	 */
	private function managed_tariff( array $tariff ): ?array {
		if ( ! $this->tariffs instanceof CdekTariffRepository ) {
			return null;
		}
		$details = is_array( $tariff['result'] ?? null ) ? array_merge( $tariff, $tariff['result'] ) : $tariff;
		$code = trim( (string) ( $details['tariff_code'] ?? '' ) );
		if ( '' === $code ) {
			return null;
		}

		return $this->tariffs->find_by_code( $code );
	}

	/**
	 * @param array<string,mixed>|null $managed_tariff
	 */
	private function display_tariff_title( string $api_name, ?array $managed_tariff, string $code ): string {
		if ( is_array( $managed_tariff ) ) {
			$custom = trim( (string) ( $managed_tariff['custom_title'] ?? '' ) );
			if ( '' !== $custom ) {
				return $custom;
			}
			$from_cdek = trim( (string) ( $managed_tariff['tariff_name_from_cdek'] ?? '' ) );
			if ( '' !== $from_cdek ) {
				return $from_cdek;
			}
		}
		if ( '' !== trim( $api_name ) ) {
			return trim( $api_name );
		}

		return $code;
	}

	private function normalize_delivery_type( string $delivery_type ): string {
		return DeliveryType::COURIER === $delivery_type ? DeliveryType::COURIER : DeliveryType::PICKUP;
	}

	private function quote_id( QuoteRequest $request, string $suffix ): string {
		return self::KEY . ':' . sha1( $suffix . '|' . $request->country_code . '|' . $request->destination->postcode . '|' . $request->destination->city . '|' . $request->package->get_total_weight_g() );
	}
}

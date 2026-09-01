<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Contracts\CarrierCustomerCommentProviderInterface;
use WallsShop\WDC\Carriers\Contracts\CarrierQuoteCacheContextProviderInterface;
use WallsShop\WDC\Carriers\OzonDelivery\Checkout\OzonDeliveryCustomerCommentProvider;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliveryCredentials;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryCourierAddressMapper;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryCourierLocationResolver;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteException;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteResult;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteService;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryCarrier implements CarrierAdapterInterface, CarrierQuoteCacheContextProviderInterface, CarrierCustomerCommentProviderInterface {
	public const KEY = OzonDeliverySettings::CARRIER_KEY;
	public const RATE_ID = 'ozon_delivery:pickup';
	public const PICKUP_RATE_ID = 'ozon_delivery:pickup';
	public const COURIER_RATE_ID = 'ozon_delivery:courier';
	public const TARIFF_KEY = 'pickup';
	public const TARIFF_NAME = 'Ozon до ПВЗ';
	public const COURIER_TARIFF_KEY = 'courier';
	public const COURIER_TARIFF_NAME = 'Ozon курьером';

	public function __construct(
		private OzonDeliverySettings $settings,
		private OzonDeliveryCredentials $credentials,
		private OzonDeliveryQuoteService $quotes,
		private OzonDeliveryCustomerCommentProvider $customer_comments,
		private Logger $logger,
		private ?OzonDeliveryCourierAddressMapper $courier_address = null,
		private ?OzonDeliveryCourierLocationResolver $courier_location = null
	) {
		$this->courier_address ??= new OzonDeliveryCourierAddressMapper();
		$this->courier_location ??= new OzonDeliveryCourierLocationResolver( new LocationRepository() );
	}

	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( self::KEY, OzonDeliverySettings::TITLE, 'api', $this->runtime_enabled() );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities(
			supports_quotes: true,
			supports_pickup_points: true,
			supports_status_sync: false,
			supports_courier_delivery: true,
			supports_pickup_delivery: true,
			supports_international: false
		);
	}

	public function supports_country( string $countryCode ): bool {
		return 'RU' === strtoupper( trim( $countryCode ) ) && $this->runtime_enabled();
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		if ( ! $this->base_runtime_enabled() || 'RU' !== strtoupper( trim( $request->country_code ?: $request->destination->country_code ) ) ) {
			return $this->empty_quote( $request, 'ozon_delivery_runtime_unavailable' );
		}

		$requested = (string) ( $request->customer_context['delivery_type'] ?? '' );
		$modes = DeliveryType::PICKUP === $requested || DeliveryType::COURIER === $requested ? array( $requested ) : array( DeliveryType::PICKUP, DeliveryType::COURIER );
		$rates = array();
		$first_error = null;
		foreach ( $modes as $mode ) {
			if ( DeliveryType::PICKUP === $mode && ! $this->pickup_runtime_enabled() ) {
				continue;
			}
			if ( DeliveryType::COURIER === $mode && ! $this->courier_runtime_enabled() ) {
				continue;
			}
			try {
				$result = DeliveryType::COURIER === $mode ? $this->quotes->quote_courier( $request ) : $this->quotes->quote_pickup( $request );
				$this->logger->info( 'Ozon Delivery checkout quote calculated.', $this->safe_success_log_context( $result ) + array( 'delivery_type' => $mode ) );
				$rates[] = $this->rate_from_result( $result, $mode );
			} catch ( OzonDeliveryQuoteException $exception ) {
				$first_error ??= $exception;
				$this->logger->warning(
					'Ozon Delivery checkout quote unavailable.',
					array(
						'carrier' => self::KEY,
						'delivery_type' => $mode,
						'operation' => $exception->operation,
						'error_code' => $exception->safe_code,
						'http_status' => $exception->http_status,
					) + $this->safe_exception_log_context( $exception )
				);
			}
		}

		if ( array() === $rates ) {
			return $first_error instanceof OzonDeliveryQuoteException
				? $this->empty_quote( $request, $first_error->safe_code, array( 'operation' => $first_error->operation, 'http_status' => $first_error->http_status ) + $this->safe_exception_log_context( $first_error ) )
				: $this->empty_quote( $request, 'ozon_delivery_runtime_unavailable' );
		}

		return new DeliveryQuote(
			$this->quote_id( $request, $rates ),
			self::KEY,
			$request->destination,
			$request->package,
			$rates,
			true,
			'',
			'',
			false,
			'api',
			array(
				'rate_count' => count( $rates ),
			)
		);
	}

	/** @return array<int,string> */
	public function customer_comments( DeliveryRate $rate ): array {
		return $this->customer_comments->customer_comments( $rate );
	}

	/** @return array<string,mixed> */
	public function quote_cache_context( QuoteRequest $request ): array {
		$selections = is_array( $request->customer_context['pickup_selections'] ?? null ) ? $request->customer_context['pickup_selections'] : array();
		$selection = is_array( $selections[ OzonDeliverySettings::PICKUP_FAMILY ] ?? null ) ? $selections[ OzonDeliverySettings::PICKUP_FAMILY ] : array();
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();

		return array(
			'ozon_delivery_pricing_contract_version' => 4,
			'ozon_delivery_pickup_shipment_method_id' => $this->settings->pickup_shipment_method_id(),
			'ozon_delivery_courier_shipment_method_id' => $this->settings->courier_shipment_method_id(),
			'ozon_delivery_pricing_gate' => $this->settings->pricing_live_confirmed() ? 'live_confirmed' : 'closed',
			'ozon_delivery_requested_delivery_type' => (string) ( $request->customer_context['delivery_type'] ?? '' ),
			'ozon_delivery_selected_point_id' => (string) ( $selection['point_code'] ?? $selection['point_id'] ?? $snapshot['point_code'] ?? '' ),
			'ozon_delivery_courier_location_fingerprint' => $this->courier_location_fingerprint( $request ),
			'ozon_delivery_declared_value_kopecks' => $request->package->declared_value->get_kopecks() ?: $request->order_total->get_kopecks(),
			'ozon_delivery_package_weight_g' => $request->package->get_total_weight_g(),
			'ozon_delivery_package_volume_cm3' => $request->package->get_total_volume_cm3(),
			'ozon_delivery_package_dimensions' => array(
				'length_cm' => $request->package->length_cm,
				'width_cm' => $request->package->width_cm,
				'height_cm' => $request->package->height_cm,
			),
		);
	}

	private function runtime_enabled(): bool {
		return $this->base_runtime_enabled() && ( $this->pickup_runtime_enabled() || $this->courier_runtime_enabled() );
	}

	private function base_runtime_enabled(): bool {
		return $this->credentials->is_complete() && $this->settings->pricing_live_confirmed();
	}

	private function pickup_runtime_enabled(): bool {
		return $this->settings->pickup_shipment_method_id() > 0;
	}

	private function courier_runtime_enabled(): bool {
		return $this->settings->courier_shipment_method_id() > 0;
	}

	/** @return array<string,mixed> */
	private function safe_exception_log_context( OzonDeliveryQuoteException $exception ): array {
		$details = $exception->details;
		$pickup = is_array( $details['pickup_diagnostics'] ?? null ) ? $details['pickup_diagnostics'] : array();
		$context = array();
		foreach ( array( 'places_count', 'total_weight_g', 'max_place_weight_g' ) as $key ) {
			if ( array_key_exists( $key, $details ) && is_scalar( $details[ $key ] ) ) { $context[ $key ] = $details[ $key ]; }
		}
		foreach ( array( 'courier_coordinate_source', 'courier_location_id', 'courier_latitude', 'courier_longitude', 'shipment_method_id', 'postings_count', 'results_count', 'usable_results_count', 'failed_results_count' ) as $key ) {
			if ( array_key_exists( $key, $details ) && is_scalar( $details[ $key ] ) ) { $context[ $key ] = $details[ $key ]; }
		}
		if ( isset( $details['places'] ) && is_array( $details['places'] ) ) { $context['places'] = $details['places']; }
		$postings = $this->safe_success_rows( $details['postings'] ?? null, array( 'request_id', 'shipment_method_id', 'weight_g', 'length_mm', 'width_mm', 'height_mm', 'declared_value_amount', 'declared_value_currency' ) );
		if ( array() !== $postings ) { $context['postings'] = $postings; }
		if ( is_array( $details['postings'] ?? null ) && count( $details['postings'] ) > count( $postings ) ) { $context['postings_truncated'] = true; }
		$ozon_results = $this->safe_success_rows( $details['ozon_results'] ?? null, array( 'request_id', 'error_code', 'code', 'message', 'status', 'availability', 'posting_present' ) );
		if ( array() !== $ozon_results ) { $context['ozon_results'] = $ozon_results; }
		if ( is_array( $details['ozon_results'] ?? null ) && count( $details['ozon_results'] ) > count( $ozon_results ) ) { $context['ozon_results_truncated'] = true; }
		if ( isset( $details['places_truncated'] ) ) { $context['places_truncated'] = (bool) $details['places_truncated']; }
		foreach ( array( 'rows_in_bbox', 'valid_base_points', 'base_point_rejected', 'outside_radius', 'inside_radius', 'accepted', 'min_weight_rejected', 'max_weight_rejected', 'dimension_rejected', 'cargo_weight_rejected', 'cargo_dimensions_rejected', 'cargo_other_rejected', 'cargo_rejected', 'points_with_all_3_dimension_limits', 'points_with_partial_dimension_limits', 'points_without_dimension_limits', 'points_with_min_weight', 'points_without_min_weight', 'points_with_max_weight', 'points_without_max_weight', 'highest_max_weight_g' ) as $key ) {
			if ( array_key_exists( $key, $pickup ) && is_scalar( $pickup[ $key ] ) ) { $context[ $key ] = $pickup[ $key ]; }
		}

		return $context;
	}

	/** @return array<string,mixed> */
	private function safe_success_log_context( OzonDeliveryQuoteResult $result ): array {
		$meta = $result->meta;
		$context = array(
			'carrier' => self::KEY,
			'packages_count' => $result->package_count,
		);
		foreach ( array( 'total_weight_g', 'goods_weight_g', 'packaging_weight_g', 'packing_strategy', 'selected_box_format', 'total_declared_value_rub', 'declared_value_per_posting_rub', 'delivery_total_rub', 'insurance_total_rub', 'total_rub' ) as $key ) {
			if ( array_key_exists( $key, $meta ) && is_scalar( $meta[ $key ] ) ) {
				$context[ $key ] = $meta[ $key ];
			}
		}
		foreach ( array( 'shipment_method_id', 'courier_coordinate_source', 'courier_location_id', 'courier_latitude', 'courier_longitude' ) as $key ) {
			if ( array_key_exists( $key, $meta ) && is_scalar( $meta[ $key ] ) ) {
				$context[ $key ] = $meta[ $key ];
			}
		}
		if ( isset( $meta['selected_box_formats'] ) && is_array( $meta['selected_box_formats'] ) ) {
			$context['selected_box_formats'] = array_values( array_filter( $meta['selected_box_formats'], static fn( mixed $format ): bool => is_string( $format ) ) );
		}
		$places = $this->safe_success_rows( $meta['ozon_delivery_places'] ?? null, array( 'weight_g', 'length_cm', 'width_cm', 'height_cm' ) );
		if ( array() !== $places ) {
			$context['places'] = $places;
		}
		if ( is_array( $meta['ozon_delivery_places'] ?? null ) && count( $meta['ozon_delivery_places'] ) > count( $places ) ) {
			$context['places_truncated'] = true;
		}
		$postings = $this->safe_success_rows( $meta['postings'] ?? null, array( 'request_id', 'delivery_cost_rub', 'insurance_cost_rub', 'total_cost_rub', 'delivery_days' ) );
		if ( array() !== $postings ) {
			$context['postings'] = $postings;
		}
		if ( is_array( $meta['postings'] ?? null ) && count( $meta['postings'] ) > count( $postings ) ) {
			$context['postings_truncated'] = true;
		}

		return $context;
	}

	/**
	 * @param array<int,string> $fields
	 * @return array<int,array<string,int|float|string>>
	 */
	private function safe_success_rows( mixed $rows, array $fields ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$safe = array();
		foreach ( array_slice( $rows, 0, 20 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$allowed = array();
			foreach ( $fields as $field ) {
				if ( array_key_exists( $field, $row ) && ( is_int( $row[ $field ] ) || is_float( $row[ $field ] ) || is_string( $row[ $field ] ) ) ) {
					$allowed[ $field ] = $row[ $field ];
				}
			}
			if ( array() !== $allowed ) {
				$safe[] = $allowed;
			}
		}

		return $safe;
	}

	private function rate_from_result( OzonDeliveryQuoteResult $result, string $delivery_type ): DeliveryRate {
		$is_courier = DeliveryType::COURIER === $delivery_type;
		return new DeliveryRate(
			$is_courier ? self::COURIER_RATE_ID : self::PICKUP_RATE_ID,
			self::KEY,
			OzonDeliverySettings::TITLE,
			OzonDeliverySettings::SERVICE_KEY,
			OzonDeliverySettings::TITLE,
			$is_courier ? self::COURIER_TARIFF_KEY : self::TARIFF_KEY,
			$is_courier ? self::COURIER_TARIFF_NAME : self::TARIFF_NAME,
			$is_courier ? DeliveryType::COURIER : DeliveryType::PICKUP,
			$is_courier ? self::COURIER_TARIFF_NAME : self::TARIFF_NAME,
			$result->price,
			null,
			null,
			$result->delivery_days,
			'',
			'',
			array(),
			false,
			'',
			! $is_courier,
			$is_courier,
			array_merge(
				array(
					'preserve_rate_title' => true,
					'carrier_key' => self::KEY,
					'service_key' => OzonDeliverySettings::SERVICE_KEY,
					'delivery_type' => $is_courier ? DeliveryType::COURIER : DeliveryType::PICKUP,
					'api_base_price_rub' => $result->price->get_rubles(),
					'ozon_delivery_base_price_kopecks' => $result->price->get_kopecks(),
					'ozon_delivery_destination_point_id' => $result->destination_point_id,
					'ozon_delivery_shipment_method_id' => $result->shipment_method_id,
					'ozon_delivery_delivery_mode' => $is_courier ? 'courier' : 'pickup',
					'ozon_delivery_package_count' => $result->package_count,
					'ozon_delivery_endpoint' => $result->endpoint,
					'ozon_delivery_http_status' => $result->http_status,
				),
				$is_courier ? array() : array(
					'pickup_family' => OzonDeliverySettings::PICKUP_FAMILY,
					'requires_rate_refresh_on_pickup_selection' => true,
				),
				$result->meta
			),
			$result->price,
			$result->delivery_days
		);
	}

	private function courier_location_fingerprint( QuoteRequest $request ): string {
		try {
			return $this->courier_address->fingerprint( $this->courier_location->resolve( $request ) );
		} catch ( OzonDeliveryQuoteException $exception ) {
			$location_id = max( 0, (int) ( $request->customer_context['selected_location_id'] ?? 0 ) );
			return 'location_id=' . $location_id . '|missing=' . $exception->safe_code;
		}
	}

	/** @param array<string,mixed> $meta */
	private function empty_quote( QuoteRequest $request, string $code, array $meta = array() ): DeliveryQuote {
		return new DeliveryQuote( self::KEY . ':' . sha1( $code . wp_json_encode( $request->to_array() ) ), self::KEY, $request->destination, $request->package, array(), false, $code, 'Расчет Ozon Delivery недоступен.', false, 'api', $meta );
	}

	/** @param array<int,DeliveryRate> $rates */
	private function quote_id( QuoteRequest $request, array $rates ): string {
		$rate_keys = array_map( static fn( DeliveryRate $rate ): array => array( $rate->rate_id, $rate->price->get_kopecks(), $rate->delivery_type ), $rates );
		return self::KEY . ':' . sha1( wp_json_encode( array( $request->to_array(), $rate_keys ) ) ?: '' );
	}
}

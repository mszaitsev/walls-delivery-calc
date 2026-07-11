<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use Throwable;
use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pricing\YandexDeliveryPricingRequestBuilder;
use WallsShop\WDC\Carriers\YandexDelivery\Pricing\YandexDeliveryPricingResponseParser;
use WallsShop\WDC\Carriers\YandexDelivery\Pricing\YandexDeliveryPricingResult;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;

final class YandexDeliveryCarrier implements CarrierAdapterInterface {
	public const PICKUP_RATE_ID = 'yandex_pickup';
	public const COURIER_RATE_ID = 'yandex_courier';
	private const DEFAULT_DELIVERY_TIME = 'без указания срока';
	/** @var array<string,mixed> */
	private array $last_pricing_request_diagnostics = array();

	public function __construct(
		private YandexDeliverySettings $settings,
		private ?YandexDeliveryApiClient $api = null,
		private ?YandexLocationMappingV2Repository $location_mapping = null,
		private ?YandexDeliveryPickupPointV2Repository $pickup_points = null,
		private ?Logger $logger = null,
		private ?YandexDeliveryPricingRequestBuilder $request_builder = null,
		private ?YandexDeliveryPricingResponseParser $response_parser = null
	) {
		$this->request_builder ??= new YandexDeliveryPricingRequestBuilder();
		$this->response_parser ??= new YandexDeliveryPricingResponseParser();
	}

	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( YandexDeliverySettings::CARRIER_KEY, YandexDeliverySettings::TITLE, 'api', true );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities(
			supports_quotes: true,
			supports_courier_delivery: true,
			supports_pickup_delivery: true
		);
	}

	public function supports_country( string $countryCode ): bool {
		return 'RU' === strtoupper( trim( $countryCode ) );
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		if ( ! $this->supports_country( $request->country_code ?: $request->destination->country_code ) ) {
			return new DeliveryQuote( $this->quote_id( $request, 'unsupported_country' ), YandexDeliverySettings::CARRIER_KEY, $request->destination, $request->package, array(), true, '', '', false, 'manual' );
		}

		$delivery_type = $this->normalize_delivery_type( (string) ( $request->customer_context['delivery_type'] ?? '' ) );
		$rates = '' !== $delivery_type
			? array( $this->rate( $request, $delivery_type ) )
			: array(
				$this->rate( $request, DeliveryType::PICKUP ),
				$this->rate( $request, DeliveryType::COURIER ),
			);

		return new DeliveryQuote(
			$this->quote_id( $request, '' !== $delivery_type ? $delivery_type : 'all' ),
			YandexDeliverySettings::CARRIER_KEY,
			$request->destination,
			$request->package,
			$rates,
			true,
			'',
			'',
			false,
			'api',
			array( 'pricing_calculator' => true )
		);
	}

	private function rate( QuoteRequest $request, string $delivery_type ): DeliveryRate {
		$delivery_type = $this->normalize_delivery_type( $delivery_type ) ?: DeliveryType::PICKUP;
		$method_title = DeliveryType::COURIER === $delivery_type ? $this->settings->courier_method_title() : $this->settings->pickup_method_title();
		$this->last_pricing_request_diagnostics = array();

		try {
			$result = $this->pricing_result( $request, $delivery_type );
			return $this->build_rate( $delivery_type, $method_title, $result->delivery_time_label(), Money::from_kopecks( $result->price_kopecks ), false, '', array_merge( array( 'api_base_price_rub' => $result->price_kopecks / 100, 'pricing_total_kopecks' => $result->price_kopecks, 'delivery_days' => $result->delivery_days, 'delivery_min_days' => $result->delivery_days, 'delivery_max_days' => $result->delivery_days, 'api_delivery_days' => $result->delivery_days, 'api_delivery_text' => $result->delivery_time_label() ), $this->last_pricing_request_diagnostics ) );
		} catch ( Throwable $exception ) {
			$reason = $this->disabled_reason( $exception, $delivery_type );
			$this->log_pricing_error( $delivery_type, $exception, $request );

			return $this->build_rate( $delivery_type, $method_title, self::DEFAULT_DELIVERY_TIME, Money::from_kopecks( 0 ), true, $reason, array_merge( array( 'pricing_error' => $this->error_code( $exception ) ), $this->last_pricing_request_diagnostics ) );
		}
	}

	/** @param array<string,mixed> $meta */
	private function build_rate( string $delivery_type, string $method_title, string $delivery_time, Money $price, bool $disabled, string $disabled_reason, array $meta ): DeliveryRate {
		$title = $method_title . ' - ' . $delivery_time;

		return new DeliveryRate(
			DeliveryType::COURIER === $delivery_type ? self::COURIER_RATE_ID : self::PICKUP_RATE_ID,
			YandexDeliverySettings::CARRIER_KEY,
			YandexDeliverySettings::TITLE,
			YandexDeliverySettings::SERVICE_KEY,
			YandexDeliverySettings::TITLE,
			$delivery_type,
			$method_title,
			$delivery_type,
			$title,
			$price,
			null,
			null,
			is_numeric( $meta['delivery_days'] ?? null ) ? DateRange::single( (int) $meta['delivery_days'] ) : new DateRange(),
			'',
			$delivery_time,
			array(),
			$disabled,
			$disabled_reason,
			DeliveryType::PICKUP === $delivery_type,
			DeliveryType::COURIER === $delivery_type,
			array_merge(
				array(
					'preserve_rate_title' => true,
					'delivery_time' => $delivery_time,
					'yandex_delivery_rate_type' => $delivery_type,
					'pricing_calculator' => true,
				),
				$meta
			)
		);
	}

	private function pricing_result( QuoteRequest $request, string $delivery_type ): YandexDeliveryPricingResult {
		if ( ! $this->api instanceof YandexDeliveryApiClient || ! $this->request_builder instanceof YandexDeliveryPricingRequestBuilder || ! $this->response_parser instanceof YandexDeliveryPricingResponseParser ) {
			throw new YandexDeliveryApiException( 'Pricing-calculator Яндекс.Доставки недоступен.', array( 'error_code' => 'pricing_dependencies_missing' ) );
		}
		$source_station_id = $this->settings->source_platform_station_id();
		if ( '' === $source_station_id ) {
			throw new YandexDeliveryApiException( 'Не выбран ПВЗ сдачи Яндекс.Доставки.', array( 'error_code' => 'source_platform_station_missing' ) );
		}
		if ( DeliveryType::COURIER === $delivery_type ) {
			return $this->courier_pricing_result( $request, $source_station_id );
		}

		$pickup_destination = $this->pickup_destination_station_id( $request );
		$payload = $this->request_builder->pickup( $request, $source_station_id, $pickup_destination['station_id'] );
		$this->last_pricing_request_diagnostics = array_merge(
			$this->request_builder->last_diagnostics(),
			array( 'pickup_source' => $pickup_destination['source'], 'destination_platform_station_id' => $pickup_destination['station_id'] )
		);

		return $this->response_parser->parse( $this->api->pricingCalculator( $payload ) );
	}

	private function courier_pricing_result( QuoteRequest $request, string $source_station_id ): YandexDeliveryPricingResult {
		$primary_error = null;
		$checkout_address = $this->courier_address( $request );
		if ( '' !== $checkout_address ) {
			try {
				$result = $this->courier_request_result( $request, $source_station_id, $checkout_address );
				$this->last_pricing_request_diagnostics = array_merge(
					$this->request_builder->last_diagnostics(),
					array( 'courier_pricing_source' => 'checkout_address', 'courier_fallback_used' => false )
				);

				return $result;
			} catch ( Throwable $exception ) {
				$primary_error = $exception;
				$this->last_pricing_request_diagnostics = array_merge(
					$this->request_builder->last_diagnostics(),
					array( 'courier_pricing_source' => 'checkout_address', 'courier_fallback_used' => false, 'courier_primary_error_code' => $this->error_code( $exception ) )
				);
				$this->log_courier_primary_error( $exception, $request );
			}
		} else {
			$primary_error = new YandexDeliveryApiException( 'Недостаточно адреса для расчета курьера Яндекс.Доставки.', array( 'error_code' => 'courier_address_missing' ) );
			$this->last_pricing_request_diagnostics = array(
				'courier_pricing_source' => 'checkout_address',
				'courier_fallback_used' => false,
				'courier_primary_error_code' => 'courier_address_missing',
			);
		}

		$pickup_destination = null;
		try {
			$pickup_destination = $this->pickup_destination_station_id( $request );
			$fallback_address = $this->courier_fallback_pickup_address( $pickup_destination['station_id'] );
			if ( '' === $fallback_address ) {
				throw new YandexDeliveryApiException( 'У резервного ПВЗ Яндекс.Доставки отсутствует адрес.', array( 'error_code' => 'courier_fallback_pickup_address_missing' ) );
			}
			$result = $this->courier_request_result( $request, $source_station_id, $fallback_address );
			$this->last_pricing_request_diagnostics = array_merge(
				$this->request_builder->last_diagnostics(),
				array(
					'courier_pricing_source' => 'pickup_address_fallback',
					'courier_fallback_used' => true,
					'courier_fallback_pickup_source' => $pickup_destination['source'],
					'courier_fallback_platform_station_id' => $pickup_destination['station_id'],
					'courier_primary_error_code' => $this->error_code( $primary_error ),
				)
			);

			return $result;
		} catch ( Throwable $fallback_error ) {
			$this->last_pricing_request_diagnostics = array_merge(
				$this->last_pricing_request_diagnostics,
				array(
					'courier_pricing_source' => 'pickup_address_fallback',
					'courier_fallback_used' => true,
					'courier_fallback_pickup_source' => is_array( $pickup_destination ) ? (string) $pickup_destination['source'] : '',
					'courier_fallback_platform_station_id' => is_array( $pickup_destination ) ? (string) $pickup_destination['station_id'] : '',
					'courier_primary_error_code' => $this->error_code( $primary_error ),
					'courier_fallback_error_code' => $this->error_code( $fallback_error ),
				)
			);

			throw new YandexDeliveryApiException(
				'Не удалось рассчитать курьерскую доставку Яндекс.Доставки ни по адресу checkout, ни по адресу ПВЗ.',
				array(
					'error_code' => 'courier_pricing_fallback_failed',
					'courier_primary_error_code' => $this->error_code( $primary_error ),
					'courier_fallback_error_code' => $this->error_code( $fallback_error ),
				),
				0,
				$fallback_error
			);
		}
	}

	private function courier_request_result( QuoteRequest $request, string $source_station_id, string $destination_address ): YandexDeliveryPricingResult {
		$payload = $this->request_builder->courier( $request, $source_station_id, $destination_address );

		return $this->response_parser->parse( $this->api->pricingCalculator( $payload ) );
	}

	private function courier_fallback_pickup_address( string $station_id ): string {
		if ( ! $this->pickup_points instanceof YandexDeliveryPickupPointV2Repository ) {
			throw new YandexDeliveryApiException( 'Локальная база ПВЗ Яндекс.Доставки недоступна.', array( 'error_code' => 'pickup_repository_missing' ) );
		}
		$point = $this->pickup_points->destination_pickup_point_by_platform_station_id( $station_id );
		if ( ! is_array( $point ) ) {
			throw new YandexDeliveryApiException( 'Резервный ПВЗ Яндекс.Доставки не найден.', array( 'error_code' => 'courier_fallback_pickup_missing' ) );
		}
		$full_address = trim( (string) ( $point['full_address'] ?? '' ) );
		if ( '' !== $full_address ) {
			return $this->compact_address( array( $full_address ) );
		}
		$locality = trim( (string) ( $point['locality'] ?? '' ) );
		$street = trim( (string) ( $point['street'] ?? '' ) );
		$house = trim( (string) ( $point['house'] ?? '' ) );
		if ( '' === $locality || '' === $street || '' === $house ) {
			return '';
		}

		return $this->compact_address( array( $locality, $street, $house ) );
	}

	/** @return array{station_id:string,source:string} */
	private function pickup_destination_station_id( QuoteRequest $request ): array {
		$selected = $this->selected_destination_station_id( $request );
		if ( '' !== $selected ) {
			return array( 'station_id' => $selected, 'source' => 'selected' );
		}

		return array( 'station_id' => $this->representative_destination_station_id( $request ), 'source' => 'representative' );
	}

	private function selected_destination_station_id( QuoteRequest $request ): string {
		$family = YandexDeliverySettings::CARRIER_KEY . ':pickup';
		$selections = is_array( $request->customer_context['pickup_selections'] ?? null ) ? $request->customer_context['pickup_selections'] : array();
		$family_selection = is_array( $selections[ $family ] ?? null ) ? $selections[ $family ] : array();
		$station_id = $this->station_id_from_pickup_selection( $family_selection );
		if ( '' !== $station_id ) {
			return $station_id;
		}

		$selection = is_array( $request->customer_context['pickup_selection'] ?? null ) ? $request->customer_context['pickup_selection'] : array();

		return $this->station_id_from_pickup_selection( $selection );
	}

	/** @param array<string,mixed> $selection */
	private function station_id_from_pickup_selection( array $selection ): string {
		if ( array() === $selection ) {
			return '';
		}
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$carrier = (string) ( $selection['carrier_key'] ?? $selection['carrier'] ?? $snapshot['carrier_key'] ?? '' );
		$family = (string) ( $selection['pickup_family'] ?? $snapshot['pickup_family'] ?? '' );
		if ( YandexDeliverySettings::CARRIER_KEY !== $carrier && YandexDeliverySettings::CARRIER_KEY . ':pickup' !== $family ) {
			return '';
		}

		return $this->sanitize_station_id( (string) ( $selection['platform_station_id'] ?? $selection['point_code'] ?? $snapshot['platform_station_id'] ?? $snapshot['point_code'] ?? '' ) );
	}
	private function representative_destination_station_id( QuoteRequest $request ): string {
		if ( ! $this->location_mapping instanceof YandexLocationMappingV2Repository || ! $this->pickup_points instanceof YandexDeliveryPickupPointV2Repository ) {
			throw new YandexDeliveryApiException( 'Локальная база ПВЗ Яндекс.Доставки недоступна.', array( 'error_code' => 'pickup_repository_missing' ) );
		}
		$location_id = $this->destination_location_id( $request );
		if ( $location_id <= 0 ) {
			throw new YandexDeliveryApiException( 'Не выбран населенный пункт назначения для предварительного расчета Яндекс.Доставки.', array( 'error_code' => 'destination_location_missing' ) );
		}
		$geo_ids = $this->location_mapping->geo_ids_for_location( $location_id );
		if ( array() === $geo_ids ) {
			throw new YandexDeliveryApiException( 'Для населенного пункта назначения нет связанных yandex_geo_id.', array( 'error_code' => 'destination_geo_ids_missing', 'location_id' => $location_id ) );
		}
		$point = $this->pickup_points->representative_destination_pickup_point_by_geo_ids( $geo_ids );
		$station_id = is_array( $point ) ? $this->sanitize_station_id( (string) ( $point['platform_station_id'] ?? '' ) ) : '';
		if ( '' === $station_id ) {
			throw new YandexDeliveryApiException( 'Нет подходящего ПВЗ Яндекс.Доставки для предварительного расчета.', array( 'error_code' => 'representative_destination_pickup_missing', 'location_id' => $location_id, 'yandex_geo_ids' => $geo_ids ) );
		}

		return $station_id;
	}

	private function destination_location_id( QuoteRequest $request ): int {
		foreach ( array( 'selected_location_id', 'location_id', 'destination_location_id' ) as $key ) {
			$value = (int) ( $request->customer_context[ $key ] ?? 0 );
			if ( $value > 0 ) {
				return $value;
			}
		}

		return 0;
	}

	private function courier_address( QuoteRequest $request ): string {
		$address = $request->destination;
		$city = trim( '' !== trim( $address->city ) ? $address->city : $address->settlement );
		$raw = trim( $address->raw_address );
		if ( '' !== $raw ) {
			if ( '' === $city || 1 === preg_match( '/' . preg_quote( $city, '/' ) . '/iu', $raw ) ) {
				return $raw;
			}

			return $this->compact_address( array( $city, $raw ) );
		}
		$street = trim( $address->street );
		$house = trim( $address->house );
		if ( '' === $city || '' === $street || '' === $house ) {
			return '';
		}

		return $this->compact_address( array( $city, $street, $house, $address->apartment ) );
	}

	/** @param array<int,string> $parts */
	private function compact_address( array $parts ): string {
		$parts = array_values( array_filter( array_map( static fn( string $part ): string => trim( preg_replace( '/\s+/', ' ', $part ) ?? $part ), $parts ), static fn( string $part ): bool => '' !== $part ) );

		return implode( ', ', $parts );
	}

	private function disabled_reason( Throwable $exception, string $delivery_type ): string {
		$message = trim( $exception->getMessage() );
		if ( '' !== $message ) {
			return $message;
		}

		return DeliveryType::COURIER === $delivery_type ? 'Не удалось рассчитать курьерскую доставку Яндекс.Доставки.' : 'Не удалось рассчитать доставку Яндекс.Доставки до ПВЗ.';
	}

	private function log_courier_primary_error( Throwable $exception, QuoteRequest $request ): void {
		if ( ! $this->logger instanceof Logger ) {
			return;
		}
		$details = $exception instanceof YandexDeliveryApiException ? $exception->details() : array();
		$this->logger->warning(
			'Yandex Delivery primary courier pricing failed; pickup-address fallback will be attempted.',
			$this->settings->sanitize_for_diagnostics(
				array(
					'delivery_type' => DeliveryType::COURIER,
					'error' => $exception->getMessage(),
					'error_code' => $this->error_code( $exception ),
					'location_id' => $this->destination_location_id( $request ),
					'details' => $details,
				)
			)
		);
	}

	private function log_pricing_error( string $delivery_type, Throwable $exception, QuoteRequest $request ): void {
		if ( ! $this->logger instanceof Logger ) {
			return;
		}
		$details = $exception instanceof YandexDeliveryApiException ? $exception->details() : array();
		$this->logger->warning(
			'Yandex Delivery pricing-calculator failed.',
			$this->settings->sanitize_for_diagnostics(
				array(
					'delivery_type' => $delivery_type,
					'error' => $exception->getMessage(),
					'error_code' => $this->error_code( $exception ),
					'location_id' => $this->destination_location_id( $request ),
					'details' => $details,
				)
			)
		);
	}

	private function error_code( Throwable $exception ): string {
		if ( $exception instanceof YandexDeliveryApiException ) {
			return (string) ( $exception->details()['error_code'] ?? $exception->details()['yandex_error_code'] ?? 'pricing_error' );
		}

		return 'pricing_error';
	}

	private function sanitize_station_id( string $value ): string {
		return substr( preg_replace( '/[^A-Za-z0-9_-]+/', '', trim( $value ) ) ?? '', 0, 80 );
	}

	private function normalize_delivery_type( string $delivery_type ): string {
		if ( DeliveryType::COURIER === $delivery_type ) {
			return DeliveryType::COURIER;
		}
		if ( DeliveryType::PICKUP === $delivery_type ) {
			return DeliveryType::PICKUP;
		}

		return '';
	}

	private function quote_id( QuoteRequest $request, string $suffix ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $request->to_array() ) : json_encode( $request->to_array() );

		return 'yandex-delivery-' . $suffix . '-' . substr( sha1( is_string( $json ) ? $json : '' ), 0, 12 );
	}
}

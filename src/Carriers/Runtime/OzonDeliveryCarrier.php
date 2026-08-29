<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Contracts\CarrierQuoteCacheContextProviderInterface;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliveryCredentials;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
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

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryCarrier implements CarrierAdapterInterface, CarrierQuoteCacheContextProviderInterface {
	public const KEY = OzonDeliverySettings::CARRIER_KEY;
	public const RATE_ID = 'ozon_delivery:pickup';
	public const TARIFF_KEY = 'pickup';
	public const TARIFF_NAME = 'Ozon до ПВЗ';

	public function __construct(
		private OzonDeliverySettings $settings,
		private OzonDeliveryCredentials $credentials,
		private OzonDeliveryQuoteService $quotes,
		private Logger $logger
	) {}

	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( self::KEY, OzonDeliverySettings::TITLE, 'api', $this->runtime_enabled() );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities(
			supports_quotes: true,
			supports_pickup_points: true,
			supports_status_sync: false,
			supports_courier_delivery: false,
			supports_pickup_delivery: true,
			supports_international: false
		);
	}

	public function supports_country( string $countryCode ): bool {
		return 'RU' === strtoupper( trim( $countryCode ) ) && $this->runtime_enabled();
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		if ( ! $this->supports_country( $request->country_code ?: $request->destination->country_code ) ) {
			return $this->empty_quote( $request, 'ozon_delivery_runtime_unavailable' );
		}

		try {
			$result = $this->quotes->quote_pickup( $request );
		} catch ( OzonDeliveryQuoteException $exception ) {
			$this->logger->warning(
				'Ozon Delivery checkout quote unavailable.',
				array(
					'carrier' => self::KEY,
					'operation' => $exception->operation,
					'error_code' => $exception->safe_code,
					'http_status' => $exception->http_status,
				)
			);

			return $this->empty_quote( $request, $exception->safe_code, array( 'operation' => $exception->operation, 'http_status' => $exception->http_status ) );
		}

		return new DeliveryQuote(
			$this->quote_id( $request, $result ),
			self::KEY,
			$request->destination,
			$request->package,
			array( $this->rate_from_result( $result ) ),
			true,
			'',
			'',
			false,
			'api',
			array(
				'endpoint' => $result->endpoint,
				'destination_point_id' => $result->destination_point_id,
				'package_count' => $result->package_count,
			)
		);
	}

	/** @return array<string,mixed> */
	public function quote_cache_context( QuoteRequest $request ): array {
		$selections = is_array( $request->customer_context['pickup_selections'] ?? null ) ? $request->customer_context['pickup_selections'] : array();
		$selection = is_array( $selections[ OzonDeliverySettings::PICKUP_FAMILY ] ?? null ) ? $selections[ OzonDeliverySettings::PICKUP_FAMILY ] : array();
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();

		return array(
			'ozon_delivery_shipment_method_id' => $this->settings->shipment_method_id(),
			'ozon_delivery_pricing_gate' => $this->settings->pricing_live_confirmed() ? 'live_confirmed' : 'closed',
			'ozon_delivery_selected_point_id' => (string) ( $selection['point_code'] ?? $selection['point_id'] ?? $snapshot['point_code'] ?? '' ),
			'ozon_delivery_destination_latitude' => (string) ( $request->customer_context['destination_latitude'] ?? $request->customer_context['selected_location_latitude'] ?? $request->customer_context['lat'] ?? '' ),
			'ozon_delivery_destination_longitude' => (string) ( $request->customer_context['destination_longitude'] ?? $request->customer_context['selected_location_longitude'] ?? $request->customer_context['lng'] ?? '' ),
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
		return $this->credentials->is_complete() && $this->settings->shipment_method_id() > 0 && $this->settings->pricing_live_confirmed();
	}

	private function rate_from_result( OzonDeliveryQuoteResult $result ): DeliveryRate {
		return new DeliveryRate(
			self::RATE_ID,
			self::KEY,
			OzonDeliverySettings::TITLE,
			OzonDeliverySettings::SERVICE_KEY,
			OzonDeliverySettings::TITLE,
			self::TARIFF_KEY,
			self::TARIFF_NAME,
			DeliveryType::PICKUP,
			self::TARIFF_NAME,
			$result->price,
			null,
			null,
			$result->delivery_days,
			'',
			'',
			array(),
			false,
			'',
			true,
			false,
			array_merge(
				array(
					'preserve_rate_title' => true,
					'carrier_key' => self::KEY,
					'service_key' => OzonDeliverySettings::SERVICE_KEY,
					'delivery_type' => DeliveryType::PICKUP,
					'pickup_family' => OzonDeliverySettings::PICKUP_FAMILY,
					'requires_rate_refresh_on_pickup_selection' => true,
					'api_base_price_rub' => $result->price->get_rubles(),
					'ozon_delivery_base_price_kopecks' => $result->price->get_kopecks(),
					'ozon_delivery_destination_point_id' => $result->destination_point_id,
					'ozon_delivery_shipment_method_id' => $result->shipment_method_id,
					'ozon_delivery_package_count' => $result->package_count,
					'ozon_delivery_endpoint' => $result->endpoint,
					'ozon_delivery_http_status' => $result->http_status,
				),
				$result->meta
			),
			$result->price,
			$result->delivery_days
		);
	}

	/** @param array<string,mixed> $meta */
	private function empty_quote( QuoteRequest $request, string $code, array $meta = array() ): DeliveryQuote {
		return new DeliveryQuote( self::KEY . ':' . sha1( $code . wp_json_encode( $request->to_array() ) ), self::KEY, $request->destination, $request->package, array(), false, $code, 'Расчет Ozon Delivery недоступен.', false, 'api', $meta );
	}

	private function quote_id( QuoteRequest $request, OzonDeliveryQuoteResult $result ): string {
		return self::KEY . ':' . sha1( wp_json_encode( array( $request->to_array(), $result->destination_point_id, $result->package_count, $result->price->get_kopecks() ) ) ?: '' );
	}
}

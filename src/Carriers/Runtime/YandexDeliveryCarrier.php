<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

final class YandexDeliveryCarrier implements CarrierAdapterInterface {
	public const PICKUP_RATE_ID = 'yandex_pickup';
	public const COURIER_RATE_ID = 'yandex_courier';
	private const DEFAULT_DELIVERY_TIME = 'без указания срока';

	public function __construct( private YandexDeliverySettings $settings ) {
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
			'manual',
			array( 'temporary_checkout_rates' => true )
		);
	}

	private function rate( QuoteRequest $request, string $delivery_type ): DeliveryRate {
		$delivery_type = $this->normalize_delivery_type( $delivery_type ) ?: DeliveryType::PICKUP;
		$delivery_time = $this->delivery_time( $request, $delivery_type );
		$method_title = DeliveryType::COURIER === $delivery_type ? $this->settings->courier_method_title() : $this->settings->pickup_method_title();
		$title = $method_title . ' — ' . $delivery_time;

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
			Money::from_kopecks( 0 ),
			null,
			null,
			new DateRange(),
			'',
			$delivery_time,
			array(),
			false,
			'',
			DeliveryType::PICKUP === $delivery_type,
			DeliveryType::COURIER === $delivery_type,
			array(
				'preserve_rate_title' => true,
				'skip_rules' => true,
				'skip_service_post_processing' => true,
				'temporary_zero_price' => true,
				'delivery_time' => $delivery_time,
				'yandex_delivery_rate_type' => $delivery_type,
			)
		);
	}

	private function delivery_time( QuoteRequest $request, string $delivery_type ): string {
		$times = $request->customer_context['yandex_delivery_times'] ?? null;
		if ( is_array( $times ) && isset( $times[ $delivery_type ] ) ) {
			$time = trim( (string) $times[ $delivery_type ] );
			if ( '' !== $time ) {
				return $time;
			}
		}

		$key = 'yandex_delivery_time_' . $delivery_type;
		$time = trim( (string) ( $request->customer_context[ $key ] ?? '' ) );

		return '' !== $time ? $time : self::DEFAULT_DELIVERY_TIME;
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
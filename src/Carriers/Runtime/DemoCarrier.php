<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Pickup\Services\DemoPickupProvider;

defined( 'ABSPATH' ) || exit;

final class DemoCarrier implements CarrierAdapterInterface {
	public const KEY = 'demo';

	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( self::KEY, 'Тестовая доставка', 'fixed', true );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities(
			supports_quotes: true,
			supports_pickup_delivery: true,
			supports_courier_delivery: true,
			supports_international: false
		);
	}

	public function supports_country( string $countryCode ): bool {
		return 'RU' === strtoupper( trim( $countryCode ) );
	}

	/**
	 * @return array<int,\WallsShop\WDC\Domain\Pickup\PickupPoint>
	 */
	public function get_pickup_points( QuoteRequest $request ): array {
		if ( ! $this->supports_country( $request->country_code ) ) {
			return array();
		}

		return ( new DemoPickupProvider() )->get_points( self::KEY, $request->destination );
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		if ( ! $this->supports_country( $request->country_code ) ) {
			return new DeliveryQuote( $this->quote_id( $request ), self::KEY, $request->destination, $request->package, array(), true, '', '', false, 'manual' );
		}

		$requested_type = (string) ( $request->customer_context['delivery_type'] ?? '' );
		$rates          = array();

		if ( '' === $requested_type || DeliveryType::PICKUP === $requested_type ) {
			$rates[] = $this->rate( DeliveryType::PICKUP, 'Тестовый пункт выдачи', Money::from_rubles( 350 ), DateRange::single( 5 ), true );
		}

		if ( '' === $requested_type || DeliveryType::COURIER === $requested_type ) {
			$rates[] = $this->rate( DeliveryType::COURIER, 'Тестовая курьерская доставка', Money::from_rubles( 550 ), DateRange::single( 3 ), false );
		}

		return new DeliveryQuote( $this->quote_id( $request ), self::KEY, $request->destination, $request->package, $rates, true, '', '', false, 'manual' );
	}

	private function rate( string $delivery_type, string $title, Money $price, DateRange $days, bool $promo_like ): DeliveryRate {
		return new DeliveryRate(
			self::KEY . ':' . $delivery_type,
			self::KEY,
			'Тестовая доставка',
			$delivery_type,
			$title,
			$delivery_type,
			$title,
			$delivery_type,
			$title,
			$price,
			null,
			null,
			$days,
			'',
			$days->min_days . ' дн.',
			$promo_like ? array( 'Тестовый промо-тариф' ) : array(),
			false,
			'',
			DeliveryType::PICKUP === $delivery_type,
			DeliveryType::COURIER === $delivery_type,
			array( 'demo' => true, 'promo_like' => $promo_like )
		);
	}

	private function quote_id( QuoteRequest $request ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $request->to_array() ) : json_encode( $request->to_array() );

		return self::KEY . '-' . substr( sha1( is_string( $json ) ? $json : '' ), 0, 12 );
	}
}

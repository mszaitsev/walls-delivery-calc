<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

final class TestDemoCarrier implements CarrierAdapterInterface {
	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( 'demo', 'Test delivery', 'fixed', true );
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

	public function quote( QuoteRequest $request ): DeliveryQuote {
		if ( ! $this->supports_country( $request->country_code ) ) {
			return new DeliveryQuote( $this->quote_id( $request ), 'demo', $request->destination, $request->package, array(), true, '', '', false, 'manual' );
		}

		return new DeliveryQuote(
			$this->quote_id( $request ),
			'demo',
			$request->destination,
			$request->package,
			array(
				$this->rate( DeliveryType::PICKUP, 'Test pickup', Money::from_rubles( 350 ), DateRange::single( 5 ), true ),
				$this->rate( DeliveryType::COURIER, 'Test courier', Money::from_rubles( 550 ), DateRange::single( 3 ), false ),
			),
			true,
			'',
			'',
			false,
			'manual'
		);
	}

	private function rate( string $delivery_type, string $title, Money $price, DateRange $days, bool $promo_like ): DeliveryRate {
		return new DeliveryRate(
			'demo:' . $delivery_type,
			'demo',
			'Test delivery',
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
			$promo_like ? array( 'Test promo rate' ) : array(),
			false,
			'',
			DeliveryType::PICKUP === $delivery_type,
			DeliveryType::COURIER === $delivery_type,
			array( 'demo' => true, 'promo_like' => $promo_like )
		);
	}

	private function quote_id( QuoteRequest $request ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $request->to_array() ) : json_encode( $request->to_array() );

		return 'demo-' . substr( sha1( is_string( $json ) ? $json : '' ), 0, 12 );
	}
}

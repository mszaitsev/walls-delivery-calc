<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pricing;

use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryPricingRequestBuilder {
	private const DEFAULT_WEIGHT_G = 500;
	private const DEFAULT_DX = 20;
	private const DEFAULT_DY = 15;
	private const DEFAULT_DZ = 10;

	/** @return array<string,mixed> */
	public function pickup( QuoteRequest $request, string $source_platform_station_id, string $destination_platform_station_id ): array {
		return $this->base_payload(
			$request,
			$source_platform_station_id,
			array( 'platform_station_id' => $destination_platform_station_id ),
			'self_pickup'
		);
	}

	/** @return array<string,mixed> */
	public function courier( QuoteRequest $request, string $source_platform_station_id, string $destination_address ): array {
		return $this->base_payload(
			$request,
			$source_platform_station_id,
			array( 'address' => $destination_address ),
			'time_interval'
		);
	}

	/** @param array<string,string> $destination @return array<string,mixed> */
	private function base_payload( QuoteRequest $request, string $source_platform_station_id, array $destination, string $tariff ): array {
		$weight = $this->weight_gross( $request );
		$dims = $this->dimensions( $request );

		return array(
			'source' => array( 'platform_station_id' => $source_platform_station_id ),
			'destination' => $destination,
			'tariff' => $tariff,
			'total_weight' => $weight,
			'total_assessed_price' => $this->assessed_price_kopecks( $request ),
			'client_price' => 0,
			'payment_method' => 'already_paid',
			'places' => array(
				array(
					'physical_dims' => array(
						'weight_gross' => $weight,
						'dx' => $dims['dx'],
						'dy' => $dims['dy'],
						'dz' => $dims['dz'],
					),
				),
			),
		);
	}

	private function weight_gross( QuoteRequest $request ): int {
		return max( self::DEFAULT_WEIGHT_G, (int) $request->package->get_total_weight_g() );
	}

	/** @return array{dx:int,dy:int,dz:int} */
	private function dimensions( QuoteRequest $request ): array {
		return array(
			'dx' => max( self::DEFAULT_DX, (int) ( $request->package->length_cm ?? 0 ) ),
			'dy' => max( self::DEFAULT_DY, (int) ( $request->package->width_cm ?? 0 ) ),
			'dz' => max( self::DEFAULT_DZ, (int) ( $request->package->height_cm ?? 0 ) ),
		);
	}

	private function assessed_price_kopecks( QuoteRequest $request ): int {
		return max( 1, $request->order_total->get_kopecks(), $request->package->cart_total->get_kopecks(), $request->package->declared_value->get_kopecks() );
	}
}

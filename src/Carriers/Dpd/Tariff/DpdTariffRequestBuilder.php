<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Tariff;

defined( 'ABSPATH' ) || exit;

final class DpdTariffRequestBuilder {
	/**
	 * @return array<string,mixed>
	 */
	public function build( DpdTariffRequest $request ): array {
		$payload = array(
			'pickup' => array(
				'cityId' => $request->sender_city_id,
			),
			'delivery' => array(
				'cityId' => $request->receiver_city_id,
			),
			'selfPickup' => $request->self_pickup,
			'selfDelivery' => $request->self_delivery,
			'declaredValue' => $this->money_value( $request->declared_value_rub ),
			'parcel' => array_map( array( $this, 'parcel_payload' ), $request->parcels ),
		);

		if ( '' !== trim( $request->service_code ) ) {
			$payload['serviceCode'] = trim( $request->service_code );
		}
		if ( '' !== trim( $request->pickup_date ) ) {
			$payload['pickupDate'] = trim( $request->pickup_date );
		}

		return $payload;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function parcel_payload( DpdTariffParcel $parcel ): array {
		return array(
			'weight' => $this->kg_value( $parcel->weight_g ),
			'length' => $this->dimension_value( $parcel->length_cm ),
			'width' => $this->dimension_value( $parcel->width_cm ),
			'height' => $this->dimension_value( $parcel->height_cm ),
			'quantity' => max( 1, $parcel->quantity ),
		);
	}

	private function kg_value( int $weight_g ): float {
		return round( max( 1, $weight_g ) / 1000, 3 );
	}

	private function dimension_value( float $value ): float {
		return round( max( 0.1, $value ), 1 );
	}

	private function money_value( float $value ): float {
		return round( max( 0.0, $value ), 2 );
	}
}

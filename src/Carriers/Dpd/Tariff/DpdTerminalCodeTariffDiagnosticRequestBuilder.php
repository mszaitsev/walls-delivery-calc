<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Tariff;

defined( 'ABSPATH' ) || exit;

final class DpdTerminalCodeTariffDiagnosticRequestBuilder {
	/**
	 * @return array<string,mixed>
	 */
	public function build( DpdTerminalCodeTariffDiagnosticRequest $request ): array {
		$payload = array(
			'pickup' => array(
				'cityId' => $request->pickup_city_id,
			),
			'delivery' => array(
				'cityId' => $request->delivery_city_id,
				'terminalCode' => $request->delivery_terminal_code,
			),
			'selfPickup' => $request->self_pickup,
			'selfDelivery' => $request->self_delivery,
			'declaredValue' => $this->money_value( $request->declared_value_rub ),
			'parcel' => array_map( array( $this, 'parcel_payload' ), $request->parcels ),
		);

		if ( '' !== trim( $request->pickup_terminal_code ) ) {
			$payload['pickup']['terminalCode'] = trim( $request->pickup_terminal_code );
		}
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
			'weight' => round( max( 1, $parcel->weight_g ) / 1000, 3 ),
			'length' => round( max( 0.1, $parcel->length_cm ), 1 ),
			'width' => round( max( 0.1, $parcel->width_cm ), 1 ),
			'height' => round( max( 0.1, $parcel->height_cm ), 1 ),
			'quantity' => max( 1, $parcel->quantity ),
		);
	}

	private function money_value( float $value ): float {
		return round( max( 0.0, $value ), 2 );
	}
}

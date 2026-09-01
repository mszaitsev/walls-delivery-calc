<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Quote;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryCourierAddressMapper {
	/** @return array{courier:array{coordinates:array{latitude:float,longitude:float}}} */
	public function delivery( OzonDeliveryCourierLocation $location ): array {
		return array(
			'courier' => array(
				'coordinates' => array(
					'latitude'  => $location->latitude,
					'longitude' => $location->longitude,
				),
			),
		);
	}

	public function fingerprint( OzonDeliveryCourierLocation $location ): string {
		if ( 'dadata_address' === $location->source ) {
			return sprintf(
				'source=dadata_address|location_id=%d|lat=%s|lng=%s|address=%s',
				$location->location_id,
				$this->coordinate( $location->latitude ),
				$this->coordinate( $location->longitude ),
				$location->address_fingerprint
			);
		}

		return sprintf(
			'source=ozon_pickup_proxy|location_id=%d|point_id=%d|lat=%s|lng=%s',
			$location->location_id,
			(int) $location->proxy_point_id,
			$this->coordinate( $location->latitude ),
			$this->coordinate( $location->longitude )
		);
	}

	private function coordinate( float $value ): string {
		$formatted = rtrim( rtrim( sprintf( '%.8F', $value ), '0' ), '.' );
		return '' === $formatted ? '0' : $formatted;
	}
}

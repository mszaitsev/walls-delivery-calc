<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

defined( 'ABSPATH' ) || exit;

final class CreatedShipmentIdentityResolver {
	private const IDENTIFIER_KEYS = array(
		'tracking_number',
		'barcode',
		'external_id',
		'carrier_shipment_id',
		'shipment_id',
		'cdek_number',
		'dpd_order_number',
		'yandex_request_id',
		'request_id',
	);

	/**
	 * @param array<string,mixed> $shipment
	 */
	public function resolve( array $shipment ): ?CreatedShipmentIdentity {
		foreach ( self::IDENTIFIER_KEYS as $key ) {
			$value = trim( (string) ( $shipment[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return new CreatedShipmentIdentity( $key, $value );
			}
		}

		return null;
	}
}

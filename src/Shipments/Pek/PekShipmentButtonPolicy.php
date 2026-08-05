<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class PekShipmentButtonPolicy {
	/** @param array<string,mixed> $shipment @return array<string,bool> */
	public function resolve( array $shipment ): array {
		if ( array() === $shipment ) {
			return array( 'create' => true, 'manual_attach' => true, 'update' => false, 'cancel' => false, 'remove' => false );
		}
		$status = (string) ( $shipment['universal_status_code'] ?? '' );
		$accepted = '' !== (string) ( $shipment['pek_take_on_stock_datetime'] ?? '' )
			|| in_array( $status, array( DeliveryStatus::IN_TRANSIT, DeliveryStatus::READY_FOR_PICKUP, DeliveryStatus::HANDED_TO_COURIER, DeliveryStatus::DELIVERED, DeliveryStatus::RETURNING_TO_SENDER, DeliveryStatus::RETURNED_TO_SENDER ), true );

		return array(
			'create' => false,
			'manual_attach' => false,
			'update' => true,
			'cancel' => ! $accepted && ! in_array( $status, DeliveryStatus::terminal(), true ),
			'remove' => $accepted || in_array( $status, DeliveryStatus::terminal(), true ) || ! empty( $shipment['manual_attach'] ),
		);
	}
}

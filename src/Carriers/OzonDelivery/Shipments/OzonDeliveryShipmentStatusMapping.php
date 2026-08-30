<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentStatusMapping {
	/** @return array<string,string> */
	public static function map(): array {
		return array(
			'unknown' => DeliveryStatus::UNKNOWN,
			'created' => DeliveryStatus::PENDING_CREATION_IN_CARRIER,
			'forming' => DeliveryStatus::PENDING_CREATION_IN_CARRIER,
			'forming_failed' => DeliveryStatus::REJECTED,
			'ready_for_shipping' => DeliveryStatus::CREATED_IN_CARRIER,
			'in_container' => DeliveryStatus::IN_TRANSIT,
			'acceptance_in_progress' => DeliveryStatus::IN_TRANSIT,
			'on_way' => DeliveryStatus::IN_TRANSIT,
			'not_accepted_to_delivery' => DeliveryStatus::REJECTED,
			'in_delivery_point' => DeliveryStatus::READY_FOR_PICKUP,
			'in_courier_service' => DeliveryStatus::HANDED_TO_COURIER,
			'delivered' => DeliveryStatus::DELIVERED,
			'canceled' => DeliveryStatus::CANCELLED,
		);
	}

	public static function universal( string $ozon_status ): string {
		return self::map()[ $ozon_status ] ?? DeliveryStatus::UNKNOWN;
	}

	/** @param array<int,string> $statuses */
	public static function aggregate( array $statuses ): string {
		if ( array() === $statuses ) {
			return DeliveryStatus::UNKNOWN;
		}
		$universal = array_map( static fn( string $status ): string => self::universal( $status ), $statuses );
		if ( count( array_unique( $universal ) ) === 1 ) {
			return $universal[0];
		}
		if ( in_array( DeliveryStatus::REJECTED, $universal, true ) ) {
			return DeliveryStatus::REJECTED;
		}
		if ( in_array( DeliveryStatus::CANCELLED, $universal, true ) || in_array( DeliveryStatus::DELIVERED, $universal, true ) ) {
			return DeliveryStatus::UNKNOWN;
		}
		foreach ( array( DeliveryStatus::READY_FOR_PICKUP, DeliveryStatus::HANDED_TO_COURIER, DeliveryStatus::IN_TRANSIT, DeliveryStatus::CREATED_IN_CARRIER, DeliveryStatus::PENDING_CREATION_IN_CARRIER ) as $candidate ) {
			if ( in_array( $candidate, $universal, true ) ) {
				return $candidate;
			}
		}

		return DeliveryStatus::UNKNOWN;
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentCreationStatusPolicy {
	public const STATUS_STARTED = 'creation_confirmation_started';
	public const STATUS_EXHAUSTED = 'creation_confirmation_exhausted';
	public const PURPOSE = 'creation_confirmation';

	/** @param array<int,string> $statuses */
	public static function all_ready( array $statuses ): bool {
		return array() !== $statuses && array() === array_filter( $statuses, static fn( string $status ): bool => ! self::is_ready( $status ) );
	}

	/** @param array<int,string> $statuses */
	public static function any_waiting( array $statuses ): bool {
		foreach ( $statuses as $status ) {
			if ( self::is_waiting( $status ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<int,string> $statuses */
	public static function any_failure( array $statuses ): bool {
		foreach ( $statuses as $status ) {
			if ( self::is_failure( $status ) ) {
				return true;
			}
		}
		return false;
	}

	public static function is_waiting( string $status ): bool {
		return in_array( OzonDeliveryShipmentStatusMapping::normalize( $status ), array( 'created', 'forming' ), true );
	}

	public static function is_ready( string $status ): bool {
		return in_array(
			OzonDeliveryShipmentStatusMapping::normalize( $status ),
			array(
				'ready_for_shipping',
				'in_container',
				'acceptance_in_progress',
				'on_way',
				'in_delivery_point',
				'in_courier_service',
				'delivered',
				'moving',
				'at_the_pick_up_point',
				'received',
				'utilization',
				'utilized',
				'written_off',
				'looking_for',
			),
			true
		);
	}

	public static function is_failure( string $status ): bool {
		return in_array(
			OzonDeliveryShipmentStatusMapping::normalize( $status ),
			array( 'forming_failed', 'not_accepted_to_delivery', 'canceled' ),
			true
		);
	}
}

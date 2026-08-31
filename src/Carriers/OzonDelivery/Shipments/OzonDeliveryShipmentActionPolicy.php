<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentActionPolicy {
	private const CANCELLABLE = array(
		'created',
		'forming',
		'ready_for_shipping',
	);

	/** @return array<int,string> */
	public static function cancellable_statuses(): array {
		return self::CANCELLABLE;
	}

	/**
	 * @param array<int,string> $raw_statuses
	 * @return array{can_cancel:bool,can_remove:bool,can_update:bool}
	 */
	public static function for_statuses( array $raw_statuses ): array {
		$statuses = self::normalize_statuses( $raw_statuses );
		if ( array() === $statuses ) {
			return array( 'can_cancel' => false, 'can_remove' => true, 'can_update' => true );
		}

		foreach ( $statuses as $status ) {
			if ( ! in_array( $status, self::CANCELLABLE, true ) ) {
				return array( 'can_cancel' => false, 'can_remove' => true, 'can_update' => true );
			}
		}

		return array( 'can_cancel' => true, 'can_remove' => false, 'can_update' => true );
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array{can_cancel:bool,can_remove:bool,can_update:bool}
	 */
	public static function for_shipment( array $shipment ): array {
		if ( array() === $shipment ) {
			return array( 'can_cancel' => false, 'can_remove' => false, 'can_update' => false );
		}
		if ( 'cancellation_started' === (string) ( $shipment['status'] ?? '' ) ) {
			return array( 'can_cancel' => false, 'can_remove' => false, 'can_update' => true );
		}
		if ( 'cancellation_exhausted' === (string) ( $shipment['status'] ?? '' ) ) {
			return array( 'can_cancel' => false, 'can_remove' => true, 'can_update' => true );
		}

		return self::for_statuses( self::raw_statuses_from_shipment( $shipment ) );
	}

	/** @param array<string,mixed> $shipment @return array<int,string> */
	public static function raw_statuses_from_shipment( array $shipment ): array {
		$rows = is_array( $shipment['ozon_statuses'] ?? null ) ? $shipment['ozon_statuses'] : array();
		$statuses = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$status = trim( (string) ( $row['status'] ?? '' ) );
			if ( '' !== $status ) {
				$statuses[] = $status;
			}
		}

		return $statuses;
	}

	/** @param array<int,string> $raw_statuses */
	public static function all_cancelled( array $raw_statuses ): bool {
		$statuses = self::normalize_statuses( $raw_statuses );
		if ( array() === $statuses ) {
			return false;
		}
		foreach ( $statuses as $status ) {
			if ( 'canceled' !== $status ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<int,string> $raw_statuses @return array<int,string> */
	private static function normalize_statuses( array $raw_statuses ): array {
		$result = array();
		foreach ( $raw_statuses as $status ) {
			$normalized = OzonDeliveryShipmentStatusMapping::normalize( $status );
			if ( '' !== $normalized ) {
				$result[] = $normalized;
			}
		}

		return array_values( array_unique( $result ) );
	}
}

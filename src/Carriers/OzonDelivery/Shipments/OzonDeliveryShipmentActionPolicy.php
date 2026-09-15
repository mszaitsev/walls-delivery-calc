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
			return array( 'can_cancel' => false, 'can_remove' => false, 'can_update' => true );
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
		if ( in_array( (string) ( $shipment['status'] ?? '' ), array( 'cancellation_started', 'cancellation_exhausted', OzonDeliveryShipmentCreationStatusPolicy::STATUS_STARTED, OzonDeliveryShipmentCreationStatusPolicy::STATUS_EXHAUSTED ), true ) ) {
			return array( 'can_cancel' => false, 'can_remove' => self::has_identifier( $shipment ), 'can_update' => true );
		}
		if ( self::has_return_lifecycle( $shipment ) ) {
			return array( 'can_cancel' => false, 'can_remove' => self::has_identifier( $shipment ), 'can_update' => true );
		}

		$policy = self::for_statuses( self::raw_statuses_from_shipment( $shipment ) );
		if ( empty( $policy['can_cancel'] ) ) {
			$policy['can_remove'] = self::has_identifier( $shipment );
		}
		return $policy;
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

	/** @param array<string,mixed> $shipment */
	private static function has_identifier( array $shipment ): bool {
		foreach ( array( 'tracking_number', 'barcode', 'ozon_order_number', 'backlog_order_id' ) as $key ) {
			if ( '' !== trim( (string) ( $shipment[ $key ] ?? '' ) ) ) {
				return true;
			}
		}
		foreach ( array( 'ozon_postings' => 'posting_number', 'ozon_returns' => 'return_number' ) as $list_key => $value_key ) {
			foreach ( is_array( $shipment[ $list_key ] ?? null ) ? $shipment[ $list_key ] : array() as $row ) {
				if ( is_array( $row ) && '' !== trim( (string) ( $row[ $value_key ] ?? '' ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/** @param array<string,mixed> $shipment */
	private static function has_return_lifecycle( array $shipment ): bool {
		foreach ( is_array( $shipment['ozon_returns'] ?? null ) ? $shipment['ozon_returns'] : array() as $return ) {
			if ( is_array( $return ) && '' !== trim( (string) ( $return['return_number'] ?? '' ) ) ) {
				return true;
			}
		}
		$search = is_array( $shipment['ozon_return_search'] ?? null ) ? $shipment['ozon_return_search'] : array();
		if ( in_array( (string) ( $search['search_state'] ?? '' ), array( 'found', 'not_found', 'incomplete', 'error', 'info_error' ), true ) ) {
			return true;
		}
		$return_states = array( 'return_not_found', 'return_search_error', 'return_info_error', 'return_unknown', 'return_found_active', 'return_resolved' );
		foreach ( is_array( $shipment['ozon_postings'] ?? null ) ? $shipment['ozon_postings'] : array() as $posting ) {
			if ( is_array( $posting ) && in_array( (string) ( $posting['return_state'] ?? '' ), $return_states, true ) ) {
				return true;
			}
		}
		foreach ( is_array( $shipment['ozon_return_place_states'] ?? null ) ? $shipment['ozon_return_place_states'] : array() as $state ) {
			if ( is_array( $state ) && in_array( (string) ( $state['state'] ?? '' ), $return_states, true ) ) {
				return true;
			}
		}
		$universal = (string) ( $shipment['universal_status_code'] ?? $shipment['status'] ?? '' );
		return in_array( $universal, array( \WallsShop\WDC\Domain\Status\DeliveryStatus::RETURNING_TO_SENDER, \WallsShop\WDC\Domain\Status\DeliveryStatus::RETURNED_TO_SENDER ), true );
	}
}

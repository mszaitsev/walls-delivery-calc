<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Storage;

use WallsShop\WDC\Shipments\Application\ShipmentCreationAttemptService;

defined( 'ABSPATH' ) || exit;

final class OrderShipmentRepository {
	public const META_KEY = '_wdc_shipments';
	public const LAST_ERROR_META_KEY = '_wdc_shipment_last_error';

	/**
	 * @return array<string,mixed>
	 */
	public function all_for_order( object $order ): array {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$value = $order->get_meta( self::META_KEY, true );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function find_by_carrier( object $order, string $carrier_key ): array {
		$shipments = $this->all_for_order( $order );
		$shipment = $shipments[ $carrier_key ] ?? array();

		return is_array( $shipment ) ? $shipment : array();
	}

	public function has_created_for_carrier( object $order, string $carrier_key ): bool {
		$shipment = $this->find_by_carrier( $order, $carrier_key );
		$status = (string) ( $shipment['status'] ?? '' );

		return in_array( $status, array( 'pending_creation_in_carrier', 'registration_pending', 'reconciliation_required', 'cancellation_started', 'created', 'registered' ), true );
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	public function save_for_carrier( object $order, string $carrier_key, array $shipment ): void {
		if ( ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'save' ) ) {
			return;
		}

		$shipments = $this->all_for_order( $order );
		$shipments[ $carrier_key ] = $shipment;
		$order->update_meta_data( self::META_KEY, $shipments );
		$order->update_meta_data( self::LAST_ERROR_META_KEY, '' );
		$order->save();
		if ( function_exists( 'do_action' ) ) {
			do_action( 'wdc_shipment_record_changed', $order, $carrier_key, $shipment );
		}
	}

	public function delete_for_carrier( object $order, string $carrier_key ): void {
		if ( ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'save' ) ) {
			return;
		}

		$shipments = $this->all_for_order( $order );
		$shipment = is_array( $shipments[ $carrier_key ] ?? null ) ? $shipments[ $carrier_key ] : array();
		$this->mark_deleted_attempt_terminal( $order, $carrier_key, $shipment );
		unset( $shipments[ $carrier_key ] );
		$order->update_meta_data( self::META_KEY, $shipments );
		$order->update_meta_data( self::LAST_ERROR_META_KEY, '' );
		$order->save();
		if ( function_exists( 'do_action' ) ) {
			do_action( 'wdc_shipment_record_deleted', $order, $carrier_key );
		}
	}

	/** @param array<string,mixed> $shipment */
	private function mark_deleted_attempt_terminal( object $order, string $carrier_key, array $shipment ): void {
		$attempt_id = $shipment['creation_attempt_id'] ?? null;
		if ( ! is_string( $attempt_id ) || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $attempt_id ) ) {
			return;
		}
		if ( ! method_exists( $order, 'get_meta' ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		$service_key = trim( (string) ( $shipment['service_key'] ?? $shipment['rate_id'] ?? '' ) );
		if ( '' === $service_key ) {
			return;
		}
		$scope = $this->scope_key( $carrier_key, $service_key );
		$value = $order->get_meta( ShipmentCreationAttemptService::META_KEY, true );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( $value, true );
			$value = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		$record = is_array( $value[ $scope ] ?? null ) ? $value[ $scope ] : array();
		if ( strtolower( $attempt_id ) !== strtolower( (string) ( $record['current_attempt_id'] ?? '' ) ) ) {
			return;
		}
		$generation = $record['generation'] ?? null;
		if ( ! is_int( $generation ) || $generation < 1 ) {
			return;
		}
		$value[ $scope ] = array(
			'current_attempt_id' => strtolower( $attempt_id ),
			'generation' => $generation,
			'state' => ShipmentCreationAttemptService::STATE_TERMINAL,
			'updated_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
		);
		$order->update_meta_data( ShipmentCreationAttemptService::META_KEY, $value );
	}

	private function scope_key( string $carrier_key, string $service_key ): string {
		$normalize = static function ( string $value ): string {
			if ( function_exists( 'sanitize_key' ) ) {
				return sanitize_key( $value );
			}

			return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' );
		};

		return $normalize( $carrier_key ) . '|' . $normalize( $service_key );
	}

	/**
	 * @param array<string,mixed> $error
	 */
	public function save_last_error( object $order, array $error ): void {
		if ( ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'save' ) ) {
			return;
		}

		$order->update_meta_data( self::LAST_ERROR_META_KEY, $error );
		$order->save();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function last_error( object $order ): array {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$value = $order->get_meta( self::LAST_ERROR_META_KEY, true );

		return is_array( $value ) ? $value : array();
	}
}

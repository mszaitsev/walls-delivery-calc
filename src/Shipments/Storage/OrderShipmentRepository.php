<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Storage;

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

		return in_array( $status, array( 'created', 'registered' ), true );
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
	}

	public function delete_for_carrier( object $order, string $carrier_key ): void {
		if ( ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'save' ) ) {
			return;
		}

		$shipments = $this->all_for_order( $order );
		unset( $shipments[ $carrier_key ] );
		$order->update_meta_data( self::META_KEY, $shipments );
		$order->update_meta_data( self::LAST_ERROR_META_KEY, '' );
		$order->save();
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

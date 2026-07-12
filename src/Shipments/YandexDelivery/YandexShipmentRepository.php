<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class YandexShipmentRepository {
	public const REQUEST_ID_META_KEY = '_wdc_yandex_delivery_request_id';

	public function __construct( private OrderShipmentRepository $repository ) {}

	/** @return array<string,mixed> */
	public function find( object $order ): array { return $this->repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY ); }

	/** @param array<string,mixed> $shipment */
	public function save( object $order, array $shipment ): void {
		if ( ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'save' ) ) {
			return;
		}
		$shipments = $this->repository->all_for_order( $order );
		$shipments[ YandexDeliverySettings::CARRIER_KEY ] = $shipment;
		$order->update_meta_data( OrderShipmentRepository::META_KEY, $shipments );
		$order->update_meta_data( OrderShipmentRepository::LAST_ERROR_META_KEY, '' );
		$this->sync_lookup_meta( $order, $shipment );
		$order->save();
	}

	/** @param array<string,mixed> $shipment */
	public function sync_lookup_meta( object $order, array $shipment ): void {
		$request_id = trim( (string) ( $shipment['yandex_request_id'] ?? $shipment['request_id'] ?? $shipment['external_id'] ?? '' ) );
		if ( '' !== $request_id && method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( self::REQUEST_ID_META_KEY, $request_id );
		}
	}

	public function delete( object $order ): void {
		if ( ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'save' ) ) {
			return;
		}
		$shipments = $this->repository->all_for_order( $order );
		unset( $shipments[ YandexDeliverySettings::CARRIER_KEY ] );
		$order->update_meta_data( OrderShipmentRepository::META_KEY, $shipments );
		$order->update_meta_data( OrderShipmentRepository::LAST_ERROR_META_KEY, '' );
		if ( method_exists( $order, 'delete_meta_data' ) ) {
			$order->delete_meta_data( self::REQUEST_ID_META_KEY );
		} else {
			$order->update_meta_data( self::REQUEST_ID_META_KEY, '' );
		}
		$order->save();
	}

	public function order_id( object $order ): int { return method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0; }
}

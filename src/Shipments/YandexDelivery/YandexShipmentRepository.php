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
		$this->repository->save_for_carrier( $order, YandexDeliverySettings::CARRIER_KEY, $shipment );
		$request_id = trim( (string) ( $shipment['yandex_request_id'] ?? $shipment['request_id'] ?? $shipment['external_id'] ?? '' ) );
		if ( '' !== $request_id && method_exists( $order, 'update_meta_data' ) && method_exists( $order, 'save' ) ) {
			$order->update_meta_data( self::REQUEST_ID_META_KEY, $request_id );
			$order->save();
		}
	}

	public function delete( object $order ): void {
		$this->repository->delete_for_carrier( $order, YandexDeliverySettings::CARRIER_KEY );
		if ( method_exists( $order, 'delete_meta_data' ) && method_exists( $order, 'save' ) ) {
			$order->delete_meta_data( self::REQUEST_ID_META_KEY );
			$order->save();
		} elseif ( method_exists( $order, 'update_meta_data' ) && method_exists( $order, 'save' ) ) {
			$order->update_meta_data( self::REQUEST_ID_META_KEY, '' );
			$order->save();
		}
	}

	public function order_id( object $order ): int { return method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0; }
}

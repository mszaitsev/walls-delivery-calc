<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Analytics\Storage\ShipmentCostAnalyticsRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsIndexer {
	public const SHIPMENT_CHANGED_HOOK = 'wdc_shipment_record_changed';
	public const SHIPMENT_DELETED_HOOK = 'wdc_shipment_record_deleted';

	public function __construct(
		private ShipmentCostAnalyticsRecordBuilder $builder,
		private ShipmentCostAnalyticsRepository $repository,
		private Logger $logger
	) {
	}

	public function sync_order( object|int $order ): void {
		try {
			$order_object = is_object( $order ) ? $order : $this->order_from_id( $order );
			if ( ! is_object( $order_object ) ) {
				if ( is_int( $order ) ) {
					$this->repository->delete_by_order_id( $order );
				}
				return;
			}
			$order_id = method_exists( $order_object, 'get_id' ) ? (int) $order_object->get_id() : 0;
			$record = $this->builder->build( $order_object );
			if ( null === $record ) {
				if ( $order_id > 0 ) {
					$this->repository->delete_by_order_id( $order_id );
				}
				return;
			}
			$this->repository->upsert( $record );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Shipment cost analytics sync failed.',
				array(
					'order_id' => is_int( $order ) ? $order : ( method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0 ),
					'error' => $e->getMessage(),
				)
			);
		}
	}

	public function delete_order( int $order_id ): void {
		try {
			$this->repository->delete_by_order_id( $order_id );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Shipment cost analytics delete failed.',
				array(
					'order_id' => $order_id,
					'error' => $e->getMessage(),
				)
			);
		}
	}

	public function handle_order_deleted( mixed $order_id ): void {
		$this->delete_order( (int) $order_id );
	}

	public function handle_order_restored( mixed $order_id ): void {
		$this->sync_order( (int) $order_id );
	}

	public function handle_shipment_changed( mixed $order, mixed $carrier_key = '' ): void {
		unset( $carrier_key );
		if ( is_object( $order ) || is_int( $order ) || ( is_string( $order ) && is_numeric( $order ) ) ) {
			$this->sync_order( is_object( $order ) ? $order : (int) $order );
		}
	}

	private function order_from_id( int $order_id ): ?object {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );

		return is_object( $order ) ? $order : null;
	}
}

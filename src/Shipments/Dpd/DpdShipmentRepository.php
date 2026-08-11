<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Shipments\Application\ShipmentCreationAttemptService;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class DpdShipmentRepository {
	public const ORDER_NUMBER_META_KEY = '_wdc_dpd_order_number';

	public function __construct(
		private OrderShipmentRepository $repository,
		private ?ShipmentCreationAttemptService $attempts = null
	) {}

	/** @return array<string,mixed> */
	public function find( object $order ): array { return $this->repository->find_by_carrier( $order, DpdSettings::CARRIER_KEY ); }

	/** @param array<string,mixed> $shipment */
	public function save( object $order, array $shipment ): void {
		$this->repository->save_for_carrier( $order, DpdSettings::CARRIER_KEY, $shipment );
		$number = trim( (string) ( $shipment['dpd_order_number'] ?? $shipment['tracking_number'] ?? '' ) );
		if ( '' !== $number && method_exists( $order, 'update_meta_data' ) && method_exists( $order, 'save' ) ) {
			$order->update_meta_data( self::ORDER_NUMBER_META_KEY, $number );
			$order->save();
		}
	}

	public function delete( object $order ): void {
		$shipment = $this->repository->find_by_carrier( $order, DpdSettings::CARRIER_KEY );
		if ( $this->attempts instanceof ShipmentCreationAttemptService ) {
			$this->attempts->mark_terminal_for_shipment( $order, DpdSettings::CARRIER_KEY, $shipment, 'local_removed' );
		}
		$this->repository->delete_for_carrier( $order, DpdSettings::CARRIER_KEY );
		if ( method_exists( $order, 'delete_meta_data' ) && method_exists( $order, 'save' ) ) {
			$order->delete_meta_data( self::ORDER_NUMBER_META_KEY );
			$order->save();
		} elseif ( method_exists( $order, 'update_meta_data' ) && method_exists( $order, 'save' ) ) {
			$order->update_meta_data( self::ORDER_NUMBER_META_KEY, '' );
			$order->save();
		}
	}

	public function find_order_by_dpd_order_number( string $dpd_order_number ): ?object {
		$dpd_order_number = trim( $dpd_order_number );
		if ( '' === $dpd_order_number || ! function_exists( 'wc_get_orders' ) ) { return null; }
		$orders = wc_get_orders( array( 'limit' => 2, 'return' => 'objects', 'meta_key' => self::ORDER_NUMBER_META_KEY, 'meta_value' => $dpd_order_number ) );
		return 1 === count( $orders ) && is_object( $orders[0] ) ? $orders[0] : null;
	}

	public function find_order_by_client_order_number( string $client_order_number ): ?object {
		$client_order_number = trim( $client_order_number );
		if ( '' === $client_order_number || ! ctype_digit( $client_order_number ) || ! function_exists( 'wc_get_order' ) ) { return null; }
		$order = wc_get_order( (int) $client_order_number );
		return is_object( $order ) ? $order : null;
	}

	public function order_id( object $order ): int { return method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0; }
}

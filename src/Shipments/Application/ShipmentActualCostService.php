<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentActualCostService {
	public function __construct(
		private OrderShipmentRepository $repository,
		private ShipmentActualCostResolver $resolver
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function manual_set( object $order, string $carrier_key, int $amount_kopecks ): array {
		if ( $amount_kopecks <= 0 ) {
			throw new \InvalidArgumentException( 'Фактическая стоимость должна быть больше нуля.' );
		}

		return $this->save_cost( $order, $carrier_key, new ShipmentActualCost( $amount_kopecks, 'RUB', 'manual', 'admin_manual', $this->now() ), true );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function clear( object $order, string $carrier_key ): array {
		$shipment = $this->existing_shipment( $order, $carrier_key );
		foreach ( array( 'actual_cost_kopecks', 'actual_cost_currency', 'actual_cost_source', 'actual_cost_source_detail', 'actual_cost_updated_at', 'russian_post_actual_cost_kopecks', 'russian_post_actual_cost_rub', 'russian_post_actual_cost_source' ) as $key ) {
			unset( $shipment[ $key ] );
		}
		$shipment['updated_at'] = $this->now();
		$this->repository->save_for_carrier( $order, $carrier_key, $shipment );

		return $shipment;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function apply_carrier_cost( object $order, string $carrier_key, ShipmentActualCost $cost ): array {
		if ( $cost->amount_kopecks <= 0 ) {
			return $this->existing_shipment( $order, $carrier_key );
		}

		return $this->save_cost( $order, $carrier_key, $cost, false );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function save_cost( object $order, string $carrier_key, ShipmentActualCost $cost, bool $manual ): array {
		$now = $this->now();
		$shipment = $this->resolver->with_legacy_canonical_fields( $this->existing_shipment( $order, $carrier_key ), $now );
		$fields = $cost->to_fields( $now );
		if ( $manual ) {
			$fields['actual_cost_source'] = 'manual';
			$fields['actual_cost_source_detail'] = 'admin_manual';
		}
		$shipment = array_merge( $shipment, $fields, array( 'updated_at' => $now ) );
		$this->repository->save_for_carrier( $order, $carrier_key, $shipment );

		return $shipment;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function existing_shipment( object $order, string $carrier_key ): array {
		$carrier_key = $this->sanitize_carrier_key( $carrier_key );
		if ( '' === $carrier_key ) {
			throw new \InvalidArgumentException( 'Shipment carrier key is required.' );
		}
		$shipment = $this->repository->find_by_carrier( $order, $carrier_key );
		if ( array() === $shipment ) {
			throw new \InvalidArgumentException( 'Shipment not found.' );
		}

		return $shipment;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function sanitize_carrier_key( string $carrier_key ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( $carrier_key );
		}

		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $carrier_key ) ?? '' );
	}
}

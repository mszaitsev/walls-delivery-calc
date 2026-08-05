<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseService;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;

defined( 'ABSPATH' ) || exit;

final class PekShipmentSenderWarehouseResolver {
	public function __construct(
		private PekSettings $settings,
		private PekSenderWarehouseService $warehouses
	) {
	}

	/** @return array<string,mixed> */
	public function resolve( ShipmentCreateRequest $request ): array {
		$override = trim( (string) ( $request->meta['pek_sender_warehouse_id'] ?? $request->meta['sender_warehouse_id'] ?? '' ) );
		if ( '' !== $override ) {
			$result = $this->warehouses->validate_snapshot( $override, $this->constraints( $request ) );
			$snapshot = is_array( $result['snapshot'] ?? null ) ? $result['snapshot'] : array();
			if ( empty( $result['success'] ) || array() === $snapshot ) {
				throw new \RuntimeException( 'ПЭК не подтвердил выбранный склад самопривоза.' );
			}
			$snapshot['source'] = 'shipment_modal_override';

			return $this->assert_limits( $snapshot, $request );
		}

		$snapshot = $this->settings->sender_warehouse();
		if ( array() === $snapshot ) {
			throw new \RuntimeException( 'В настройках ПЭК не выбран склад самопривоза отправителя.' );
		}
		$result = $this->warehouses->validate_snapshot( (string) ( $snapshot['warehouseId'] ?? '' ), $this->constraints( $request ) );
		$fresh = is_array( $result['snapshot'] ?? null ) ? $result['snapshot'] : array();
		if ( empty( $result['success'] ) || array() === $fresh ) {
			throw new \RuntimeException( 'ПЭК не подтвердил склад самопривоза из настроек.' );
		}
		$snapshot = $fresh;
		$snapshot['source'] = (string) ( $snapshot['source'] ?? 'settings_default' );

		return $this->assert_limits( $snapshot, $request );
	}

	/** @param array<string,mixed> $warehouse @return array<string,mixed> */
	private function assert_limits( array $warehouse, ShipmentCreateRequest $request ): array {
		$limits = is_array( $warehouse['limits'] ?? null ) ? $warehouse['limits'] : array();
		$total_weight = 0;
		$total_volume = 0;
		$max_weight = 0;
		$max_dimension = 0;
		foreach ( $request->places as $place ) {
			$total_weight += (int) $place->weight_g;
			$total_volume += (int) $place->get_volume_cm3();
			$max_weight = max( $max_weight, (int) $place->weight_g );
			$max_dimension = max( $max_dimension, (int) $place->length_cm, (int) $place->width_cm, (int) $place->height_cm );
		}
		$this->limit( $limits['maxWeight'] ?? null, $total_weight / 1000, 'Вес груза превышает лимит склада ПЭК.' );
		$this->limit( $limits['maxVolume'] ?? null, $total_volume / 1000000, 'Объём груза превышает лимит склада ПЭК.' );
		$this->limit( $limits['maxWeightOnePlace'] ?? $limits['maxWeightPerPlace'] ?? null, $max_weight / 1000, 'Вес грузоместа превышает лимит склада ПЭК.' );
		$this->limit( $limits['maxDimension'] ?? null, $max_dimension / 100, 'Габарит грузоместа превышает лимит склада ПЭК.' );
		$this->limit( $limits['maxCount'] ?? null, count( $request->places ), 'Количество грузомест превышает лимит склада ПЭК.' );

		return $warehouse;
	}

	private function limit( mixed $limit, float|int $value, string $message ): void {
		if ( is_numeric( $limit ) && (float) $limit > 0 && $value > (float) $limit ) {
			throw new \RuntimeException( $message );
		}
	}

	private function constraints( ShipmentCreateRequest $request ): PickupCargoConstraints {
		$weight = 0;
		$volume = 0;
		$max_dimension = 0;
		$max_place_weight = 0;
		foreach ( $request->places as $place ) {
			if ( ! $place instanceof ShipmentPlace ) {
				continue;
			}
			$weight += max( 0, $place->weight_g );
			$volume += max( 0, $place->get_volume_cm3() );
			$max_dimension = max( $max_dimension, $place->length_cm, $place->width_cm, $place->height_cm );
			$max_place_weight = max( $max_place_weight, $place->weight_g );
		}

		return new PickupCargoConstraints( $weight, $volume, $max_dimension, $max_place_weight, max( 1, count( $request->places ) ) );
	}
}

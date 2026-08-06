<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class PekManualAttachContextResolver {
	public function __construct(
		private OrderShipmentDraftFactory $drafts,
		private OrderShipmentRepository $repository
	) {
	}

	/** @param array<string,mixed> $existing_shipment @return array<string,mixed> */
	public function resolve( object $order, array $existing_shipment = array() ): array {
		if ( array() === $existing_shipment ) {
			$existing_shipment = $this->repository->find_by_carrier( $order, PekSettings::CARRIER_KEY );
		}
		try {
			$request = $this->drafts->create_request_from_order( $order );
		} catch ( \Throwable ) {
			$request = null;
		}
		if ( is_object( $request ) && ( PekSettings::CARRIER_KEY !== $request->carrier_key || $request->order_id !== (int) ( method_exists( $order, 'get_id' ) ? $order->get_id() : $request->order_id ) ) ) {
			$request = null;
		}
		if ( null === $request && array() === $existing_shipment ) {
			throw new \RuntimeException( 'Не удалось восстановить данные отправления ПЭК для ручного прикрепления.' );
		}
		$summary = is_array( $existing_shipment['request_summary'] ?? null ) ? $existing_shipment['request_summary'] : array();
		$sender = is_array( $summary['sender_warehouse'] ?? null ) ? $summary['sender_warehouse'] : array();
		$sms = is_array( $summary['sms'] ?? null ) ? $summary['sms'] : array();
		$order_id = is_object( $request ) ? $request->order_id : (int) ( $existing_shipment['order_id'] ?? ( method_exists( $order, 'get_id' ) ? $order->get_id() : 0 ) );
		$delivery_type = trim( (string) ( $existing_shipment['delivery_type'] ?? $existing_shipment['shipment_mode'] ?? ( is_object( $request ) ? $request->delivery_type : '' ) ) );
		if ( '' === $delivery_type ) {
			throw new \RuntimeException( 'Не удалось восстановить данные отправления ПЭК для ручного прикрепления.' );
		}

		return array(
			'order_id' => $order_id,
			'service_key' => (string) ( $existing_shipment['service_key'] ?? PekSettings::SERVICE_KEY ),
			'service_title' => (string) ( $existing_shipment['service_title'] ?? PekSettings::TITLE ),
			'delivery_type' => $delivery_type,
			'shipment_mode' => (string) ( $existing_shipment['shipment_mode'] ?? $delivery_type ),
			'rate_id' => (string) ( $existing_shipment['rate_id'] ?? ( is_object( $request ) ? $request->rate_id : '' ) ),
			'places' => $this->context_places( $existing_shipment, $request ),
			'order_num' => (string) ( $existing_shipment['order_num'] ?? ( is_object( $request ) ? ( $request->meta['order_num'] ?? $order_id ) : $order_id ) ),
			'pek_sender_warehouse_id' => (string) ( $existing_shipment['pek_sender_warehouse_id'] ?? $sender['warehouseId'] ?? '' ),
			'pek_sender_warehouse_title' => (string) ( $existing_shipment['pek_sender_warehouse_title'] ?? $sender['divisionName'] ?? $sender['branchName'] ?? '' ),
			'pek_sender_warehouse_source' => (string) ( $existing_shipment['pek_sender_warehouse_source'] ?? $sender['source'] ?? '' ),
			'pek_receiver_warehouse_id' => (string) ( $existing_shipment['pek_receiver_warehouse_id'] ?? $summary['receiver_warehouse_id'] ?? ( is_object( $request ) ? ( $request->meta['pek_receiver_warehouse_id'] ?? '' ) : '' ) ),
			'pek_receiver_warehouse_source' => (string) ( $existing_shipment['pek_receiver_warehouse_source'] ?? '' ),
			'pek_receiver_branch_id' => (string) ( $existing_shipment['pek_receiver_branch_id'] ?? $summary['receiver_branch_id'] ?? ( is_object( $request ) ? ( $request->meta['pek_receiver_branch_id'] ?? '' ) : '' ) ),
			'recipient_type' => (string) ( $existing_shipment['recipient_type'] ?? 'physical' ),
			'declared_value_kopecks' => (int) ( $existing_shipment['declared_value_kopecks'] ?? $summary['declared_value_kopecks'] ?? 0 ),
			'sealing_requested' => ! empty( $existing_shipment['sealing_requested'] ) || ! empty( $summary['sealing_requested'] ),
			'sms_release_requested' => ! empty( $existing_shipment['sms_release_requested'] ),
			'sms_release_confirmed' => ! empty( $existing_shipment['sms_release_confirmed'] ) || ! empty( $sms['success'] ),
			'sms_release_effective_limit_kopecks' => (int) ( $existing_shipment['sms_release_effective_limit_kopecks'] ?? $sms['effective_limit_kopecks'] ?? 0 ),
			'request_snapshot' => is_array( $existing_shipment['request_snapshot'] ?? null ) ? $existing_shipment['request_snapshot'] : array(),
			'request_summary' => $summary,
			'pek_correlation' => (string) ( $existing_shipment['pek_correlation'] ?? $summary['correlation'] ?? '' ),
			'original_created_at' => (string) ( $existing_shipment['created_at'] ?? '' ),
		);
	}

	/** @param array<int,mixed> $places @return array<int,array<string,mixed>> */
	private function places( array $places ): array {
		$result = array();
		foreach ( $places as $place ) {
			if ( ! $place instanceof ShipmentPlace ) {
				continue;
			}
			$result[] = array(
				'place_number' => $place->place_number,
				'weight_g' => $place->weight_g,
				'length_cm' => $place->length_cm,
				'width_cm' => $place->width_cm,
				'height_cm' => $place->height_cm,
			);
		}

		return $result;
	}

	/** @param array<string,mixed> $existing_shipment @return array<int,array<string,mixed>> */
	private function context_places( array $existing_shipment, ?object $request ): array {
		if ( array_key_exists( 'places', $existing_shipment ) ) {
			$places = $existing_shipment['places'];
			if ( ! is_array( $places ) || array() === $places ) {
				throw new \RuntimeException( 'Не удалось восстановить данные отправления ПЭК для ручного прикрепления.' );
			}
			$result = array();
			$numbers = array();
			foreach ( $places as $place ) {
				if ( ! is_array( $place ) || array_is_list( $place ) ) {
					throw new \RuntimeException( 'Не удалось восстановить данные отправления ПЭК для ручного прикрепления.' );
				}
				foreach ( $place as $value ) {
					if ( is_object( $value ) || is_resource( $value ) ) {
						throw new \RuntimeException( 'Не удалось восстановить данные отправления ПЭК для ручного прикрепления.' );
					}
				}
				$number = $this->positive_int_field( $place, 'place_number', 1, 1000 );
				if ( isset( $numbers[ $number ] ) ) {
					throw new \RuntimeException( 'Не удалось восстановить данные отправления ПЭК для ручного прикрепления.' );
				}
				$numbers[ $number ] = true;
				$result[] = array(
					'place_number' => $number,
					'weight_g' => $this->positive_int_field( $place, 'weight_g', 1, 100000000 ),
					'length_cm' => $this->positive_int_field( $place, 'length_cm', 1, 100000 ),
					'width_cm' => $this->positive_int_field( $place, 'width_cm', 1, 100000 ),
					'height_cm' => $this->positive_int_field( $place, 'height_cm', 1, 100000 ),
				);
			}

			return $result;
		}
		if ( is_object( $request ) ) {
			return $this->places( $request->places );
		}

		throw new \RuntimeException( 'Не удалось восстановить данные отправления ПЭК для ручного прикрепления.' );
	}

	/** @param array<string,mixed> $row */
	private function positive_int_field( array $row, string $key, int $min, int $max ): int {
		$value = $row[ $key ] ?? null;
		if ( ! is_int( $value ) || $value < $min || $value > $max ) {
			throw new \RuntimeException( 'Не удалось восстановить данные отправления ПЭК для ручного прикрепления.' );
		}

		return $value;
	}
}

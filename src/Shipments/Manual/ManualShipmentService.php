<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Manual;

use WallsShop\WDC\Carriers\Manual\ManualDeliverySettings;
use WallsShop\WDC\Domain\Common\MoneyParser;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class ManualShipmentService {
	public function __construct(
		private OrderShipmentRepository $repository,
		private ShipmentActualCostService $actual_costs
	) {
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function attach_manual( object $order, array $payload ): array {
		$context = $this->order_delivery_context( $order );
		if ( ManualDeliverySettings::CARRIER_KEY !== $context['carrier_key'] ) {
			return array( 'success' => false, 'message' => 'Заказ не относится к ручной доставке.' );
		}
		if ( '' === $context['service_key'] ) {
			return array( 'success' => false, 'message' => 'Не удалось определить ручную службу доставки заказа.' );
		}
		$number = $this->shipment_number( $payload );
		if ( '' === $number ) {
			return array( 'success' => false, 'message' => 'Введите номер отправления.' );
		}
		if ( $this->contains_control_chars( $number ) || $this->string_length( $number ) > 96 ) {
			return array( 'success' => false, 'message' => 'Некорректный номер отправления.' );
		}
		if ( array() !== $this->repository->find_by_carrier( $order, ManualDeliverySettings::CARRIER_KEY ) ) {
			return array( 'success' => false, 'message' => 'Для заказа уже сохранено ручное отправление.' );
		}
		$actual_cost = $this->optional_actual_cost_kopecks( $payload );

		$now = $this->now();
		$shipment = array(
			'carrier_key' => ManualDeliverySettings::CARRIER_KEY,
			'service_key' => $context['service_key'],
			'service_title' => $context['service_title'],
			'rate_id' => $context['rate_id'],
			'delivery_type' => $context['delivery_type'],
			'status' => 'attached_manually',
			'status_title' => 'Отправление добавлено вручную',
			'tracking_number' => $number,
			'barcode' => $number,
			'external_id' => $number,
			'manual_attach' => true,
			'universal_status_code' => DeliveryStatus::UNKNOWN,
			'universal_status_label' => DeliveryStatus::label( DeliveryStatus::UNKNOWN ),
			'request_snapshot' => array(
				'source' => 'admin_manual_attach',
				'carrier_key' => ManualDeliverySettings::CARRIER_KEY,
				'service_key' => $context['service_key'],
				'service_title' => $context['service_title'],
				'rate_id' => $context['rate_id'],
				'delivery_type' => $context['delivery_type'],
			),
			'created_at' => $now,
			'updated_at' => $now,
		);
		$this->repository->save_for_carrier( $order, ManualDeliverySettings::CARRIER_KEY, $shipment );
		if ( null !== $actual_cost ) {
			$shipment = $this->actual_costs->manual_set( $order, ManualDeliverySettings::CARRIER_KEY, $actual_cost );
		}

		return array( 'success' => true, 'message' => 'Ручное отправление добавлено.', 'tracking_number' => $number, 'shipment' => $shipment );
	}

	/** @return array<string,mixed> */
	public function remove_local( object $order ): array {
		$this->repository->delete_for_carrier( $order, ManualDeliverySettings::CARRIER_KEY );

		return array( 'success' => true, 'message' => 'Ручное отправление удалено из заказа локально.', 'removed' => true );
	}

	/** @return array{carrier_key:string,service_key:string,service_title:string,rate_id:string,delivery_type:string} */
	private function order_delivery_context( object $order ): array {
		$calculation = $this->calculation_data( $order );
		$carrier_key = $this->normalize_key(
			$this->first_string(
				$this->meta_string( $order, '_wdc_platform_carrier_key' ),
				$calculation['carrier_key'] ?? null,
				$calculation['carrier']['carrier_key'] ?? null,
				$calculation['service']['carrier_key'] ?? null,
				$calculation['rate']['carrier_key'] ?? null,
				$calculation['result']['carrier_key'] ?? null
			)
		);
		$service_key = $this->normalize_key(
			$this->first_string(
				$this->meta_string( $order, '_wdc_platform_service_key' ),
				$calculation['service_key'] ?? null,
				$calculation['carrier']['service_key'] ?? null,
				$calculation['service']['service_key'] ?? null,
				$calculation['service']['key'] ?? null,
				$calculation['rate']['service_key'] ?? null,
				$calculation['result']['service_key'] ?? null
			)
		);

		return array(
			'carrier_key' => $carrier_key,
			'service_key' => $service_key,
			'service_title' => $this->first_string(
				$this->meta_string( $order, '_wdc_platform_service_title' ),
				$calculation['service_title'] ?? null,
				$calculation['service']['title'] ?? null,
				$calculation['rate']['service_title'] ?? null,
				$calculation['result']['service_title'] ?? null
			),
			'rate_id' => $this->first_string(
				$this->meta_string( $order, '_wdc_platform_rate_id' ),
				$calculation['rate_id'] ?? null,
				$calculation['rate']['rate_id'] ?? null,
				$calculation['result']['rate_id'] ?? null
			),
			'delivery_type' => $this->first_string(
				$this->meta_string( $order, '_wdc_platform_delivery_type' ),
				$calculation['delivery_type'] ?? null,
				$calculation['rate']['delivery_type'] ?? null,
				$calculation['result']['delivery_type'] ?? null
			),
		);
	}

	/** @param array<string,mixed> $payload */
	private function shipment_number( array $payload ): string {
		foreach ( array( 'shipment_number', 'tracking_number', 'barcode', 'external_id', 'number' ) as $key ) {
			$value = $payload[ $key ] ?? null;
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}

		return '';
	}

	/** @param array<string,mixed> $payload */
	private function optional_actual_cost_kopecks( array $payload ): ?int {
		if ( ! array_key_exists( 'actual_cost', $payload ) ) {
			return null;
		}
		$raw = trim( str_replace( ',', '.', (string) $payload['actual_cost'] ) );
		if ( '' === $raw ) {
			return null;
		}
		if ( 1 !== preg_match( '/^\d+(?:\.\d{1,2})?$/', $raw ) ) {
			throw new \InvalidArgumentException( 'Стоимость должна быть положительным числом с максимум двумя знаками после запятой.' );
		}
		$amount = MoneyParser::rubles_to_kopecks( $raw );
		if ( $amount <= 0 ) {
			throw new \InvalidArgumentException( 'Фактическая стоимость должна быть больше нуля.' );
		}

		return $amount;
	}

	private function contains_control_chars( string $value ): bool {
		return 1 === preg_match( '/[\x00-\x1F\x7F]/u', $value );
	}

	private function string_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	private function meta_string( object $order, string $key ): string {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}
		$value = $order->get_meta( $key, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/** @return array<string,mixed> */
	private function calculation_data( object $order ): array {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$value = $order->get_meta( '_wdc_delivery_calculation_data', true );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( $value, true );
			$value = is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $value ) ? $value : array();
	}

	private function first_string( mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}

		return '';
	}

	private function normalize_key( string $value ): string {
		return function_exists( 'sanitize_key' )
			? sanitize_key( $value )
			: strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ?? '' );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}

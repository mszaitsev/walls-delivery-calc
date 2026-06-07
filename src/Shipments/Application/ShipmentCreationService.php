<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Contracts\ShipmentCarrierAdapterInterface;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentCreationService {
	/**
	 * @param array<int,ShipmentCarrierAdapterInterface> $adapters
	 */
	public function __construct(
		private OrderShipmentRepository $repository,
		private array $adapters,
		private ?Logger $logger = null,
		private ?RussianPostShipmentActualCostLookupService $actual_cost_lookup = null
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function safe_preview( ShipmentCreateRequest $request ): array {
		$adapter = $this->adapter_for( $request );

		if ( ! $adapter instanceof ShipmentCarrierAdapterInterface ) {
			return array();
		}
		try {
			return $adapter->build_safe_payload_preview( $request );
		} catch ( \Throwable $e ) {
			return array(
				'method' => '',
				'path' => '',
				'body' => array(),
				'errors' => array( $e->getMessage() ),
			);
		}
	}

	public function create( object $order, ShipmentCreateRequest $request ): ShipmentCreateResult {
		$order_id = $this->order_id( $order );
		if ( $order_id > 0 && $request->order_id !== $order_id ) {
			$this->log_order_mismatch( $order_id, $request );
			return new ShipmentCreateResult(
				false,
				error_code: 'shipment_order_mismatch',
				error_message: 'ID заказа в запросе отправления не совпадает с текущим заказом WooCommerce.'
			);
		}

		if ( $this->repository->has_created_for_carrier( $order, $request->carrier_key ) ) {
			$existing = $this->repository->find_by_carrier( $order, $request->carrier_key );
			return new ShipmentCreateResult(
				false,
				error_code: 'shipment_already_created',
				error_message: 'По заказу уже создано отправление: ' . (string) ( $existing['tracking_number'] ?? $existing['barcode'] ?? '' ),
				raw_reference: array( 'existing' => $existing )
			);
		}
		$adapter = $this->adapter_for( $request );
		if ( ! $adapter instanceof ShipmentCarrierAdapterInterface ) {
			return new ShipmentCreateResult( false, error_code: 'unsupported_carrier', error_message: 'Для выбранной службы нет адаптера создания отправлений.' );
		}

		$preview = $this->safe_preview( $request );
		$result = $adapter->create( $request );
		$now = $this->now();
		if ( ! $result->success ) {
			$this->repository->save_last_error(
				$order,
				array(
					'carrier_key' => $request->carrier_key,
					'service_key' => (string) ( $request->meta['service_key'] ?? $request->rate_id ),
					'error_code' => $result->error_code,
					'error_message' => $result->error_message,
					'updated_at' => $now,
				)
			);
			$this->add_order_note( $order, 'Не удалось создать отправление Почты России: ' . $result->error_message );

			return $result;
		}

		$raw = $result->raw_reference;
		$backlog_order_id = trim( $result->backlog_order_id );
		$shipment = array(
			'carrier_key' => $request->carrier_key,
			'service_key' => (string) ( $request->meta['service_key'] ?? $request->rate_id ),
			'order_id' => $request->order_id,
			'service_title' => (string) ( $request->meta['service_title'] ?? '' ),
			'delivery_type' => $request->delivery_type,
			'places' => array_map( static fn ( $place ): array => $place->to_array(), $request->places ),
			'request_snapshot' => $preview,
			'response_snapshot' => $raw,
			'barcode' => $result->tracking_number,
			'tracking_number' => $result->tracking_number,
			'order_num' => (string) ( $request->meta['order_num'] ?? $request->order_id ),
			'group_name' => (string) ( $raw['group_name'] ?? '' ),
			'status' => 'created',
			'created_at' => $now,
			'updated_at' => $now,
		);
		if ( '' !== $backlog_order_id ) {
			$shipment['backlog_order_id'] = ctype_digit( $backlog_order_id ) ? (int) $backlog_order_id : $backlog_order_id;
		}
		$actual_cost = $this->actual_cost_after_create( $request->carrier_key, $result->tracking_number );
		if ( array() !== $actual_cost ) {
			$shipment = array_merge( $shipment, $actual_cost );
		}
		$this->repository->save_for_carrier( $order, $request->carrier_key, $shipment );
		$this->add_order_note(
			$order,
			sprintf(
				'Отправление Почты России создано. Barcode: %s. Мест: %d%s',
				$result->tracking_number,
				count( $request->places ),
				'' !== (string) ( $raw['group_name'] ?? '' ) ? '. ММО group-name: ' . (string) $raw['group_name'] : ''
			)
		);

		return $result;
	}

	private function adapter_for( ShipmentCreateRequest $request ): ?ShipmentCarrierAdapterInterface {
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->supports( $request ) ) {
				return $adapter;
			}
		}

		return null;
	}

	private function order_id( object $order ): int {
		return method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
	}

	private function log_order_mismatch( int $order_id, ShipmentCreateRequest $request ): void {
		if ( ! $this->logger instanceof Logger ) {
			return;
		}
		$this->logger->error(
			'Shipment create blocked because WooCommerce order id and request order_id differ.',
			array(
				'order_id' => $order_id,
				'request_order_id' => $request->order_id,
				'order_num' => (string) ( $request->meta['order_num'] ?? $request->order_id ),
				'carrier_key' => $request->carrier_key,
				'service_key' => (string) ( $request->meta['service_key'] ?? $request->rate_id ),
			)
		);
	}

	private function add_order_note( object $order, string $message ): void {
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( $message );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function actual_cost_after_create( string $carrier_key, string $barcode ): array {
		if ( RussianPostDomesticSettings::CARRIER_KEY !== $carrier_key || '' === trim( $barcode ) ) {
			return array();
		}
		if ( ! $this->actual_cost_lookup instanceof RussianPostShipmentActualCostLookupService ) {
			return array();
		}

		try {
			$result = $this->actual_cost_lookup->lookup_after_create( $barcode );
		} catch ( \Throwable ) {
			return array( 'russian_post_actual_cost_lookup_error' => 'exception' );
		}

		$fields = is_array( $result['fields'] ?? null ) ? $result['fields'] : array();
		if ( array() !== $fields ) {
			return $fields;
		}
		$error_code = trim( (string) ( $result['error_code'] ?? '' ) );

		return '' !== $error_code ? array( 'russian_post_actual_cost_lookup_error' => $error_code ) : array();
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}

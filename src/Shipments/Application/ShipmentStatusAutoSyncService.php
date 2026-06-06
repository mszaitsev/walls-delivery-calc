<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use Throwable;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentStatusAutoSyncService {
	public const ENABLED_KEY = 'shipment_status_autosync_enabled';
	public const ORDER_STATUSES_KEY = 'shipment_status_autosync_order_statuses';
	public const DIAGNOSTICS_KEY = 'shipment_status_autosync_last_run';
	public const LOCK_KEY = 'wdc_shipment_status_autosync_lock';
	public const LOCK_TTL = 30 * 60;
	public const INTERVAL_SECONDS = 6 * 60 * 60;

	/**
	 * @var array<int,string>
	 */
	private const TERMINAL_STATUSES = array(
		DeliveryStatus::DELIVERED,
		DeliveryStatus::RETURNED_TO_SENDER,
		DeliveryStatus::CANCELLED,
		DeliveryStatus::REJECTED,
	);

	public function __construct(
		private SettingsRepository $settings,
		private OrderShipmentRepository $repository,
		private ShipmentStatusUpdateService $status_updates,
		private mixed $dispatcher = null
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function run( string $trigger_type = 'cron' ): array {
		$trigger_type = in_array( $trigger_type, array( 'cron', 'manual' ), true ) ? $trigger_type : 'manual';
		if ( ! $this->enabled() ) {
			return array_merge( $this->empty_stats(), array( 'trigger_type' => $trigger_type, 'status' => 'disabled' ) );
		}

		if ( $this->is_locked() ) {
			return array_merge( $this->empty_stats(), array( 'trigger_type' => $trigger_type, 'status' => 'locked' ) );
		}

		$this->lock();
		$started_at = $this->now();
		$started_ms = microtime( true );
		$stats = array_merge(
			$this->empty_stats(),
			array(
				'started_at' => $started_at,
				'trigger_type' => $trigger_type,
				'status' => 'running',
			)
		);

		try {
			foreach ( $this->find_orders() as $order ) {
				if ( ! is_object( $order ) ) {
					continue;
				}
				++$stats['orders_scanned'];
				$this->process_order( $order, $stats );
			}
			$stats['status'] = 'finished';
		} catch ( Throwable $throwable ) {
			$stats['status'] = 'failed';
			++$stats['shipments_failed'];
			$this->add_error_sample( $stats, 0, '', $throwable->getMessage() );
		} finally {
			$stats['finished_at'] = $this->now();
			$stats['duration_ms'] = max( 0, (int) round( ( microtime( true ) - $started_ms ) * 1000 ) );
			$this->save_diagnostics( $stats );
			$this->unlock();
		}

		return $stats;
	}

	public function enabled(): bool {
		return $this->settings->get_bool( self::ENABLED_KEY, true );
	}

	/**
	 * @return array<int,string>
	 */
	public function selected_order_statuses(): array {
		return array_values(
			array_filter(
				array_map( 'strval', $this->settings->get_array( self::ORDER_STATUSES_KEY, $this->default_order_statuses() ) ),
				static fn ( string $status ): bool => '' !== trim( $status )
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function diagnostics(): array {
		$value = $this->settings->get_array( self::DIAGNOSTICS_KEY, array() );

		return array_merge( $this->empty_stats(), $value );
	}

	/**
	 * @return array<int,string>
	 */
	public function default_order_statuses(): array {
		return array( 'wc-processing', 'wc-on-hold', 'wc-completed' );
	}

	/**
	 * @return array<int,object>
	 */
	private function find_orders(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$statuses = $this->selected_order_statuses();
		if ( array() === $statuses ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'status' => $statuses,
				'limit' => -1,
				'return' => 'objects',
			)
		);

		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * @param array<string,mixed> $stats
	 */
	private function process_order( object $order, array &$stats ): void {
		foreach ( $this->repository->all_for_order( $order ) as $shipment_key => $shipment ) {
			if ( ! is_array( $shipment ) ) {
				continue;
			}
			++$stats['shipments_found'];
			$carrier_key = trim( (string) ( $shipment['carrier_key'] ?? $shipment_key ) );
			$tracking_number = trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
			$universal_status = trim( (string) ( $shipment['universal_status_code'] ?? '' ) );

			if ( '' === $tracking_number ) {
				$this->skip( $stats, 'missing_tracking_number' );
				continue;
			}
			if ( in_array( $universal_status, self::TERMINAL_STATUSES, true ) ) {
				$this->skip( $stats, 'terminal_status' );
				continue;
			}
			if ( ! $this->supports_carrier( $carrier_key ) ) {
				$this->skip( $stats, 'unsupported_carrier' );
				continue;
			}

			$this->increment_carrier( $stats, $carrier_key );
			$result = $this->dispatch( $carrier_key, $order, (string) $shipment_key );
			if ( ! empty( $result['success'] ) ) {
				++$stats['shipments_updated'];
				continue;
			}

			++$stats['shipments_failed'];
			$this->add_error_sample( $stats, $this->order_id( $order ), $carrier_key, (string) ( $result['message'] ?? 'Shipment status update failed.' ) );
		}
	}

	private function supports_carrier( string $carrier_key ): bool {
		return RussianPostDomesticSettings::CARRIER_KEY === $carrier_key;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function dispatch( string $carrier_key, object $order, string $shipment_key ): array {
		if ( is_callable( $this->dispatcher ) ) {
			$result = call_user_func( $this->dispatcher, $carrier_key, $order, $shipment_key );
			return is_array( $result ) ? $result : array( 'success' => false, 'message' => 'Shipment dispatcher returned an invalid result.' );
		}

		switch ( $carrier_key ) {
			case RussianPostDomesticSettings::CARRIER_KEY:
				return $this->status_updates->update_russian_post( $order, $shipment_key );
			default:
				return array( 'success' => false, 'message' => 'Unsupported carrier.' );
		}
	}

	/**
	 * @param array<string,mixed> $stats
	 */
	private function skip( array &$stats, string $reason ): void {
		++$stats['shipments_skipped'];
		$stats['skip_reasons'][ $reason ] = (int) ( $stats['skip_reasons'][ $reason ] ?? 0 ) + 1;
	}

	/**
	 * @param array<string,mixed> $stats
	 */
	private function increment_carrier( array &$stats, string $carrier_key ): void {
		$stats['updates_by_carrier'][ $carrier_key ] = (int) ( $stats['updates_by_carrier'][ $carrier_key ] ?? 0 ) + 1;
	}

	/**
	 * @param array<string,mixed> $stats
	 */
	private function add_error_sample( array &$stats, int $order_id, string $carrier_key, string $message ): void {
		$stats['error_samples'][] = array(
			'order_id' => $order_id,
			'carrier_key' => $carrier_key,
			'message' => $message,
		);
		$stats['error_samples'] = array_slice( $stats['error_samples'], -20 );
	}

	private function save_diagnostics( array $stats ): void {
		$this->settings->set( self::DIAGNOSTICS_KEY, $stats );
	}

	private function is_locked(): bool {
		return function_exists( 'get_transient' ) && false !== get_transient( self::LOCK_KEY );
	}

	private function lock(): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );
		}
	}

	private function unlock(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::LOCK_KEY );
		}
	}

	private function order_id( object $order ): int {
		return method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function empty_stats(): array {
		return array(
			'started_at' => '',
			'finished_at' => '',
			'duration_ms' => 0,
			'trigger_type' => '',
			'orders_scanned' => 0,
			'shipments_found' => 0,
			'shipments_updated' => 0,
			'shipments_skipped' => 0,
			'shipments_failed' => 0,
			'updates_by_carrier' => array(),
			'skip_reasons' => array(),
			'error_samples' => array(),
			'status' => '',
		);
	}
}

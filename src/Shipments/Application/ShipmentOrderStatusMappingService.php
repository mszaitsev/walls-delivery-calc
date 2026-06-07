<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use Throwable;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentOrderStatusMappingService {
	public const ENABLED_KEY = 'shipment_status_order_status_mapping_enabled';
	public const MAPPING_KEY = 'shipment_status_order_status_mapping';

	public function __construct(
		private SettingsRepository $settings
	) {
	}

	public function enabled(): bool {
		return $this->settings->get_bool( self::ENABLED_KEY, false );
	}

	/**
	 * @return array<string,string>
	 */
	public function mapping(): array {
		$mapping = $this->settings->get_array( self::MAPPING_KEY, array() );
		$result = array();
		foreach ( $mapping as $shipment_status => $order_status ) {
			$shipment_status = sanitize_key( (string) $shipment_status );
			$order_status = sanitize_key( (string) $order_status );
			if ( '' === $shipment_status || '' === $order_status || ! DeliveryStatus::is_valid( $shipment_status ) ) {
				continue;
			}
			$result[ $shipment_status ] = $order_status;
		}

		return $result;
	}

	/**
	 * @return array<string,string>
	 */
	public function woo_order_statuses(): array {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();

		return is_array( $statuses ) ? array_map( 'strval', $statuses ) : array();
	}

	/**
	 * @param array<string,mixed> $mapping
	 * @return array<string,string>
	 */
	public function sanitize_mapping( array $mapping ): array {
		$available_statuses = $this->woo_order_statuses();
		$result = array();
		foreach ( DeliveryStatus::all() as $shipment_status ) {
			$order_status = sanitize_key( (string) ( $mapping[ $shipment_status ] ?? '' ) );
			if ( '' === $order_status || ! array_key_exists( $order_status, $available_statuses ) ) {
				continue;
			}
			$result[ $shipment_status ] = $order_status;
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	public function apply( object $order, array $shipment, string $universal_status_code = '' ): array {
		if ( ! $this->enabled() ) {
			return $this->skipped( 'disabled' );
		}

		$universal_status_code = sanitize_key( '' !== $universal_status_code ? $universal_status_code : (string) ( $shipment['universal_status_code'] ?? '' ) );
		if ( '' === $universal_status_code ) {
			return $this->skipped( 'empty_universal_status' );
		}

		$mapping = $this->mapping();
		if ( ! isset( $mapping[ $universal_status_code ] ) ) {
			return $this->skipped( 'missing_mapping' );
		}

		$target_status = $mapping[ $universal_status_code ];
		$available_statuses = $this->woo_order_statuses();
		if ( ! array_key_exists( $target_status, $available_statuses ) ) {
			return $this->skipped( 'target_status_unavailable', array( 'target_status' => $target_status ) );
		}

		if ( ! method_exists( $order, 'get_status' ) || ! method_exists( $order, 'update_status' ) ) {
			return $this->error( 'Order object does not support WooCommerce status changes.', array( 'target_status' => $target_status ) );
		}

		$current_status = $this->normalize_order_status( (string) $order->get_status() );
		if ( $current_status === $target_status ) {
			return $this->skipped( 'already_target_status', array( 'target_status' => $target_status ) );
		}

		try {
			$order->update_status( $this->status_without_prefix( $target_status ) );
			$this->add_private_note( $order, $shipment, $universal_status_code, $current_status, $target_status, $available_statuses );
		} catch ( Throwable $throwable ) {
			return $this->error(
				$throwable->getMessage(),
				array(
					'target_status' => $target_status,
					'from_status' => $current_status,
					'universal_status_code' => $universal_status_code,
				)
			);
		}

		return array(
			'status' => 'changed',
			'changed' => true,
			'from_status' => $current_status,
			'target_status' => $target_status,
			'universal_status_code' => $universal_status_code,
		);
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function skipped( string $reason, array $extra = array() ): array {
		return array_merge(
			array(
				'status' => 'skipped',
				'changed' => false,
				'reason' => $reason,
			),
			$extra
		);
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function error( string $message, array $extra = array() ): array {
		return array_merge(
			array(
				'status' => 'error',
				'changed' => false,
				'message' => '' !== trim( $message ) ? $message : 'WooCommerce order status change failed.',
			),
			$extra
		);
	}

	private function normalize_order_status( string $status ): string {
		$status = sanitize_key( $status );

		return str_starts_with( $status, 'wc-' ) ? $status : 'wc-' . $status;
	}

	private function status_without_prefix( string $status ): string {
		return str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @param array<string,string> $available_statuses
	 */
	private function add_private_note( object $order, array $shipment, string $universal_status_code, string $from_status, string $target_status, array $available_statuses ): void {
		if ( ! method_exists( $order, 'add_order_note' ) ) {
			return;
		}

		$tracking_number = trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
		$shipment_status = trim( (string) ( $shipment['universal_status_label'] ?? '' ) );
		if ( '' === $shipment_status ) {
			$shipment_status = DeliveryStatus::label( $universal_status_code );
		}

		$note = sprintf(
			"Посылка %s\nСтатус: %s.\nСтатус заказа изменён:\n%s → %s",
			'' !== $tracking_number ? $tracking_number : '-',
			$shipment_status,
			$this->status_without_prefix( $from_status ),
			$this->status_without_prefix( $target_status )
		);

		$order->add_order_note( $note, false, false );
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class PekShipmentButtonPolicy {
	public function __construct( private ?PekStatusMapping $mapping = null ) {
		$this->mapping = $this->mapping ?? new PekStatusMapping();
	}

	/** @param array<string,mixed> $shipment @return array<string,bool> */
	public function resolve( array $shipment ): array {
		if ( array() === $shipment ) {
			return array( 'create' => true, 'manual_attach' => true, 'update' => false, 'cancel' => false, 'remove' => false );
		}
		$status = (string) ( $shipment['universal_status_code'] ?? '' );
		if ( DeliveryStatus::PENDING_CREATION_IN_CARRIER === $status || ! empty( $shipment['pending_creation_in_carrier'] ) ) {
			return array( 'create' => false, 'manual_attach' => true, 'update' => false, 'cancel' => false, 'remove' => true );
		}
		$accepted = '' !== (string) ( $shipment['pek_take_on_stock_datetime'] ?? '' )
			|| in_array( $status, array( DeliveryStatus::IN_TRANSIT, DeliveryStatus::READY_FOR_PICKUP, DeliveryStatus::HANDED_TO_COURIER, DeliveryStatus::DELIVERED, DeliveryStatus::RETURNING_TO_SENDER, DeliveryStatus::RETURNED_TO_SENDER ), true );
		$external_status = trim( (string) ( $shipment['pek_cargo_status'] ?? $shipment['status_title'] ?? '' ) );
		$terminal = in_array( $status, DeliveryStatus::terminal(), true );
		$can_cancel = '' !== $this->cargo_code( $shipment )
			&& '' === trim( (string) ( $shipment['pek_take_on_stock_datetime'] ?? '' ) )
			&& '' !== $external_status
			&& ! $terminal
			&& empty( $shipment['manual_attach'] )
			&& $this->mapping->is_pre_acceptance_status( $external_status );

		return array(
			'create' => false,
			'manual_attach' => false,
			'update' => true,
			'cancel' => $can_cancel && ! $accepted,
			'remove' => $accepted || $terminal || ! empty( $shipment['manual_attach'] ),
		);
	}

	/** @param array<string,mixed> $shipment */
	private function cargo_code( array $shipment ): string {
		return trim( (string) ( $shipment['pek_cargo_code'] ?? $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
	}
}

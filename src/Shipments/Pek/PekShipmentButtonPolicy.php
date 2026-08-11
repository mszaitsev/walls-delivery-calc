<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Carriers\Pek\PekCountryPolicy;

defined( 'ABSPATH' ) || exit;

final class PekShipmentButtonPolicy {
	public function __construct( private PekStatusMapping $mapping, ?PekCountryPolicy $countries = null ) {
		$this->countries = $countries ?? new PekCountryPolicy();
	}

	private PekCountryPolicy $countries;

	/** @param array<string,mixed> $shipment @return array<string,bool> */
	public function resolve( array $shipment ): array {
		if ( array() === $shipment ) {
			return array( 'create' => true, 'manual_attach' => true, 'update' => false, 'cancel' => false, 'remove' => false );
		}
		$receiver_country = strtoupper( trim( (string) ( $shipment['receiver_country_code'] ?? $shipment['recipient_country_code'] ?? '' ) ) );
		$pending_status = (string) ( $shipment['universal_status_code'] ?? '' );
		if ( DeliveryStatus::PENDING_CREATION_IN_CARRIER === $pending_status || ! empty( $shipment['pending_creation_in_carrier'] ) ) {
			return array( 'create' => false, 'manual_attach' => true, 'update' => false, 'cancel' => false, 'remove' => true );
		}
		$take_on_stock_datetime = trim( (string) ( $shipment['pek_take_on_stock_datetime'] ?? '' ) );
		$external_status = trim( (string) ( $shipment['pek_cargo_status'] ?? $shipment['status_title'] ?? '' ) );
		$accepted = '' !== $take_on_stock_datetime
			|| ( '' !== $external_status && $this->mapping->is_accepted_status( $external_status ) );
		$terminal = '' !== $external_status && $this->mapping->is_terminal_status( $external_status );
		$can_cancel = ( '' === $receiver_country || $this->countries->allows_automatic_shipment_create( $this->countries->sender_country(), $receiver_country ) )
			&& '' !== $this->cargo_code( $shipment )
			&& '' === $take_on_stock_datetime
			&& '' !== $external_status
			&& ! $terminal
			&& empty( $shipment['manual_attach'] )
			&& ! $accepted
			&& $this->mapping->is_pre_acceptance_status( $external_status );

		return array(
			'create' => false,
			'manual_attach' => false,
			'update' => true,
			'cancel' => $can_cancel,
			'remove' => $accepted || $terminal || ! empty( $shipment['manual_attach'] ),
		);
	}

	/** @param array<string,mixed> $shipment */
	private function cargo_code( array $shipment ): string {
		return trim( (string) ( $shipment['pek_cargo_code'] ?? $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
	}
}

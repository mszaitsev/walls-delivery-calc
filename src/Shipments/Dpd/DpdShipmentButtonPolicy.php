<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

defined( 'ABSPATH' ) || exit;

final class DpdShipmentButtonPolicy {
	private const CANCELLABLE = array( '1001', '1101', '1201', '1401', '1501' );

	/** @param array<string,mixed> $shipment @return array<string,bool> */
	public function resolve( array $shipment ): array {
		$has = array() !== $shipment;
		$dpd_order = $this->first_non_empty( $shipment['dpd_order_number'] ?? '', $shipment['tracking_number'] ?? '', $shipment['barcode'] ?? '', $shipment['external_id'] ?? '' );
		$event = preg_replace( '/[^0-9]/', '', $this->first_non_empty( $shipment['dpd_event_code'] ?? '', $shipment['carrier_operation_code'] ?? '' ) ) ?: '';
		$registration_state = (string) ( $shipment['dpd_registration_state'] ?? '' );
		$terminal_error = in_array( $registration_state, array( 'duplicate', 'error', 'cancelled', 'transport_error' ), true );
		if ( ! $has ) {
			return array( 'create' => true, 'manual_attach' => true, 'update' => false, 'cancel' => false, 'remove' => false );
		}
		if ( '' === $dpd_order ) {
			return array( 'create' => false, 'manual_attach' => false, 'update' => ! $terminal_error, 'cancel' => false, 'remove' => true );
		}
		if ( '' === $event && 'ok' === $registration_state ) {
			return array( 'create' => false, 'manual_attach' => false, 'update' => true, 'cancel' => true, 'remove' => false );
		}
		$can_cancel = in_array( $event, self::CANCELLABLE, true );
		return array( 'create' => false, 'manual_attach' => false, 'update' => true, 'cancel' => $can_cancel, 'remove' => ! $can_cancel );
	}

	private function first_non_empty( mixed ...$values ): string {
		foreach ( $values as $value ) {
			$text = trim( (string) $value );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return '';
	}
}

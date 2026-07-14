<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class YandexShipmentLabelPolicy {
	public function __construct( private ?YandexStatusMapping $status_mapping = null ) {
	}

	/** @param array<string,mixed> $shipment */
	public function can_download( array $shipment ): bool {
		if ( array() === $shipment || '' === $this->request_id( $shipment ) || ! empty( $shipment['yandex_reconciliation_required'] ) ) {
			return false;
		}
		$universal = $this->universal_status( $shipment );

		return in_array(
			$universal,
			array(
				DeliveryStatus::CREATED_IN_CARRIER,
				DeliveryStatus::IN_TRANSIT,
				DeliveryStatus::READY_FOR_PICKUP,
				DeliveryStatus::HANDED_TO_COURIER,
				DeliveryStatus::DELIVERED,
				DeliveryStatus::RETURNING_TO_SENDER,
				DeliveryStatus::RETURNED_TO_SENDER,
			),
			true
		);
	}

	/** @param array<string,mixed> $shipment */
	public function request_id( array $shipment ): string {
		foreach ( array( 'yandex_request_id', 'request_id', 'external_id' ) as $key ) {
			$value = trim( (string) ( $shipment[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/** @param array<string,mixed> $shipment */
	private function universal_status( array $shipment ): string {
		$universal = $this->sanitize_key( (string) ( $shipment['universal_status_code'] ?? '' ) );
		if ( ! DeliveryStatus::is_valid( $universal ) && $this->status_mapping instanceof YandexStatusMapping ) {
			$universal = $this->status_mapping->universal_status_for( (string) ( $shipment['yandex_status'] ?? '' ) );
		}

		return $universal;
	}

	private function sanitize_key( string $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' );
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class YandexShipmentButtonPolicy {
	public function __construct( private ?YandexStatusMapping $status_mapping = null ) {
	}

	/** @param array<string,mixed> $shipment @return array<string,bool> */
	public function resolve( array $shipment ): array {
		if ( array() === $shipment ) {
			return array( 'create' => true, 'manual_attach' => true, 'update' => false, 'cancel' => false, 'remove' => false );
		}
		$request_id = $this->request_id( $shipment );
		$local_status = trim( (string) ( $shipment['status'] ?? '' ) );
		if ( '' !== $request_id && 'reconciliation_required' === $local_status ) {
			return array( 'create' => false, 'manual_attach' => false, 'update' => true, 'cancel' => false, 'remove' => true );
		}
		if ( '' !== $request_id && 'cancellation_started' === $local_status ) {
			return array( 'create' => false, 'manual_attach' => false, 'update' => true, 'cancel' => false, 'remove' => ! empty( $shipment['yandex_cancel_poll_exhausted'] ) );
		}
		$universal_status = sanitize_key( (string) ( $shipment['universal_status_code'] ?? '' ) );
		if ( ! DeliveryStatus::is_valid( $universal_status ) ) {
			$raw_status = (string) ( $shipment['yandex_status'] ?? '' );
			$universal_status = $this->status_mapping instanceof YandexStatusMapping
				? $this->status_mapping->universal_status_for( $raw_status )
				: DeliveryStatus::UNKNOWN;
		}
		$can_cancel = in_array( $universal_status, array( DeliveryStatus::PENDING_CREATION_IN_CARRIER, DeliveryStatus::CREATED_IN_CARRIER ), true );

		return array(
			'create' => false,
			'manual_attach' => false,
			'update' => '' !== $request_id,
			'cancel' => '' !== $request_id && $can_cancel,
			'remove' => ! $can_cancel || '' === $request_id,
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
}

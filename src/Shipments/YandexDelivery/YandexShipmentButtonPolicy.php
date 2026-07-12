<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

defined( 'ABSPATH' ) || exit;

final class YandexShipmentButtonPolicy {
	private const TERMINAL_STATUSES = array( 'CANCELLED', 'DELIVERED', 'RETURNED', 'RETURNED_TO_SENDER', 'REJECTED' );

	/** @param array<string,mixed> $shipment @return array<string,bool> */
	public function resolve( array $shipment ): array {
		if ( array() === $shipment ) {
			return array( 'create' => true, 'manual_attach' => false, 'update' => false, 'cancel' => false, 'remove' => false );
		}
		$request_id = $this->request_id( $shipment );
		$status = strtoupper( trim( (string) ( $shipment['yandex_status'] ?? $shipment['status'] ?? '' ) ) );
		$terminal = in_array( $status, self::TERMINAL_STATUSES, true );

		return array(
			'create' => false,
			'manual_attach' => false,
			'update' => '' !== $request_id,
			'cancel' => '' !== $request_id && ! $terminal,
			'remove' => $terminal || '' === $request_id,
		);
	}

	/** @param array<string,mixed> $shipment */
	public function request_id( array $shipment ): string {
		foreach ( array( 'yandex_request_id', 'request_id', 'external_id', 'barcode', 'tracking_number' ) as $key ) {
			$value = trim( (string) ( $shipment[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}
}

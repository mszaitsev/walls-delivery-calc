<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

defined( 'ABSPATH' ) || exit;

final class YandexShipmentButtonPolicy {
	private const TERMINAL_STATUSES = array( 'CANCELLED', 'DELIVERED', 'RETURNED', 'RETURNED_TO_SENDER', 'REJECTED' );

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
			return array( 'create' => false, 'manual_attach' => false, 'update' => true, 'cancel' => false, 'remove' => false );
		}
		$status = strtoupper( trim( (string) ( $shipment['yandex_status'] ?? $local_status ) ) );
		$terminal = self::is_terminal_status( $status );

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
		foreach ( array( 'yandex_request_id', 'request_id', 'external_id' ) as $key ) {
			$value = trim( (string) ( $shipment[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	public static function is_terminal_status( string $status ): bool {
		return in_array( strtoupper( trim( $status ) ), self::TERMINAL_STATUSES, true );
	}
}

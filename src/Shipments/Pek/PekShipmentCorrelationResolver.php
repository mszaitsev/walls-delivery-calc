<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;

defined( 'ABSPATH' ) || exit;

final class PekShipmentCorrelationResolver {
	public function resolve( ShipmentCreateRequest $request, string $sender_warehouse_id, string $receiver_warehouse_id ): string {
		$existing = trim( (string) ( $request->meta['pek_creation_correlation'] ?? '' ) );
		if ( '' !== $existing && strlen( $existing ) <= 64 ) {
			return $existing;
		}
		$place_hash = hash( 'sha256', json_encode( array_map( static fn( $place ): array => $place->to_array(), $request->places ), JSON_UNESCAPED_UNICODE ) ?: '' );
		$attempt = trim( (string) ( $request->meta['creation_attempt_id'] ?? '' ) );
		if ( '' === $attempt ) {
			$attempt = trim( (string) ( $request->meta['shipment_attempt_id'] ?? '' ) );
		}
		if ( '' === $attempt ) {
			$attempt = 'initial';
		}

		return 'wdc-' . substr( hash( 'sha256', implode( '|', array( $request->order_id, $request->delivery_type, $sender_warehouse_id, $receiver_warehouse_id, $place_hash, $attempt ) ) ), 0, 32 );
	}
}

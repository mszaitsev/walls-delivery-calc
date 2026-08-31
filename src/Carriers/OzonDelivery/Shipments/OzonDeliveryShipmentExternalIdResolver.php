<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentExternalIdResolver {
	public function order_external_id( string $order_number ): string {
		return $this->base( $order_number );
	}

	public function posting_external_id( string $order_number, int $place_number, int $total_places ): string {
		return $this->place_external_id( $order_number, $place_number, $total_places );
	}

	public function expected_return_external_id( string $order_number, int $place_number, int $total_places ): string {
		return $this->place_external_id( $order_number, $place_number, $total_places );
	}

	private function place_external_id( string $order_number, int $place_number, int $total_places ): string {
		$base = $this->base( $order_number );

		return $total_places <= 1 ? $base : substr( $base . '-' . max( 1, $place_number ), 0, 120 );
	}

	private function base( string $order_number ): string {
		$value = trim( preg_replace( '/[^A-Za-z0-9_.-]+/', '-', trim( $order_number ) ) ?? '' );

		return '' !== $value ? substr( $value, 0, 120 ) : 'order';
	}
}

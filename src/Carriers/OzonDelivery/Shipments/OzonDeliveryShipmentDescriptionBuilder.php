<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentDescriptionBuilder {
	public function build( string $order_number, int $index, int $total ): string {
		$order_number = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $order_number ) ?? '';
		$order_number = trim( preg_replace( '/\s+/u', ' ', $order_number ) ?? '' );
		$order_number = '' !== $order_number ? $order_number : '-';
		$description = sprintf( 'Товары по заказу %s. Коробка %d из %d', $order_number, max( 1, $index ), max( 1, $total ) );

		return $this->limit( $description, 500 );
	}

	private function limit( string $value, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}
}

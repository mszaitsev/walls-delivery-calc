<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

defined( 'ABSPATH' ) || exit;

final class ShipmentOrderItemIdentityResolver {
	public function order_item_id( mixed $item_key, mixed $split_parent = null ): int {
		$value = trim( (string) ( null !== $split_parent && '' !== trim( (string) $split_parent ) ? $split_parent : $item_key ) );
		$split_pos = strpos( $value, ':split:' );
		if ( false !== $split_pos ) {
			$value = substr( $value, 0, $split_pos );
		}
		if ( str_starts_with( $value, 'order-item-' ) ) {
			$value = substr( $value, 11 );
		}

		return ctype_digit( $value ) ? (int) $value : 0;
	}
}

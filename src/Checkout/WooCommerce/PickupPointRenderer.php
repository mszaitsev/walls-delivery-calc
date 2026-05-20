<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Domain\Pickup\PickupPoint;

defined( 'ABSPATH' ) || exit;

final class PickupPointRenderer {
	public function render( PickupPoint $point ): string {
		$parts = array();
		$parts[] = '<div class="wdc-pickup-info">';
		$parts[] = '<strong>' . esc_html( $point->address ) . '</strong>';

		if ( '' !== trim( $point->work_time ) ) {
			$parts[] = '<span class="wdc-pickup-info__line">' . esc_html( $point->work_time ) . '</span>';
		}

		if ( '' !== trim( $point->comment ) ) {
			$parts[] = '<span class="wdc-pickup-info__line">' . esc_html( $point->comment ) . '</span>';
		}

		if ( $point->has_extra_cost() ) {
			$parts[] = '<span class="wdc-pickup-info__line">' . esc_html( sprintf( __( 'Extra pickup cost: %s', 'walls-delivery-calc' ), $this->format_money( $point->extra_cost?->get_kopecks() ?? 0 ) ) ) . '</span>';
		}

		$parts[] = '</div>';

		return implode( '', $parts );
	}

	private function format_money( int $kopecks ): string {
		return rtrim( rtrim( number_format( $kopecks / 100, 2, '.', ' ' ), '0' ), '.' ) . ' ₽';
	}
}

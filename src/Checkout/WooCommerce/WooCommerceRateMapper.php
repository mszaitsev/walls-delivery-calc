<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Domain\Quote\DeliveryRate;

defined( 'ABSPATH' ) || exit;

final class WooCommerceRateMapper {
	/**
	 * @return array{id:string,label:string,cost:string,meta_data:array<string,mixed>}
	 */
	public function map( DeliveryRate $rate ): array {
		$label = $rate->title;
		if ( '' !== trim( $rate->planned_delivery_comment ) ) {
			$label .= ' - ' . $rate->planned_delivery_comment;
		}

		return array(
			'id'        => $rate->rate_id,
			'label'     => $label,
			'cost'      => (string) $rate->price->get_rubles(),
			'meta_data' => array(
				'carrier_key'     => $rate->carrier_key,
				'delivery_type'   => $rate->delivery_type,
				'crossed_price'   => $rate->crossed_price?->to_array(),
				'comments'        => $rate->comments,
				'disabled'        => $rate->disabled,
				'disabled_reason' => $rate->disabled_reason,
			),
		);
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Domain\Quote\DeliveryRate;

defined( 'ABSPATH' ) || exit;

final class WooCommerceRateMapper {
	/**
	 * @return array{id:string,label:string,cost:string,meta_data:array<string,mixed>}
	 */
	public function map( DeliveryRate $rate, bool $fallback_used = false ): array {
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
				'rate_id'         => $rate->rate_id,
				'delivery_type'   => $rate->delivery_type,
				'crossed_price'   => $rate->crossed_price?->to_array(),
				'planned_delivery_comment' => $rate->planned_delivery_comment,
				'comments'        => $rate->comments,
				'disabled'        => $rate->disabled,
				'disabled_reason' => $rate->disabled_reason,
				'service_key'     => $rate->service_key,
				'service_title'   => $rate->service_name,
				'rules_source'    => (string) ( $rate->meta['rules_source'] ?? 'none' ),
				'round_up_applied' => ! empty( $rate->meta['round_up_applied'] ),
				'minimum_price_applied' => ! empty( $rate->meta['minimum_price_applied'] ),
				'final_price_rub' => (float) ( $rate->meta['final_price_rub'] ?? $rate->price->get_rubles() ),
				'api_base_price_rub' => $rate->meta['api_base_price_rub'] ?? null,
				'api_price_with_vat_rub' => $rate->meta['api_price_with_vat_rub'] ?? null,
				'rules_audit' => is_array( $rate->meta['rules_audit'] ?? null ) ? $rate->meta['rules_audit'] : array(),
				'rate_meta'       => $rate->meta,
				'requires_pickup_point' => $rate->requires_pickup_point,
				'requires_courier_address' => $rate->requires_courier_address,
				'no_pickup_selection' => ! empty( $rate->meta['no_pickup_selection'] ),
				'fallback_used'   => $fallback_used || 'fallback' === $rate->carrier_key,
			),
		);
	}
}

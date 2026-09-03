<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Admin;

use WallsShop\WDC\Domain\Quote\DeliveryType;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryRateRenderer {
	/**
	 * @param array<int,array<string,mixed>> $rates
	 */
	public function render( array $rates ): string {
		ob_start();
		$this->render_inner( $rates );

		return (string) ob_get_clean();
	}

	/**
	 * @param array<int,array<string,mixed>> $rates
	 */
	private function render_inner( array $rates ): void {
		if ( array() === $rates ) {
			echo '<p class="wdc-order-delivery-preview__empty">' . esc_html__( 'Доступные варианты доставки не найдены.', 'walls-delivery-calc' ) . '</p>';
			return;
		}

		echo '<div class="wdc-order-delivery-rates">';
		foreach ( array( DeliveryType::PICKUP, DeliveryType::COURIER, 'other' ) as $group ) {
			$group_rates = array_values(
				array_filter(
					$rates,
					static fn( array $rate ): bool => 'other' === $group
						? ! in_array( (string) ( $rate['delivery_type'] ?? '' ), array( DeliveryType::PICKUP, DeliveryType::COURIER ), true )
						: $group === (string) ( $rate['delivery_type'] ?? '' )
				)
			);
			if ( array() === $group_rates ) {
				continue;
			}

			echo '<section class="wdc-order-delivery-rates__group">';
			echo '<h4>' . esc_html( $this->group_label( $group ) ) . '</h4>';
			foreach ( $group_rates as $rate ) {
				$this->render_rate( $rate );
			}
			echo '</section>';
		}
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function render_rate( array $rate ): void {
		$id = (string) ( $rate['id'] ?? '' );
		$delivery_type = (string) ( $rate['delivery_type'] ?? '' );
		$requires_pickup = ! empty( $rate['requires_pickup_point'] );
		$requires_admin_address = ! empty( $rate['order_recalculation_requires_address'] );
		$payload = $this->rate_payload_json( $rate );
		$title = ! empty( $rate['is_grouped'] )
			? trim( (string) ( $rate['label'] ?? '' ) )
			: $this->title_with_delivery_comment( (string) ( $rate['label'] ?? '' ), (string) ( $rate['delivery_comment'] ?? '' ) );

		echo '<article class="wdc-order-delivery-rate" data-wdc-order-delivery-rate data-rate-id="' . esc_attr( $id ) . '" data-delivery-type="' . esc_attr( $delivery_type ) . '" data-requires-pickup="' . esc_attr( $requires_pickup ? '1' : '0' ) . '" data-requires-admin-address="' . esc_attr( $requires_admin_address ? '1' : '0' ) . '" data-carrier-key="' . esc_attr( (string) ( $rate['carrier_key'] ?? '' ) ) . '" data-service-key="' . esc_attr( (string) ( $rate['service_key'] ?? '' ) ) . '" data-rate-payload="' . esc_attr( $payload ) . '">';
		$this->render_compact_summary( $rate, $title );
		echo '<label class="wdc-order-delivery-rate__header">';
		echo '<input type="radio" name="wdc_order_delivery_preview_rate" value="' . esc_attr( $id ) . '">';
		echo '<span class="wdc-order-delivery-rate__title">' . esc_html( $title ) . '</span>';
		$this->render_price_cluster( (string) ( $rate['price_html'] ?? '' ), (string) ( $rate['crossed_price_html'] ?? '' ) );
		echo '</label>';
		$this->render_comments( is_array( $rate['comments'] ?? null ) ? $rate['comments'] : array() );
		$this->render_planned_delivery_comment( (string) ( $rate['planned_delivery_comment'] ?? '' ) );
		$this->render_tariffs( is_array( $rate['tariff_variants'] ?? null ) ? $rate['tariff_variants'] : array(), $id );
		if ( $requires_pickup ) {
			echo '<div class="wdc-order-delivery-rate__pickup-selector" data-wdc-pickup-selector hidden>';
			echo '<div class="wdc-order-delivery-rate__pickup-status" data-wdc-selected-pickup-label>' . esc_html__( 'ПВЗ не выбран', 'walls-delivery-calc' ) . '</div>';
			echo '<button type="button" class="button" data-wdc-open-pickup-picker>' . esc_html__( 'Выбрать ПВЗ', 'walls-delivery-calc' ) . '</button>';
			echo '</div>';
		}
		echo '</article>';
	}

	/**
	 * @param array<int,array<string,mixed>> $tariffs
	 */
	private function render_tariffs( array $tariffs, string $group_id ): void {
		if ( array() === $tariffs ) {
			return;
		}

		echo '<div class="wdc-order-delivery-tariffs">';
		foreach ( $tariffs as $tariff ) {
			$object = (string) ( $tariff['object_code'] ?? '' );
			$tariff_payload = $this->rate_payload_json( $tariff );
			$title = $this->title_with_delivery_comment( (string) ( $tariff['title'] ?? '' ), (string) ( $tariff['delivery_comment'] ?? '' ) );
			echo '<label class="wdc-order-delivery-tariff">';
			echo '<input type="radio" name="wdc_order_delivery_preview_tariff_' . esc_attr( $group_id ) . '" value="' . esc_attr( $object ) . '" data-tariff-payload="' . esc_attr( $tariff_payload ) . '">';
			echo '<span class="wdc-order-delivery-tariff__title">' . esc_html( $title ) . '</span>';
			$this->render_price_cluster( (string) ( $tariff['price_html'] ?? '' ), (string) ( $tariff['crossed_price_html'] ?? '' ) );
			echo '</label>';
			$this->render_comments( is_array( $tariff['comments'] ?? null ) ? $tariff['comments'] : array() );
			$this->render_planned_delivery_comment( (string) ( $tariff['planned_delivery_comment'] ?? '' ) );
		}
		echo '</div>';
	}

	/**
	 * @param array<int,mixed> $comments
	 */
	private function render_comments( array $comments ): void {
		foreach ( $comments as $comment ) {
			if ( '' !== trim( (string) $comment ) ) {
				echo '<div class="wdc-order-delivery-rate__comment">' . esc_html( (string) $comment ) . '</div>';
			}
		}
	}

	private function render_planned_delivery_comment( string $comment ): void {
		$comment = trim( $comment );
		if ( '' !== $comment ) {
			echo '<div class="wdc-order-delivery-rate__planned-comment">' . esc_html( $comment ) . '</div>';
		}
	}

	private function render_price_cluster( string $price_html, string $crossed_price_html ): void {
		echo '<span class="wdc-order-delivery-rate__prices">';
		echo '<span class="wdc-order-delivery-rate__price">' . esc_html( $price_html ) . '</span>';
		if ( '' !== trim( $crossed_price_html ) ) {
			echo '<span class="wdc-order-delivery-rate__crossed">' . esc_html( $crossed_price_html ) . '</span>';
		}
		echo '</span>';
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function render_compact_summary( array $rate, string $fallback_title ): void {
		$title = $this->title_with_delivery_comment(
			(string) ( $rate['compact_title'] ?? $fallback_title ),
			(string) ( $rate['compact_delivery_comment'] ?? '' )
		);
		$price = (string) ( $rate['compact_price_html'] ?? $rate['price_html'] ?? '' );
		$crossed = (string) ( $rate['compact_crossed_price_html'] ?? $rate['crossed_price_html'] ?? '' );
		echo '<div class="wdc-order-delivery-rate__compact-summary" data-wdc-order-delivery-compact-summary>';
		echo '<span class="wdc-order-delivery-rate__compact-title">' . esc_html( $title ) . '</span>';
		$this->render_price_cluster( $price, $crossed );
		echo '</div>';
	}

	private function title_with_delivery_comment( string $title, string $delivery_comment ): string {
		$title = trim( $title );
		$delivery_comment = trim( $delivery_comment );
		if ( '' === $delivery_comment || str_contains( $title, $delivery_comment ) ) {
			return $title;
		}

		return '' !== $title ? $title . ' - ' . $delivery_comment : $delivery_comment;
	}

	private function group_label( string $group ): string {
		return match ( $group ) {
			DeliveryType::PICKUP => 'Самовывоз',
			DeliveryType::COURIER => 'Курьерская доставка',
			default => 'Другие варианты',
		};
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function rate_payload_json( array $payload ): string {
		$encoded = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		return false === $encoded ? '{}' : (string) $encoded;
	}
}

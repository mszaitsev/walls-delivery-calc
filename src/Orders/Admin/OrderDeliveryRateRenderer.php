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

		echo '<article class="wdc-order-delivery-rate" data-wdc-order-delivery-rate data-rate-id="' . esc_attr( $id ) . '" data-delivery-type="' . esc_attr( $delivery_type ) . '" data-requires-pickup="' . esc_attr( $requires_pickup ? '1' : '0' ) . '">';
		echo '<label class="wdc-order-delivery-rate__header">';
		echo '<input type="radio" name="wdc_order_delivery_preview_rate" value="' . esc_attr( $id ) . '">';
		echo '<span class="wdc-order-delivery-rate__title">' . esc_html( (string) ( $rate['label'] ?? '' ) ) . '</span>';
		echo '<span class="wdc-order-delivery-rate__price">' . esc_html( (string) ( $rate['price_html'] ?? '' ) ) . '</span>';
		if ( '' !== trim( (string) ( $rate['crossed_price_html'] ?? '' ) ) ) {
			echo '<span class="wdc-order-delivery-rate__crossed">' . esc_html( (string) $rate['crossed_price_html'] ) . '</span>';
		}
		echo '</label>';
		if ( '' !== trim( (string) ( $rate['delivery_comment'] ?? '' ) ) ) {
			echo '<div class="wdc-order-delivery-rate__delivery">' . esc_html( (string) $rate['delivery_comment'] ) . '</div>';
		}
		$this->render_comments( is_array( $rate['comments'] ?? null ) ? $rate['comments'] : array() );
		$this->render_tariffs( is_array( $rate['tariff_variants'] ?? null ) ? $rate['tariff_variants'] : array(), $id );
		echo '<div class="wdc-order-delivery-rate__pickup-placeholder" data-wdc-pickup-placeholder hidden>' . esc_html__( 'Выбор ПВЗ будет добавлен следующим шагом.', 'walls-delivery-calc' ) . '</div>';
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
			echo '<label class="wdc-order-delivery-tariff">';
			echo '<input type="radio" name="wdc_order_delivery_preview_tariff_' . esc_attr( $group_id ) . '" value="' . esc_attr( $object ) . '">';
			echo '<span class="wdc-order-delivery-tariff__title">' . esc_html( (string) ( $tariff['title'] ?? '' ) ) . '</span>';
			if ( '' !== trim( (string) ( $tariff['delivery_comment'] ?? '' ) ) ) {
				echo '<span class="wdc-order-delivery-tariff__delivery">' . esc_html( (string) $tariff['delivery_comment'] ) . '</span>';
			}
			echo '<span class="wdc-order-delivery-tariff__price">' . esc_html( (string) ( $tariff['price_html'] ?? '' ) ) . '</span>';
			if ( '' !== trim( (string) ( $tariff['crossed_price_html'] ?? '' ) ) ) {
				echo '<span class="wdc-order-delivery-rate__crossed">' . esc_html( (string) $tariff['crossed_price_html'] ) . '</span>';
			}
			echo '</label>';
			$this->render_comments( is_array( $tariff['comments'] ?? null ) ? $tariff['comments'] : array() );
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

	private function group_label( string $group ): string {
		return match ( $group ) {
			DeliveryType::PICKUP => 'Самовывоз',
			DeliveryType::COURIER => 'Курьерская доставка',
			default => 'Другие варианты',
		};
	}
}

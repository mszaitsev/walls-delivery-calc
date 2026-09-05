<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Pickup\Presentation\PickupPointCardRenderer;

defined( 'ABSPATH' ) || exit;

final class CheckoutRateRenderer {
	public function __construct(
		private ?CheckoutSessionManager $session_manager = null,
		private ?PickupPointCardRenderer $card_renderer = null
	) {
		$this->session_manager ??= new CheckoutSessionManager();
		$this->card_renderer ??= new PickupPointCardRenderer();
	}

	public function register(): void {
		add_filter( 'woocommerce_cart_shipping_method_full_label', array( $this, 'append_crossed_price_to_label' ), 10, 2 );
		add_action( 'woocommerce_after_shipping_rate', array( $this, 'render' ), 10, 2 );
	}

	public function append_crossed_price_to_label( string $label, mixed $method ): string {
		$meta = $this->meta( $method );
		if ( array() === $meta || ! isset( $meta['carrier_key'] ) ) {
			return $label;
		}

		if ( ! is_array( $meta['crossed_price'] ?? null ) || ! isset( $meta['crossed_price']['amount_kopecks'] ) ) {
			return $label;
		}

		return $label . ' <span class="wdc-platform-crossed-price wdc-platform-crossed-price--inline">' . esc_html( $this->format_money( (int) $meta['crossed_price']['amount_kopecks'] ) ) . '</span>';
	}

	public function render( mixed $method, int|string $index = 0 ): void {
		$this->session_manager->expire_stale_yandex_5post_selection();
		$meta = $this->meta( $method );
		if ( array() === $meta || ! isset( $meta['carrier_key'] ) ) {
			return;
		}

		$classes = array( 'wdc-platform-rate-meta' );
		if ( ! empty( $meta['fallback_used'] ) || 'fallback' === (string) ( $meta['carrier_key'] ?? '' ) ) {
			$classes[] = 'wdc-platform-rate-meta--fallback';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		$this->render_tariff_selector( $meta );
		$this->render_pickup_selector( $meta, $method );
		$this->render_courier_address_summary( $meta );
		$this->render_customer_link_comments( $meta );

		if ( is_array( $meta['comments'] ?? null ) ) {
			foreach ( $meta['comments'] as $comment ) {
				if ( '' !== trim( (string) $comment ) ) {
					echo '<div class="wdc-platform-delivery-comment wdc-shipping-rate-comment">' . esc_html( (string) $comment ) . '</div>';
				}
			}
		}
		$planned_comment = trim( (string) ( $meta['planned_delivery_comment'] ?? '' ) );
		if ( '' !== $planned_comment ) {
			echo '<div class="wdc-platform-planned-delivery-comment wdc-shipping-rate-comment">' . esc_html( $planned_comment ) . '</div>';
		}
		$this->render_yandex_5post_warning( $meta, $method );

		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function render_customer_link_comments( array $meta ): void {
		$link_comments = is_array( $meta['customer_link_comments'] ?? null ) ? $meta['customer_link_comments'] : array();
		foreach ( $link_comments as $comment ) {
			if ( ! is_array( $comment ) ) {
				continue;
			}
			$url = trim( (string) ( $comment['url'] ?? '' ) );
			$label = trim( (string) ( $comment['label'] ?? '' ) );
			if ( '' === $url || '' === $label ) {
				continue;
			}
			$text_before = (string) ( $comment['text_before'] ?? '' );
			$text_after = (string) ( $comment['text_after'] ?? '' );
			echo '<div class="wdc-platform-delivery-comment wdc-platform-delivery-link-comment wdc-shipping-rate-comment">';
			if ( '' !== trim( $text_before ) ) {
				echo esc_html( $text_before );
			}
			echo '<a class="wdc-platform-delivery-comment-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
			if ( '' !== trim( $text_after ) ) {
				echo esc_html( $text_after );
			}
			echo '</div>';
		}
	}

	private function render_yandex_5post_warning( array $meta, mixed $method ): void {
		$rate_id = (string) ( $meta['rate_id'] ?? $this->method_id( $method ) );
		$family = $this->session_manager->normalize_pickup_family( (string) ( $meta['pickup_family'] ?? $this->session_manager->shipping_method_family( $rate_id ) ) );
		if ( 'yandex_pickup' !== $this->session_manager->normalize_rate_id( $rate_id ) || 'yandex_delivery:pickup' !== $family ) {
			return;
		}

		$selection = $this->session_manager->pickup_selection_for_family( $family );
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$operator_id = strtolower( trim( (string) ( $selection['operator_id'] ?? $snapshot['operator_id'] ?? '' ) ) );
		if ( '5post' !== $operator_id || ! $this->session_manager->valid_pickup_selection_for_checkout( $family ) ) {
			return;
		}

		echo '<div class="wdc-yandex-5post-warning">' . esc_html( __( 'При выборе 5Post цена доставки могла стать дороже. Попробуйте выбрать другой ПВЗ', 'walls-delivery-calc' ) ) . '</div>';
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function render_pickup_selector( array $meta, mixed $method ): void {
		if ( empty( $meta['requires_pickup_point'] ) || DeliveryType::PICKUP !== (string) ( $meta['delivery_type'] ?? '' ) ) {
			return;
		}
		if ( $this->skip_pickup_selection( $meta ) ) {
			return;
		}

		$carrier_key = (string) ( $meta['carrier_key'] ?? '' );
		if ( '' === $carrier_key ) {
			return;
		}

		$rate_id = (string) ( $meta['rate_id'] ?? $this->method_id( $method ) );
		$family = trim( (string) ( $meta['pickup_family'] ?? '' ) );
		$family = '' !== $family ? $this->session_manager->normalize_pickup_family( $family ) : $this->session_manager->shipping_method_family( $rate_id );
		$selection = $this->session_manager->checkout_pickup_point_for_family( $family );
		$matches = $this->session_manager->pickup_selection_matches( $carrier_key, $rate_id, $family );
		$has_selection = $matches
			&& array() !== $selection
			&& '' !== trim( (string) ( $selection['point_code'] ?? '' ) )
			&& '' !== trim( (string) ( $selection['point_address'] ?? $selection['address'] ?? '' ) );

		echo '<div class="wdc-rp-pickup-checkout" data-wdc-pickup-checkout data-shipping-method-id="' . esc_attr( $rate_id ) . '" data-carrier-key="' . esc_attr( $carrier_key ) . '">';
		echo '<input type="hidden" name="wdc_platform_pickup_rate_id" value="' . esc_attr( $rate_id ) . '">';
		echo '<input type="hidden" name="wdc_platform_pickup_carrier" value="' . esc_attr( $carrier_key ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_id" data-wdc-pickup-point-id value="' . esc_attr( (string) ( $selection['id'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_code" data-wdc-pickup-point-code value="' . esc_attr( (string) ( $selection['point_code'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_carrier_key" data-wdc-pickup-carrier-key value="' . esc_attr( (string) ( $selection['carrier_key'] ?? $carrier_key ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_service_key" data-wdc-pickup-service-key value="' . esc_attr( (string) ( $selection['service_key'] ?? $carrier_key ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_family" data-wdc-pickup-family value="' . esc_attr( (string) ( $selection['pickup_family'] ?? $family ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_type" data-wdc-pickup-point-type value="' . esc_attr( (string) ( $selection['point_type'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_type_label" data-wdc-pickup-point-type-label value="' . esc_attr( (string) ( $selection['point_type_label'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_title" data-wdc-pickup-point-title value="' . esc_attr( (string) ( $selection['point_title'] ?? $selection['card_title'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_name" data-wdc-pickup-point-name value="' . esc_attr( (string) ( $selection['point_name'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_address" data-wdc-pickup-point-address value="' . esc_attr( (string) ( $selection['point_address'] ?? $selection['address'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_postcode" data-wdc-pickup-point-postcode value="' . esc_attr( (string) ( $selection['point_postcode'] ?? $selection['postcode'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_city_name" data-wdc-pickup-city-name value="' . esc_attr( (string) ( $selection['city_name'] ?? $selection['city'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_region_name" data-wdc-pickup-region-name value="' . esc_attr( (string) ( $selection['region_name'] ?? $selection['region'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_work_time" data-wdc-pickup-work-time-field value="' . esc_attr( (string) ( $selection['point_work_time'] ?? $selection['work_time'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_comment" data-wdc-pickup-point-comment-field value="' . esc_attr( (string) ( $selection['point_comment'] ?? $selection['snapshot']['point_comment'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_description" data-wdc-pickup-description-field value="' . esc_attr( (string) ( $selection['description'] ?? $selection['point_comment'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_storage_notice" data-wdc-pickup-storage-notice-field value="' . esc_attr( (string) ( $selection['storage_notice'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_marker_type" data-wdc-pickup-marker-type value="' . esc_attr( (string) ( $selection['marker_type'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_cdek_code" data-wdc-pickup-cdek-code value="' . esc_attr( (string) ( $selection['cdek_code'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_location_id" data-wdc-pickup-location-id value="' . esc_attr( (string) ( $selection['location_id'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_fias_id" data-wdc-pickup-fias-id value="' . esc_attr( (string) ( $selection['fias_id'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_gar_object_id" data-wdc-pickup-gar-object-id value="' . esc_attr( (string) ( $selection['gar_object_id'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_destination_fingerprint" data-wdc-pickup-destination-fingerprint value="' . esc_attr( (string) ( $selection['destination_fingerprint'] ?? '' ) ) . '">';
		$empty_button_class = 'button wdc-rp-pickup-checkout__button' . ( $has_selection ? ' wdc-is-hidden' : '' );
		echo '<button type="button" class="' . esc_attr( $empty_button_class ) . '" data-wdc-pickup-open data-wdc-pickup-empty-open aria-hidden="' . esc_attr( $has_selection ? 'true' : 'false' ) . '"' . ( $has_selection ? ' hidden style="display:none;"' : '' ) . '>' . esc_html( __( 'Выбрать пункт выдачи', 'walls-delivery-calc' ) ) . '</button>';
		$this->render_pickup_inline_notice( $meta );
		echo $this->card_renderer->render(
			array_merge(
				$selection,
				array(
					'carrier_key' => $carrier_key,
					'rate_id'     => $rate_id,
				)
			),
			true,
			! $has_selection,
			false
		);
		echo '</div>';
	}

	/** @param array<string,mixed> $meta */
	private function render_pickup_inline_notice( array $meta ): void {
		$message = $this->pickup_inline_notice_message( $meta );
		echo '<div class="wdc-pickup-inline-notice" data-wdc-pickup-inline-notice role="status" aria-live="polite"' . ( '' === $message ? ' hidden' : '' ) . '>' . esc_html( $message ) . '</div>';
	}

	/** @param array<string,mixed> $meta */
	private function pickup_inline_notice_message( array $meta ): string {
		$rate_meta = is_array( $meta['rate_meta'] ?? null ) ? $meta['rate_meta'] : array();
		if ( empty( $rate_meta['pickup_selection_rejected'] ) && empty( $meta['pickup_selection_rejected'] ) ) {
			return '';
		}

		return trim( (string) ( $rate_meta['pickup_selection_rejected_message'] ?? $meta['pickup_selection_rejected_message'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function skip_pickup_selection( array $meta ): bool {
		if ( ! empty( $meta['no_pickup_selection'] ) ) {
			return true;
		}

		$rate_meta = $meta['rate_meta'] ?? array();
		return is_array( $rate_meta ) && ! empty( $rate_meta['no_pickup_selection'] );
	}

	private function method_id( mixed $method ): string {
		return is_object( $method ) && isset( $method->id ) ? (string) $method->id : '';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function meta( mixed $method ): array {
		return WooCommerceRateMetaNormalizer::meta( $method );
	}

	private function format_money( int $kopecks ): string {
		return rtrim( rtrim( number_format( $kopecks / 100, 2, '.', ' ' ), '0' ), '.' ) . ' руб.';
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function render_courier_address_summary( array $meta ): void {
		if ( ! CourierRateSupport::is_courier_meta( $meta ) ) {
			return;
		}

		$field = $this->checkout_address_field();
		$prefix = str_starts_with( $field, 'shipping_' ) ? 'shipping' : 'billing';
		$address_1 = $this->posted_value( $prefix . '_address_1' );
		$has_address_1 = '' !== trim( $address_1 );
		$address = $has_address_1 ? $this->format_address_parts( $this->checkout_address_parts( $prefix ) ) : '';
		$hidden_value = $has_address_1 ? '' : ' hidden';
		$hidden_warning = $has_address_1 ? ' hidden' : '';

		echo '<div class="wdc-courier-address-summary" data-wdc-courier-address-summary data-address-field="' . esc_attr( $field ) . '">';
		echo '<div class="wdc-courier-address-summary__title">' . esc_html__( 'Доставка курьером по адресу:', 'walls-delivery-calc' ) . '</div>';
		echo '<div class="wdc-courier-address-summary__value"' . $hidden_value . '>' . esc_html( $address ) . '</div>';
		echo '<div class="wdc-courier-address-summary__warning"' . $hidden_warning . '>';
		echo esc_html__( 'Для доставки курьером необходимо ', 'walls-delivery-calc' );
		echo '<a href="#' . esc_attr( $field ) . '">' . esc_html__( 'заполнить адрес', 'walls-delivery-calc' ) . '</a>.';
		echo '</div>';
		echo '</div>';
	}

	private function checkout_address_field(): string {
		$ship_to_different = $this->posted_value( 'ship_to_different_address' );
		if ( '' !== trim( $ship_to_different ) ) {
			return 'shipping_address_1';
		}

		if ( $this->posted_has( 'billing_address_1' ) ) {
			return 'billing_address_1';
		}

		return $this->posted_has( 'shipping_address_1' ) ? 'shipping_address_1' : 'billing_address_1';
	}

	/**
	 * @return array<int,string>
	 */
	private function checkout_address_parts( string $prefix ): array {
		return array(
			$this->posted_value( $prefix . '_postcode' ),
			$this->posted_value( $prefix . '_city' ),
			$this->posted_value( $prefix . '_address_1' ),
		);
	}

	/**
	 * @param array<int,string> $parts
	 */
	private function format_address_parts( array $parts ): string {
		$parts = array_values( array_filter( array_map( static fn ( string $part ): string => trim( $part ), $parts ), static fn ( string $part ): bool => '' !== $part ) );

		return implode( ', ', $parts );
	}

	private function posted_value( string $key ): string {
		if ( isset( $_POST[ $key ] ) && ! is_array( $_POST[ $key ] ) ) {
			$value = function_exists( 'wp_unslash' ) ? wp_unslash( $_POST[ $key ] ) : $_POST[ $key ];
			return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) );
		}

		if ( function_exists( 'WC' ) && is_object( WC() ) && method_exists( WC(), 'checkout' ) ) {
			$checkout = WC()->checkout();
			if ( is_object( $checkout ) && method_exists( $checkout, 'get_value' ) ) {
				$value = $checkout->get_value( $key );
				return is_scalar( $value ) ? trim( (string) $value ) : '';
			}
		}

		return '';
	}

	private function posted_has( string $key ): bool {
		return isset( $_POST ) && is_array( $_POST ) && array_key_exists( $key, $_POST );
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function render_tariff_selector( array $meta ): void {
		$variants = is_array( $meta['tariff_variants'] ?? null ) ? $meta['tariff_variants'] : array();
		if ( count( $variants ) < 2 ) {
			return;
		}
		$service_key = (string) ( $meta['service_key'] ?? '' );
		$rate_meta = is_array( $meta['rate_meta'] ?? null ) ? $meta['rate_meta'] : array();
		$checkout_group_id = (string) ( $meta['checkout_group_id'] ?? $rate_meta['checkout_group_id'] ?? $meta['rate_id'] ?? $service_key );
		$delivery_type = (string) ( $meta['delivery_type'] ?? '' );
		$selected = (string) ( $meta['selected_tariff_object'] ?? '' );
		echo '<div class="wdc-domestic-tariff-selector" data-wdc-service-key="' . esc_attr( $service_key ) . '" data-wdc-checkout-group-id="' . esc_attr( $checkout_group_id ) . '" data-wdc-delivery-type="' . esc_attr( $delivery_type ) . '">';
		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}
			$object = (string) ( $variant['object_code'] ?? '' );
			$title = (string) ( $variant['title'] ?? '' );
			$delivery_days_label = (string) ( $variant['delivery_days_label'] ?? '' );
			if ( '' === $delivery_days_label && is_array( $variant['delivery_days'] ?? null ) ) {
				$delivery_days_label = DeliveryDaysFormatter::format_array( $variant['delivery_days'] );
			}
			$planned_comment = (string) ( $variant['planned_delivery_comment'] ?? '' );
			$price = isset( $variant['price_rub'] ) ? $this->format_rubles( (float) $variant['price_rub'] ) : '';
			$crossed = is_array( $variant['crossed_price'] ?? null ) && isset( $variant['crossed_price']['amount_kopecks'] )
				? $this->format_money( (int) $variant['crossed_price']['amount_kopecks'] )
				: '';
			$line = $title;
			if ( '' !== $delivery_days_label ) {
				$line .= ' - ' . $delivery_days_label;
			}
			if ( '' !== $price ) {
				$line .= ': ';
			}
			echo '<label class="wdc-domestic-tariff-selector__item">';
			echo '<input type="radio" name="wdc_domestic_tariff_' . esc_attr( $checkout_group_id ) . '" value="' . esc_attr( $object ) . '" data-title="' . esc_attr( $title ) . '" data-price="' . esc_attr( (string) ( $variant['price_rub'] ?? '' ) ) . '" data-delivery-days-label="' . esc_attr( $delivery_days_label ) . '" data-planned-delivery-date="' . esc_attr( (string) ( $variant['planned_delivery_date'] ?? '' ) ) . '" data-planned-delivery-comment="' . esc_attr( $planned_comment ) . '" ' . checked( $selected, $object, false ) . '>';
			echo '<span class="wdc-domestic-tariff-selector__line">';
			echo '<span class="wdc-domestic-tariff-selector__line-text">' . esc_html( $line ) . '</span>';
			if ( '' !== $price ) {
				echo '<span class="wdc-domestic-tariff-selector__price">' . esc_html( $price ) . '</span>';
			}
			if ( '' !== $crossed ) {
				echo ' <span class="wdc-platform-crossed-price wdc-domestic-tariff-selector__crossed-price">' . esc_html( $crossed ) . '</span>';
			}
			echo '</span>';
			echo '</label>';
		}
		echo '</div>';
	}

	private function format_rubles( float $rubles ): string {
		return rtrim( rtrim( number_format( $rubles, 2, '.', ' ' ), '0' ), '.' ) . ' руб.';
	}
}

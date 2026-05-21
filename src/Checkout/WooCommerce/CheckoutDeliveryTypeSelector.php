<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Pickup\Services\PickupProviderInterface;
use WallsShop\WDC\Pickup\Storage\PickupPointRepository;

defined( 'ABSPATH' ) || exit;

final class CheckoutDeliveryTypeSelector {
	public function __construct(
		private CheckoutSessionManager $session_manager,
		private PickupPointRepository $repository,
		private PickupProviderInterface $pickup_provider,
		private PickupPointRenderer $renderer
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_after_shipping_rate', array( $this, 'render' ), 20, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'capture_update_order_review' ), 10, 1 );
		add_action( 'wp_loaded', array( $this, 'capture_posted_selection' ) );
	}

	public function capture_update_order_review( string $posted_data ): void {
		parse_str( $posted_data, $data );
		$this->capture( is_array( $data ) ? $data : array() );
	}

	public function capture_posted_selection(): void {
		$this->capture( $_POST );
	}

	public function render( mixed $method, int|string $index = 0 ): void {
		$meta = $this->meta( $method );
		if ( array() === $meta || ! isset( $meta['carrier_key'], $meta['delivery_type'] ) ) {
			return;
		}

		$delivery_type = (string) $meta['delivery_type'];
		if ( ! in_array( $delivery_type, array( DeliveryType::PICKUP, DeliveryType::COURIER ), true ) ) {
			return;
		}

		$rate_id = (string) ( $meta['rate_id'] ?? $this->method_id( $method ) );
		echo '<div class="wdc-delivery-type-controls">';
		echo '<label><input type="radio" name="wdc_platform_delivery_type" value="' . esc_attr( $delivery_type ) . '" ' . checked( $this->selected_delivery_type_for_rate( $delivery_type, $rate_id ), $delivery_type, false ) . '> ' . esc_html( $this->label_for_type( $delivery_type ) ) . '</label>';
		echo '</div>';

		if ( ! empty( $meta['requires_pickup_point'] ) ) {
			$this->render_pickup_selector( (string) $meta['carrier_key'], $rate_id );
		}

		if ( ! empty( $meta['requires_courier_address'] ) ) {
			echo '<div class="wdc-courier-notice">' . esc_html__( 'Курьерская доставка будет оформлена на адрес из checkout.', 'walls-delivery-calc' ) . '</div>';
		}
	}

	private function render_pickup_selector( string $carrier_key, string $rate_id ): void {
		$points    = $this->points_for_checkout( $carrier_key );
		$selection = $this->session_manager->pickup_selection();
		$selected  = (string) ( $selection['point_code'] ?? '' );

		echo '<div class="wdc-pickup-selector">';
		echo '<input type="hidden" name="wdc_platform_pickup_rate_id" value="' . esc_attr( $rate_id ) . '">';
		echo '<input type="hidden" name="wdc_platform_pickup_carrier" value="' . esc_attr( $carrier_key ) . '">';
		echo '<label><span>' . esc_html__( 'Пункт выдачи', 'walls-delivery-calc' ) . '</span>';
		echo '<select name="wdc_platform_pickup_point">';
		echo '<option value="">' . esc_html__( 'Выберите пункт выдачи', 'walls-delivery-calc' ) . '</option>';
		foreach ( $points as $point ) {
			echo '<option value="' . esc_attr( $point->code ) . '" ' . selected( $selected, $point->code, false ) . '>' . esc_html( $point->city . ' - ' . $point->address ) . '</option>';
		}
		echo '</select></label>';

		if ( '' !== $selected ) {
			$point = $this->repository->find_by_code( $carrier_key, $selected ) ?? $this->find_demo_point( $points, $selected );
			if ( $point instanceof PickupPoint ) {
				echo $this->renderer->render( $point );
			}
		}

		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function capture( array $data ): void {
		$delivery_type = isset( $data['wdc_platform_delivery_type'] ) ? sanitize_text_field( wp_unslash( (string) $data['wdc_platform_delivery_type'] ) ) : '';
		if ( in_array( $delivery_type, array( DeliveryType::PICKUP, DeliveryType::COURIER ), true ) ) {
			$this->session_manager->save_selected_delivery_type( $delivery_type );
		}

		$carrier = isset( $data['wdc_platform_pickup_carrier'] ) ? sanitize_text_field( wp_unslash( (string) $data['wdc_platform_pickup_carrier'] ) ) : '';
		$code    = isset( $data['wdc_platform_pickup_point'] ) ? sanitize_text_field( wp_unslash( (string) $data['wdc_platform_pickup_point'] ) ) : '';
		$rate_id = isset( $data['wdc_platform_pickup_rate_id'] ) ? sanitize_text_field( wp_unslash( (string) $data['wdc_platform_pickup_rate_id'] ) ) : '';

		if ( '' === $carrier || '' === $code ) {
			return;
		}

		$point = $this->repository->find_by_code( $carrier, $code ) ?? $this->find_demo_point( $this->points_for_checkout( $carrier ), $code );
		if ( ! $point instanceof PickupPoint ) {
			return;
		}

		$this->session_manager->save_pickup_selection(
			array(
				'carrier_key'      => $carrier,
				'rate_id'          => $rate_id,
				'point_code'       => $point->code,
				'point_address'    => $point->address,
				'point_comment'    => $point->comment,
				'point_work_time'  => $point->work_time,
				'selected_at'      => gmdate( 'c' ),
			)
		);
	}

	/**
	 * @return array<int,PickupPoint>
	 */
	private function points_for_checkout( string $carrier_key ): array {
		$destination = $this->checkout_destination();
		$points      = $this->repository->search( $carrier_key, $destination->country_code ?: 'RU', $destination->city );

		return array() !== $points ? $points : $this->pickup_provider->get_points( $carrier_key, $destination );
	}

	private function checkout_destination(): Address {
		$country = 'RU';
		$city    = '';
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->customer ) && is_object( WC()->customer ) ) {
			$customer = WC()->customer;
			$country  = method_exists( $customer, 'get_shipping_country' ) ? (string) $customer->get_shipping_country() : $country;
			$city     = method_exists( $customer, 'get_shipping_city' ) ? (string) $customer->get_shipping_city() : $city;
		}

		return new Address( country_code: '' !== $country ? $country : 'RU', city: $city );
	}

	/**
	 * @param array<int,PickupPoint> $points
	 */
	private function find_demo_point( array $points, string $code ): ?PickupPoint {
		foreach ( $points as $point ) {
			if ( $point->code === $code ) {
				return $point;
			}
		}

		return null;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function meta( mixed $method ): array {
		if ( is_object( $method ) && method_exists( $method, 'get_meta_data' ) ) {
			$meta = $method->get_meta_data();
			return is_array( $meta ) ? $meta : array();
		}

		return is_object( $method ) && isset( $method->meta_data ) && is_array( $method->meta_data ) ? $method->meta_data : array();
	}

	private function method_id( mixed $method ): string {
		return is_object( $method ) && isset( $method->id ) ? (string) $method->id : '';
	}

	private function selected_delivery_type_for_rate( string $delivery_type, string $rate_id ): string {
		$selected = $this->session_manager->selected_delivery_type();
		if ( '' !== $selected ) {
			return $selected;
		}

		return $this->is_chosen_rate( $rate_id ) ? $delivery_type : '';
	}

	private function is_chosen_rate( string $rate_id ): bool {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->session ) || ! is_object( WC()->session ) || ! method_exists( WC()->session, 'get' ) ) {
			return false;
		}

		$chosen = WC()->session->get( 'chosen_shipping_methods', array() );
		if ( ! is_array( $chosen ) ) {
			return false;
		}

		return in_array( $rate_id, $chosen, true ) || in_array( NewShippingMethod::METHOD_ID . ':' . $rate_id, $chosen, true );
	}

	private function label_for_type( string $delivery_type ): string {
		return DeliveryType::PICKUP === $delivery_type ? __( 'Пункт выдачи', 'walls-delivery-calc' ) : __( 'Курьер', 'walls-delivery-calc' );
	}
}

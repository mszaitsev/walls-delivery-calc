<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Pickup\Presentation\PickupPointCardRenderer;
use WallsShop\WDC\Pickup\Storage\PickupPointRepository;

defined( 'ABSPATH' ) || exit;

final class CheckoutDeliveryTypeSelector {
	public function __construct(
		private CheckoutSessionManager $session_manager,
		private PickupPointRepository $repository,
		private PickupPointRenderer $renderer,
		private ?PickupPointCardRenderer $card_renderer = null
	) {
		$this->card_renderer ??= new PickupPointCardRenderer();
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
		if ( ! empty( $meta['requires_pickup_point'] ) && ! $this->skip_pickup_selection( $meta ) ) {
			$this->render_pickup_map_selector( (string) $meta['carrier_key'], $rate_id );
		}
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function skip_pickup_selection( array $meta ): bool {
		if ( ! empty( $meta['no_pickup_selection'] ) ) {
			return true;
		}

		$rate_meta = $meta['rate_meta'] ?? array();
		if ( is_array( $rate_meta ) && ! empty( $rate_meta['no_pickup_selection'] ) ) {
			return true;
		}

		return false;
	}

	private function render_pickup_selector( string $carrier_key, string $rate_id ): void {
		$points    = $this->points_for_checkout( $carrier_key );
		$selection = $this->session_manager->pickup_selection();
		$selected  = (string) ( $selection['point_code'] ?? '' );

		echo '<div class="wdc-pickup-selector">';
		echo '<input type="hidden" name="wdc_platform_pickup_rate_id" value="' . esc_attr( $rate_id ) . '">';
		echo '<input type="hidden" name="wdc_platform_pickup_carrier" value="' . esc_attr( $carrier_key ) . '">';
		echo '<label><span>' . esc_html__( 'Пункт выдачи', 'walls-delivery-calc' ) . '</span>';
		echo '<select class="wdc-platform-pickup-point" name="wdc_platform_pickup_point">';
		echo '<option value="">' . esc_html__( 'Выберите пункт выдачи', 'walls-delivery-calc' ) . '</option>';
		foreach ( $points as $point ) {
			echo '<option value="' . esc_attr( $point->code ) . '" ' . selected( $selected, $point->code, false ) . '>' . esc_html( $point->city . ' - ' . $point->address ) . '</option>';
		}
		echo '</select></label>';

		if ( '' !== $selected ) {
			$point = $this->find_point( $points, $selected );
			if ( $point instanceof PickupPoint ) {
				echo $this->renderer->render( $point );
			}
		}

		echo '</div>';
	}

	private function render_pickup_map_selector( string $carrier_key, string $rate_id ): void {
		$selection = $this->session_manager->checkout_pickup_point();
		$matches = $this->session_manager->pickup_selection_matches( $carrier_key, $rate_id );
		$has_selection = $matches && array() !== $selection && '' !== trim( (string) ( $selection['point_code'] ?? '' ) );

		echo '<div class="wdc-rp-pickup-checkout" data-wdc-pickup-checkout data-shipping-method-id="' . esc_attr( $rate_id ) . '" data-carrier-key="' . esc_attr( $carrier_key ) . '">';
		echo '<input type="hidden" name="wdc_platform_pickup_rate_id" value="' . esc_attr( $rate_id ) . '">';
		echo '<input type="hidden" name="wdc_platform_pickup_carrier" value="' . esc_attr( $carrier_key ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_id" data-wdc-pickup-point-id value="' . esc_attr( (string) ( $selection['id'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_code" data-wdc-pickup-point-code value="' . esc_attr( (string) ( $selection['point_code'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_carrier_key" data-wdc-pickup-carrier-key value="' . esc_attr( (string) ( $selection['carrier_key'] ?? $carrier_key ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_service_key" data-wdc-pickup-service-key value="' . esc_attr( (string) ( $selection['service_key'] ?? $carrier_key ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_family" data-wdc-pickup-family value="' . esc_attr( (string) ( $selection['pickup_family'] ?? $this->session_manager->shipping_method_family( $rate_id ) ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_type" data-wdc-pickup-point-type value="' . esc_attr( (string) ( $selection['point_type'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_type_label" data-wdc-pickup-point-type-label value="' . esc_attr( (string) ( $selection['point_type_label'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_title" data-wdc-pickup-point-title value="' . esc_attr( (string) ( $selection['point_title'] ?? $selection['card_title'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_name" data-wdc-pickup-point-name value="' . esc_attr( (string) ( $selection['point_name'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_address" data-wdc-pickup-point-address value="' . esc_attr( (string) ( $selection['point_address'] ?? $selection['address'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_point_postcode" data-wdc-pickup-point-postcode value="' . esc_attr( (string) ( $selection['point_postcode'] ?? $selection['postcode'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_city_name" data-wdc-pickup-city-name value="' . esc_attr( (string) ( $selection['city_name'] ?? $selection['city'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_region_name" data-wdc-pickup-region-name value="' . esc_attr( (string) ( $selection['region_name'] ?? $selection['region'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_work_time" data-wdc-pickup-work-time-field value="' . esc_attr( (string) ( $selection['point_work_time'] ?? $selection['work_time'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_description" data-wdc-pickup-description-field value="' . esc_attr( (string) ( $selection['description'] ?? $selection['point_comment'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_storage_notice" data-wdc-pickup-storage-notice-field value="' . esc_attr( (string) ( $selection['storage_notice'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_marker_type" data-wdc-pickup-marker-type value="' . esc_attr( (string) ( $selection['marker_type'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="wdc_pickup_cdek_code" data-wdc-pickup-cdek-code value="' . esc_attr( (string) ( $selection['cdek_code'] ?? '' ) ) . '">';
		$empty_button_class = 'button wdc-rp-pickup-checkout__button' . ( $has_selection ? ' wdc-is-hidden' : '' );
		echo '<button type="button" class="' . esc_attr( $empty_button_class ) . '" data-wdc-pickup-open data-wdc-pickup-empty-open aria-hidden="' . esc_attr( $has_selection ? 'true' : 'false' ) . '"' . ( $has_selection ? ' hidden style="display:none;"' : '' ) . '>' . esc_html( __( 'Выбрать пункт выдачи', 'walls-delivery-calc' ) ) . '</button>';
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

	/**
	 * @param array<string,mixed> $data
	 */
	private function capture( array $data ): void {
		$carrier = isset( $data['wdc_platform_pickup_carrier'] ) ? sanitize_text_field( wp_unslash( (string) $data['wdc_platform_pickup_carrier'] ) ) : '';
		$code    = isset( $data['wdc_platform_pickup_point'] ) ? sanitize_text_field( wp_unslash( (string) $data['wdc_platform_pickup_point'] ) ) : '';
		$rate_id = isset( $data['wdc_platform_pickup_rate_id'] ) ? sanitize_text_field( wp_unslash( (string) $data['wdc_platform_pickup_rate_id'] ) ) : '';

		if ( '' === $carrier || '' === $code ) {
			return;
		}

		$point = $this->find_point( $this->points_for_checkout( $carrier ), $code );
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
		return $this->repository->search( $carrier_key, $destination->country_code ?: 'RU', $destination->city );
	}

	private function checkout_destination(): Address {
		$country = 'RU';
		$city    = '';
		$selected_city = $this->session_manager->selected_city();
		if ( ! empty( $selected_city['city_name'] ) ) {
			return new Address(
				country_code: ! empty( $selected_city['country_code'] ) ? (string) $selected_city['country_code'] : $country,
				region_name: (string) ( $selected_city['region_name'] ?? '' ),
				city: (string) $selected_city['city_name'],
				postcode: (string) ( $selected_city['postal_code'] ?? '' ),
				fias_id: (string) ( $selected_city['fias_id'] ?? '' ),
				gar_id: (string) ( $selected_city['gar_id'] ?? '' ),
				normalized: true
			);
		}

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
	private function find_point( array $points, string $code ): ?PickupPoint {
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
}

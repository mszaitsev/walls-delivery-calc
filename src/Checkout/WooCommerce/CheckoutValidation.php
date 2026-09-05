<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryCheckoutPickupPointFormatter;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Checkout\Validation\CheckoutAddressValidation;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;

defined( 'ABSPATH' ) || exit;

final class CheckoutValidation {
	private bool $stale_yandex_5post_post_blocked = false;

	public function __construct(
		private CheckoutSessionManager $session_manager,
		private ?CheckoutAddressValidation $address_validation = null,
		private ?RussianPostPickupPointRepository $pickup_repository = null,
		private ?DpdPickupPointService $dpd_pickup_points = null,
		private ?YandexDeliveryPickupPointV2Repository $yandex_pickup_points = null,
		private ?YandexDeliveryCheckoutPickupPointFormatter $yandex_formatter = null
	) {
		$this->yandex_formatter ??= new YandexDeliveryCheckoutPickupPointFormatter();
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_process', array( $this, 'preload_from_post' ), 5, 0 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 20, 2 );
	}

	public function preload_from_post(): void {
		$data = $this->posted_checkout_data();
		$chosen_methods = $this->chosen_shipping_methods( $data );
		$chosen_rate_id = $this->first_chosen_shipping_method( $chosen_methods );
		$expired = $this->session_manager->expire_stale_yandex_5post_selection();
		if ( $expired && $this->is_yandex_pickup_rate( $chosen_rate_id ) ) {
			$this->stale_yandex_5post_post_blocked = true;
		}

		if ( $this->stale_yandex_5post_post_blocked && $this->is_yandex_pickup_rate( $chosen_rate_id ) ) {
			return;
		}

		if ( ! $this->is_supported_pickup_family( $chosen_rate_id ) ) {
			return;
		}

		$point_id = max( 0, (int) ( $data['wdc_pickup_point_id'] ?? 0 ) );
		$point_code = $this->posted_string( $data, 'wdc_pickup_point_code' );
		if ( $point_id <= 0 && '' === $point_code ) {
			return;
		}

		$this->restore_posted_pickup_selection( $data, $this->synthetic_pickup_rate( $chosen_rate_id ) );
	}

	public function validate( mixed $data = array(), mixed $errors = null ): void {
		$data = is_array( $data ) ? $data : array();
		$chosen_methods = $this->chosen_shipping_methods( $data );
		$chosen_rate_id = $this->first_chosen_shipping_method( $chosen_methods );
		$expired = $this->session_manager->expire_stale_yandex_5post_selection();
		if ( $expired && $this->is_yandex_pickup_rate( $chosen_rate_id ) ) {
			$this->stale_yandex_5post_post_blocked = true;
		}
		$rate = $this->selected_rate( $data );
		if ( array() === $rate && $this->is_supported_pickup_family( $chosen_rate_id ) ) {
			$rate = $this->synthetic_pickup_rate( $chosen_rate_id );
		}
		if ( array() === $rate ) {
			return;
		}

		$delivery_type = (string) ( $rate['delivery_type'] ?? '' );
		$this->validate_city( $delivery_type, $data, $errors );
		if ( $this->is_courier_rate( $rate ) ) {
			$this->validate_courier_address( $data, $errors );
			return;
		}

		if ( ! $this->selected_rate_requires_pickup_point( $rate, $delivery_type ) ) {
			return;
		}

		$selected_rate_id = $this->selected_rate_id( $rate );
		$active_family = $this->rate_pickup_family( $rate, $selected_rate_id );
		if ( $this->stale_yandex_5post_post_blocked && YandexDeliverySettings::CARRIER_KEY . ':pickup' === $active_family ) {
			$this->stale_yandex_5post_post_blocked = false;
			$this->add_pickup_error( $errors, $rate );
			return;
		}
		$this->stale_yandex_5post_post_blocked = false;
		$active_selection = $this->session_manager->pickup_selection_for_family( $active_family );
		if ( array() !== $active_selection && $this->session_manager->valid_pickup_selection_for_checkout( $active_family ) ) {
			$this->session_manager->update_pickup_selection_rate_id( $selected_rate_id, $active_family );
			return;
		}
		if ( PekSettings::PICKUP_FAMILY === $active_family && array() !== $active_selection ) {
			$this->session_manager->clear_pickup_selection_for_family( $active_family, 'stale_pek_destination_selection' );
		}
		$matches_before_restore = $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), $selected_rate_id );
		if ( $matches_before_restore ) {
			$this->session_manager->update_pickup_selection_rate_id( $selected_rate_id, $active_family );
			return;
		}
		$restored = $this->restore_posted_pickup_selection( $data, $rate );
		if ( $restored ) {
			$this->session_manager->update_pickup_selection_rate_id( $selected_rate_id, $active_family );
			return;
		}

		$this->add_pickup_error( $errors, $rate );
	}

	private function validate_city( string $delivery_type, array $data, mixed $errors = null ): void {
		if ( ! in_array( $delivery_type, array( DeliveryType::PICKUP, DeliveryType::COURIER ), true ) ) {
			return;
		}

		$validator = $this->address_validation ?? new CheckoutAddressValidation( $this->session_manager );
		if ( $validator->has_city( $data ) ) {
			return;
		}

		$this->add_city_error( $errors );
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function add_pickup_error( mixed $errors = null, array $rate = array() ): void {
		$carrier = (string) ( $rate['carrier_key'] ?? '' );
		$message = match ( $carrier ) {
			RussianPostDomesticSettings::CARRIER_KEY => __( 'Выберите пункт выдачи Почты России.', 'walls-delivery-calc' ),
			DpdSettings::CARRIER_KEY => __( 'Выберите пункт выдачи DPD.', 'walls-delivery-calc' ),
			YandexDeliverySettings::CARRIER_KEY => __( 'Выберите пункт выдачи Яндекс.Доставки.', 'walls-delivery-calc' ),
			PekSettings::CARRIER_KEY => __( 'Выберите терминал ПЭК.', 'walls-delivery-calc' ),
			default => __( 'Выберите пункт выдачи.', 'walls-delivery-calc' ),
		};
		if ( is_object( $errors ) && method_exists( $errors, 'add' ) ) {
			$errors->add( 'wdc_pickup_required', $message );
			return;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function rate_skips_pickup_selection( array $rate ): bool {
		if ( ! empty( $rate['no_pickup_selection'] ) ) {
			return true;
		}

		$meta = $rate['rate_meta'] ?? array();
		if ( is_array( $meta ) && ! empty( $meta['no_pickup_selection'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function selected_rate_requires_pickup_point( array $rate, string $delivery_type ): bool {
		if ( DeliveryType::PICKUP !== $delivery_type ) {
			return false;
		}

		if ( $this->rate_skips_pickup_selection( $rate ) ) {
			return false;
		}

		return $this->rate_flag( $rate, 'requires_pickup_point' );
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function rate_flag( array $rate, string $key ): bool {
		if ( array_key_exists( $key, $rate ) ) {
			return filter_var( $rate[ $key ], FILTER_VALIDATE_BOOLEAN );
		}

		$meta = $rate['rate_meta'] ?? array();
		if ( is_array( $meta ) && array_key_exists( $key, $meta ) ) {
			return filter_var( $meta[ $key ], FILTER_VALIDATE_BOOLEAN );
		}

		return false;
	}

	private function add_city_error( mixed $errors = null ): void {
		if ( is_object( $errors ) && method_exists( $errors, 'add' ) ) {
			$errors->add( 'wdc_city_required', __( 'Введите населенный пункт.', 'walls-delivery-calc' ) );
			return;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Введите населенный пункт.', 'walls-delivery-calc' ), 'error' );
		}
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function validate_courier_address( array $data, mixed $errors = null ): void {
		if ( '' !== $this->checkout_address_1( $data ) ) {
			return;
		}

		$this->add_courier_address_error( $errors );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function checkout_address_1( array $data ): string {
		$use_shipping = '' !== $this->posted_string( $data, 'ship_to_different_address' )
			|| ( ! array_key_exists( 'billing_address_1', $data ) && array_key_exists( 'shipping_address_1', $data ) );
		$field = $use_shipping ? 'shipping_address_1' : 'billing_address_1';

		return $this->posted_string( $data, $field );
	}

	private function add_courier_address_error( mixed $errors = null ): void {
		$message = __( 'Для доставки курьером необходимо заполнить адрес.', 'walls-delivery-calc' );
		if ( is_object( $errors ) && method_exists( $errors, 'add' ) ) {
			$errors->add( 'wdc_courier_address_required', $message );
			return;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function selected_rate( array $data = array() ): array {
		$rates = $this->session_manager->rates();
		foreach ( $this->chosen_shipping_methods( $data ) as $rate_id ) {
			if ( isset( $rates[ $rate_id ] ) ) {
				return $this->with_selected_rate_id( $rates[ $rate_id ], $rate_id );
			}

			if ( str_starts_with( $rate_id, NewShippingMethod::METHOD_ID . ':' ) ) {
				$normalized = substr( $rate_id, strlen( NewShippingMethod::METHOD_ID . ':' ) );
				if ( isset( $rates[ $normalized ] ) ) {
					return $this->with_selected_rate_id( $rates[ $normalized ], $rate_id );
				}
			}

			foreach ( $rates as $rate ) {
				if ( is_array( $rate ) && $this->is_same_supported_pickup_family( $rate_id, (string) ( $rate['rate_id'] ?? '' ) ) ) {
					return $this->with_selected_rate_id( $rate, $rate_id );
				}
			}

			$family = $this->session_manager->shipping_method_family( $rate_id );
			if ( str_ends_with( $family, ':pickup' ) ) {
				foreach ( $rates as $rate ) {
					if ( is_array( $rate ) && $this->rate_pickup_family( $rate, (string) ( $rate['rate_id'] ?? '' ) ) === $family ) {
						return $this->with_selected_rate_id( $rate, $rate_id );
					}
				}
			}
		}

		return array();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function synthetic_pickup_rate( string $selected_rate_id ): array {
		$normalized = $this->session_manager->normalize_rate_id( $selected_rate_id );
		$family = $this->session_manager->shipping_method_family( $normalized );
		if ( ! str_ends_with( $family, ':pickup' ) ) {
			$family = RussianPostDomesticSettings::CARRIER_KEY . ':pickup';
		}
		$carrier = explode( ':', $family )[0] ?? RussianPostDomesticSettings::CARRIER_KEY;
		$rate_id = '' !== $normalized ? $normalized : $family;

		return array(
			'carrier_key' => $carrier,
			'rate_id' => $rate_id,
			'service_key' => $carrier,
			'pickup_family' => $family,
			'delivery_type' => DeliveryType::PICKUP,
			'requires_pickup_point' => true,
			'_selected_rate_id' => $rate_id,
			'_synthetic' => true,
		);
	}

	/**
	 * @param array<string,mixed> $rate
	 * @return array<string,mixed>
	 */
	private function with_selected_rate_id( array $rate, string $selected_rate_id ): array {
		$rate['_selected_rate_id'] = $this->session_manager->normalize_rate_id( $selected_rate_id );

		return $rate;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $rate
	 */
	private function restore_posted_pickup_selection( array $data, array $rate ): bool {
		$point_id   = max( 0, (int) ( $data['wdc_pickup_point_id'] ?? 0 ) );
		$point_code = $this->posted_string( $data, 'wdc_pickup_point_code' );
		if ( $point_id <= 0 && '' === $point_code ) {
			return false;
		}

		$family = $this->rate_pickup_family( $rate, $this->selected_rate_id( $rate ) );
		$is_russian_post_family = RussianPostDomesticSettings::CARRIER_KEY . ':pickup' === $family;
		$is_dpd_family = DpdSettings::CARRIER_KEY . ':pickup' === $family;
		$is_yandex_family = YandexDeliverySettings::CARRIER_KEY . ':pickup' === $family;
		$is_pek_family = PekSettings::PICKUP_FAMILY === $family;
		$selection = array();
		if ( '' !== $point_code ) {
			$selection = $this->selection_from_current_pickup_session( $point_code, $rate );
		}
		if ( $is_pek_family ) {
			return array() !== $selection && $this->session_manager->valid_pickup_selection_for_checkout( $family );
		}
		if ( array() === $selection && $is_russian_post_family ) {
			$selection = $point_id > 0 ? $this->selection_from_pickup_row( $point_id ) : array();
		}
		if ( array() === $selection && $is_russian_post_family && '' !== $point_code ) {
			$selection = $this->selection_from_pickup_code( $point_code );
		}
		if ( $is_dpd_family && '' !== $point_code ) {
			$selection = $this->selection_from_dpd_point_code( $point_code, $rate );
		}
		if ( array() === $selection && $is_yandex_family && '' !== $point_code ) {
			$selection = $this->selection_from_yandex_point_code( $point_code, $rate );
		}
		if ( ( $is_dpd_family || $is_yandex_family ) && array() === $selection ) {
			return false;
		}
		if ( array() === $selection ) {
			$selection = $this->selection_from_posted_fields( $data, $point_id, $point_code, $rate );
		}
		$minimal_restore_used = false;
		if ( array() === $selection ) {
			$carrier = $this->carrier_from_family( $family );
			$minimal_restore_used = true;
			$selection = array(
				'point_id' => $point_id,
				'id' => $point_id > 0 ? (string) $point_id : ( '' !== $point_code ? $carrier . ':' . $point_code : '' ),
				'carrier_key' => $carrier,
				'service_key' => $carrier,
				'pickup_family' => $family,
				'point_code' => $point_code,
				'point_type' => '',
				'point_address' => '',
				'point_postcode' => '',
				'snapshot' => array(
					'id' => $point_id,
					'carrier_key' => $carrier,
					'service_key' => $carrier,
					'pickup_family' => $family,
					'point_code' => $point_code,
				),
			);
		}

		if ( '' === (string) ( $selection['point_code'] ?? '' ) ) {
			$selection['point_code'] = $point_code;
		}

		if ( '' === (string) ( $selection['point_code'] ?? '' ) ) {
			return false;
		}

		$family = $this->rate_pickup_family( $rate, $this->selected_rate_id( $rate ) );
		$carrier = (string) ( $selection['carrier_key'] ?? $selection['carrier'] ?? $rate['carrier_key'] ?? $this->carrier_from_family( $family ) );
		$carrier = $this->session_manager->normalize_carrier_key_for_pickup( $carrier );
		$expected_carrier = $this->carrier_from_family( $family );
		if ( '' !== $carrier && '' !== $expected_carrier && $carrier !== $expected_carrier ) {
			return false;
		}
		$selection['carrier_key'] = '' !== $carrier ? $carrier : $expected_carrier;
		$selection['service_key'] = $this->session_manager->normalize_carrier_key_for_pickup( (string) ( $selection['service_key'] ?? $selection['carrier_key'] ) );
		$selection['pickup_family'] = $family;
		$selection['rate_id'] = $this->selected_rate_id( $rate ) ?: $selection['pickup_family'];
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$operator_id = (string) ( $selection['operator_id'] ?? $snapshot['operator_id'] ?? '' );
		if ( '' !== trim( $operator_id ) ) {
			$selection['operator_id'] = $operator_id;
		}
		$selected_at = trim( (string) ( $selection['selected_at'] ?? $snapshot['selected_at'] ?? '' ) );
		if ( $is_yandex_family && '5post' === strtolower( trim( $operator_id ) ) && '' === $selected_at ) {
			return false;
		}
		$selection['selected_at'] = '' !== $selected_at ? $selected_at : gmdate( 'c' );
		$this->session_manager->save_pickup_selection( $selection );
		$this->session_manager->save_checkout_pickup_point( $this->checkout_pickup_point_from_selection( $selection ) );

		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function selection_from_current_pickup_session( string $point_code, array $rate ): array {
		$family = $this->rate_pickup_family( $rate, $this->selected_rate_id( $rate ) );
		$bucket = $this->session_manager->pickup_selection_for_family( $family );
		if ( array() !== $bucket && $point_code === (string) ( $bucket['point_code'] ?? '' ) ) {
			return $bucket;
		}

		$checkout = $this->session_manager->checkout_pickup_point_for_family( $family );
		if ( array() === $checkout || $point_code !== (string) ( $checkout['point_code'] ?? '' ) || $family !== $this->selection_pickup_family( $checkout, (string) ( $checkout['rate_id'] ?? '' ) ) ) {
			return array();
		}

		$snapshot = is_array( $checkout['snapshot'] ?? null ) ? $checkout['snapshot'] : $checkout;
		$carrier = (string) ( $checkout['carrier_key'] ?? $snapshot['carrier_key'] ?? $this->carrier_from_family( $family ) );

		return array(
			'id' => (string) ( $checkout['id'] ?? $snapshot['id'] ?? ( $carrier . ':' . $point_code ) ),
			'carrier_key' => $carrier,
			'service_key' => (string) ( $checkout['service_key'] ?? $snapshot['service_key'] ?? $carrier ),
			'pickup_family' => $family,
			'operator_id' => (string) ( $checkout['operator_id'] ?? $snapshot['operator_id'] ?? '' ),
			'selected_at' => (string) ( $checkout['selected_at'] ?? $snapshot['selected_at'] ?? '' ),
			'point_code' => $point_code,
			'point_type' => (string) ( $checkout['point_type'] ?? $snapshot['point_type'] ?? '' ),
			'point_type_label' => (string) ( $checkout['point_type_label'] ?? $snapshot['point_type_label'] ?? '' ),
			'point_title' => (string) ( $checkout['point_title'] ?? $snapshot['point_title'] ?? '' ),
			'marker_type' => (string) ( $checkout['marker_type'] ?? $snapshot['marker_type'] ?? '' ),
			'point_name' => (string) ( $checkout['point_name'] ?? $snapshot['point_name'] ?? '' ),
			'point_address' => (string) ( $checkout['point_address'] ?? $checkout['address'] ?? $snapshot['address'] ?? '' ),
			'address' => (string) ( $checkout['address'] ?? $checkout['point_address'] ?? $snapshot['address'] ?? '' ),
			'point_postcode' => (string) ( $checkout['point_postcode'] ?? $checkout['postcode'] ?? $snapshot['postcode'] ?? '' ),
			'postcode' => (string) ( $checkout['postcode'] ?? $checkout['point_postcode'] ?? $snapshot['postcode'] ?? '' ),
			'city_name' => (string) ( $checkout['city_name'] ?? $snapshot['city'] ?? '' ),
			'city' => (string) ( $checkout['city'] ?? $checkout['city_name'] ?? $snapshot['city'] ?? '' ),
			'region_name' => (string) ( $checkout['region_name'] ?? $snapshot['region'] ?? '' ),
			'region' => (string) ( $checkout['region'] ?? $checkout['region_name'] ?? $snapshot['region'] ?? '' ),
			'lat' => $checkout['lat'] ?? $snapshot['lat'] ?? null,
			'lng' => $checkout['lng'] ?? $snapshot['lng'] ?? null,
			'point_work_time' => $this->meaningful_text( $checkout['point_work_time'] ?? $checkout['work_time'] ?? '' ),
			'work_time' => $this->meaningful_text( $checkout['work_time'] ?? $checkout['point_work_time'] ?? '' ),
			'description' => $this->first_meaningful( $checkout['description'] ?? '', $checkout['point_comment'] ?? '', $snapshot['description'] ?? '' ),
			'storage_notice' => $this->first_meaningful( $checkout['storage_notice'] ?? '', $snapshot['storage_notice'] ?? '' ),
			'cdek_code' => (string) ( $checkout['cdek_code'] ?? $snapshot['cdek_code'] ?? '' ),
			'platform_station_id' => (string) ( $checkout['platform_station_id'] ?? $snapshot['platform_station_id'] ?? '' ),
			'snapshot' => $snapshot,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function selection_from_posted_fields( array $data, int $point_id, string $point_code, array $rate ): array {
		if ( '' === $point_code ) {
			return array();
		}
		$family = $this->posted_string( $data, 'wdc_pickup_family' );
		$family = '' !== $family ? $this->session_manager->normalize_pickup_family( $family ) : $this->rate_pickup_family( $rate, $this->selected_rate_id( $rate ) );
		$carrier = $this->posted_string( $data, 'wdc_pickup_carrier_key' );
		$carrier = '' !== $carrier ? $carrier : (string) ( $rate['carrier_key'] ?? $this->carrier_from_family( $family ) );
		$carrier = $this->session_manager->normalize_carrier_key_for_pickup( $carrier );
		$service_key = $this->posted_string( $data, 'wdc_pickup_service_key' );
		$service_key = '' !== $service_key ? $this->session_manager->normalize_carrier_key_for_pickup( $service_key ) : $carrier;
		$point_type = strtoupper( $this->posted_string( $data, 'wdc_pickup_point_type' ) );
		$storage_notice = $this->meaningful_text( $this->posted_string( $data, 'wdc_pickup_storage_notice' ) );
		if ( false && '' === $storage_notice && 'POSTAMAT' === $point_type ) {
			$storage_notice = 'Срок хранения 3 дня';
		}
		$snapshot = array(
			'id' => $carrier . ':' . $point_code,
			'carrier_key' => $carrier,
			'service_key' => $service_key,
			'pickup_family' => $family,
			'point_code' => $point_code,
			'point_type' => $point_type,
			'point_type_label' => $this->posted_string( $data, 'wdc_pickup_point_type_label' ),
			'point_title' => $this->posted_string( $data, 'wdc_pickup_point_title' ),
			'marker_type' => $this->posted_string( $data, 'wdc_pickup_marker_type' ),
			'point_name' => $this->posted_string( $data, 'wdc_pickup_point_name' ),
			'postcode' => $this->posted_string( $data, 'wdc_pickup_point_postcode' ),
			'address' => $this->posted_string( $data, 'wdc_pickup_point_address' ),
			'city' => $this->posted_string( $data, 'wdc_pickup_city_name' ),
			'region' => $this->posted_string( $data, 'wdc_pickup_region_name' ),
			'location_id' => $this->posted_string( $data, 'wdc_pickup_location_id' ),
			'fias_id' => $this->posted_string( $data, 'wdc_pickup_fias_id' ),
			'gar_object_id' => $this->posted_string( $data, 'wdc_pickup_gar_object_id' ),
			'destination_fingerprint' => $this->posted_string( $data, 'wdc_pickup_destination_fingerprint' ),
			'work_time' => $this->meaningful_text( $this->posted_string( $data, 'wdc_pickup_work_time' ) ),
			'point_comment' => $this->meaningful_text( $this->posted_string( $data, 'wdc_pickup_point_comment' ) ),
			'description' => $this->meaningful_text( $this->posted_string( $data, 'wdc_pickup_description' ) ),
			'storage_notice' => $storage_notice,
			'cdek_code' => $this->posted_string( $data, 'wdc_pickup_cdek_code' ) ?: $point_code,
			'platform_station_id' => YandexDeliverySettings::CARRIER_KEY === $carrier ? $point_code : '',
		);

		return array(
			'id' => $point_id > 0 ? (string) $point_id : (string) $snapshot['id'],
			'point_id' => $point_id,
			'carrier_key' => $carrier,
			'service_key' => $service_key,
			'pickup_family' => $family,
			'point_code' => $point_code,
			'point_type' => $point_type,
			'point_type_label' => (string) $snapshot['point_type_label'],
			'point_title' => (string) $snapshot['point_title'],
			'marker_type' => (string) $snapshot['marker_type'],
			'point_name' => (string) $snapshot['point_name'],
			'point_address' => (string) $snapshot['address'],
			'address' => (string) $snapshot['address'],
			'point_postcode' => (string) $snapshot['postcode'],
			'postcode' => (string) $snapshot['postcode'],
			'city_name' => (string) $snapshot['city'],
			'city' => (string) $snapshot['city'],
			'region_name' => (string) $snapshot['region'],
			'region' => (string) $snapshot['region'],
			'location_id' => (string) $snapshot['location_id'],
			'fias_id' => (string) $snapshot['fias_id'],
			'gar_object_id' => (string) $snapshot['gar_object_id'],
			'destination_fingerprint' => (string) $snapshot['destination_fingerprint'],
			'point_work_time' => $this->meaningful_text( $snapshot['work_time'] ),
			'work_time' => $this->meaningful_text( $snapshot['work_time'] ),
			'description' => $this->meaningful_text( $snapshot['description'] ),
			'storage_notice' => $storage_notice,
			'cdek_code' => (string) $snapshot['cdek_code'],
			'platform_station_id' => (string) $snapshot['platform_station_id'],
			'snapshot' => $snapshot,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function selection_from_pickup_row( int $point_id ): array {
		$repository = $this->pickup_repository ?? new RussianPostPickupPointRepository();
		$row = $repository->find_row_by_id( $point_id );
		if ( ! is_array( $row ) || 1 !== (int) ( $row['active'] ?? 0 ) ) {
			return array();
		}

		return $this->selection_from_pickup_row_data( $row );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function selection_from_pickup_code( string $point_code ): array {
		$repository = $this->pickup_repository ?? new RussianPostPickupPointRepository();
		$row = method_exists( $repository, 'find_row_by_point_code' ) ? $repository->find_row_by_point_code( $point_code ) : null;
		if ( ! is_array( $row ) || 1 !== (int) ( $row['active'] ?? 0 ) ) {
			return array();
		}

		return $this->selection_from_pickup_row_data( $row );
	}

	/**
	 * @param array<string,mixed> $rate
	 * @return array<string,mixed>
	 */
	private function selection_from_yandex_point_code( string $point_code, array $rate ): array {
		$repository = $this->yandex_pickup_points;
		if ( ! $repository instanceof YandexDeliveryPickupPointV2Repository ) {
			return array();
		}
		$row = $repository->destination_pickup_point_by_platform_station_id( $point_code );
		if ( ! is_array( $row ) ) {
			return array();
		}
		$point = $this->yandex_formatter->format( $row );
		$family = $this->rate_pickup_family( $rate, $this->selected_rate_id( $rate ) );
		$point['pickup_family'] = $family;
		$point['rate_id'] = $this->selected_rate_id( $rate ) ?: $family;
		$point['platform_station_id'] = (string) ( $point['platform_station_id'] ?? $point['point_code'] ?? '' );
		$point['point_work_time'] = (string) ( $point['work_time'] ?? '' );
		$point['snapshot'] = is_array( $point['snapshot'] ?? null ) ? array_merge( $point['snapshot'], array( 'pickup_family' => $family ) ) : array();

		return $point;
	}
	/**
	 * @param array<string,mixed> $rate
	 * @return array<string,mixed>
	 */
	private function selection_from_dpd_point_code( string $point_code, array $rate ): array {
		$service = $this->dpd_pickup_points;
		if ( ! $service instanceof DpdPickupPointService ) {
			return array();
		}
		$row = $service->get_point_by_terminal_code( $point_code );
		if ( ! is_array( $row ) || '' === (string) ( $row['terminal_code'] ?? '' ) ) {
			return array();
		}

		return $this->selection_from_dpd_row_data( $row, $rate );
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $rate
	 * @return array<string,mixed>
	 */
	private function selection_from_dpd_row_data( array $row, array $rate ): array {
		$type = (string) ( $row['type'] ?? '' );
		$type_label = 'terminal_self_delivery' === $type ? 'Терминал' : 'Пункт выдачи';
		$point_title = 'terminal_self_delivery' === $type ? 'Терминал DPD' : 'Пункт выдачи DPD';
		$marker_type = 'terminal_self_delivery' === $type ? 'terminal' : 'pickup';
		$code = (string) ( $row['terminal_code'] ?? '' );
		$family = $this->rate_pickup_family( $rate, $this->selected_rate_id( $rate ) );
		$snapshot = array(
			'id' => DpdSettings::CARRIER_KEY . ':' . $code,
			'carrier_key' => DpdSettings::CARRIER_KEY,
			'service_key' => DpdSettings::SERVICE_KEY,
			'pickup_family' => $family,
			'point_code' => $code,
			'terminal_code' => $code,
			'point_type' => $type,
			'point_type_label' => $type_label,
			'point_title' => $point_title,
			'display_code' => $code,
			'display_title' => trim( $point_title . ' ' . $code ),
			'marker_type' => $marker_type,
			'point_name' => (string) ( $row['name'] ?? '' ),
			'address' => (string) ( $row['address'] ?? '' ),
			'city' => (string) ( $row['city_name'] ?? '' ),
			'region' => (string) ( $row['region_name'] ?? '' ),
			'lat' => $row['latitude'] ?? null,
			'lng' => $row['longitude'] ?? null,
			'work_time' => (string) ( $row['schedule'] ?? '' ),
			'description' => '',
			'dpd_source' => (string) ( $row['source'] ?? '' ),
		);

		return array(
			'id' => $snapshot['id'],
			'point_id' => $snapshot['id'],
			'carrier_key' => DpdSettings::CARRIER_KEY,
			'service_key' => DpdSettings::SERVICE_KEY,
			'pickup_family' => $family,
			'point_code' => $code,
			'terminal_code' => $code,
			'point_type' => $type,
			'point_type_label' => $type_label,
			'point_title' => $point_title,
			'marker_type' => $marker_type,
			'point_name' => $snapshot['point_name'],
			'point_address' => $snapshot['address'],
			'city_name' => $snapshot['city'],
			'region_name' => $snapshot['region'],
			'point_work_time' => $snapshot['work_time'],
			'dpd_source' => $snapshot['dpd_source'],
			'lat' => $snapshot['lat'],
			'lng' => $snapshot['lng'],
			'snapshot' => $snapshot,
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function selection_from_pickup_row_data( array $row ): array {

		$snapshot = array(
			'id' => (int) ( $row['id'] ?? 0 ),
			'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
			'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
			'pickup_family' => RussianPostDomesticSettings::CARRIER_KEY . ':pickup',
			'point_code' => (string) ( $row['point_code'] ?? '' ),
			'point_type' => (string) ( $row['point_type'] ?? '' ),
			'postcode' => (string) ( $row['postcode'] ?? '' ),
			'address' => (string) ( $row['address'] ?? '' ),
			'city' => (string) ( $row['city_name'] ?? '' ),
			'region' => (string) ( $row['region_name'] ?? '' ),
			'fias_location_guid' => (string) ( $row['fias_location_guid'] ?? '' ),
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			'work_time' => (string) ( $row['work_time'] ?? '' ),
			'description' => (string) ( $row['description'] ?? '' ),
		);

		return array(
			'point_id' => $snapshot['id'],
			'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
			'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
			'pickup_family' => RussianPostDomesticSettings::CARRIER_KEY . ':pickup',
			'point_code' => $snapshot['point_code'],
			'point_type' => $snapshot['point_type'],
			'point_address' => $snapshot['address'],
			'point_postcode' => $snapshot['postcode'],
			'point_comment' => $snapshot['description'],
			'point_work_time' => $snapshot['work_time'],
			'lat' => $snapshot['lat'],
			'lng' => $snapshot['lng'],
			'snapshot' => $snapshot,
		);
	}

	/**
	 * @param array<string,mixed> $selection
	 * @return array<string,mixed>
	 */
	private function checkout_pickup_point_from_selection( array $selection ): array {
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();

		return array(
			'id' => $selection['point_id'] ?? $selection['id'] ?? $snapshot['id'] ?? 0,
			'carrier_key' => $selection['carrier_key'] ?? $snapshot['carrier_key'] ?? '',
			'service_key' => $selection['service_key'] ?? $snapshot['service_key'] ?? '',
			'pickup_family' => $selection['pickup_family'] ?? $snapshot['pickup_family'] ?? '',
			'point_code' => $selection['point_code'] ?? $snapshot['point_code'] ?? '',
			'platform_station_id' => $selection['platform_station_id'] ?? $snapshot['platform_station_id'] ?? '',
			'operator_id' => $selection['operator_id'] ?? $snapshot['operator_id'] ?? '',
			'selected_at' => $selection['selected_at'] ?? $snapshot['selected_at'] ?? '',
			'terminal_code' => $selection['terminal_code'] ?? $snapshot['terminal_code'] ?? '',
			'point_type' => $selection['point_type'] ?? $snapshot['point_type'] ?? '',
			'point_type_label' => $selection['point_type_label'] ?? $snapshot['point_type_label'] ?? '',
			'point_title' => $selection['point_title'] ?? $snapshot['point_title'] ?? '',
			'marker_type' => $selection['marker_type'] ?? $snapshot['marker_type'] ?? '',
			'postcode' => $selection['point_postcode'] ?? $snapshot['postcode'] ?? '',
			'address' => $selection['point_address'] ?? $snapshot['address'] ?? '',
			'lat' => $selection['lat'] ?? $snapshot['lat'] ?? null,
			'lng' => $selection['lng'] ?? $snapshot['lng'] ?? null,
			'point_name' => $selection['point_name'] ?? $snapshot['point_name'] ?? '',
			'point_address' => $selection['point_address'] ?? $snapshot['address'] ?? '',
			'point_postcode' => $selection['point_postcode'] ?? $snapshot['postcode'] ?? '',
			'city_name' => $selection['city_name'] ?? $snapshot['city'] ?? '',
			'region_name' => $selection['region_name'] ?? $snapshot['region'] ?? '',
			'location_id' => $selection['location_id'] ?? $snapshot['location_id'] ?? '',
			'fias_id' => $selection['fias_id'] ?? $snapshot['fias_id'] ?? $snapshot['city_fias_id'] ?? $snapshot['fias_location_guid'] ?? '',
			'gar_object_id' => $selection['gar_object_id'] ?? $snapshot['gar_object_id'] ?? $snapshot['gar_id'] ?? '',
			'destination_fingerprint' => $selection['destination_fingerprint'] ?? $snapshot['destination_fingerprint'] ?? '',
			'work_time' => $this->meaningful_text( $selection['point_work_time'] ?? $selection['work_time'] ?? '' ),
			'point_work_time' => $this->meaningful_text( $selection['point_work_time'] ?? $selection['work_time'] ?? '' ),
			'description' => $this->first_meaningful( $selection['description'] ?? '', $selection['point_comment'] ?? '', $snapshot['description'] ?? '' ),
			'storage_notice' => $this->first_meaningful( $selection['storage_notice'] ?? '', $snapshot['storage_notice'] ?? '' ),
			'cdek_code' => $selection['cdek_code'] ?? $snapshot['cdek_code'] ?? '',
			'dpd_source' => $selection['dpd_source'] ?? $snapshot['dpd_source'] ?? '',
			'snapshot' => array() !== $snapshot ? $snapshot : array(
				'id' => $selection['point_id'] ?? 0,
				'carrier_key' => $selection['carrier_key'] ?? '',
				'service_key' => $selection['service_key'] ?? '',
				'pickup_family' => $selection['pickup_family'] ?? '',
				'point_code' => $selection['point_code'] ?? '',
			),
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function posted_string( array $data, string $key ): string {
		$value = $data[ $key ] ?? '';
		if ( is_array( $value ) ) {
			return '';
		}

		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) );
	}

	private function meaningful_text( mixed $value ): string {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		$text = trim( (string) $value );
		if ( '' === $text ) {
			return '';
		}
		$normalized = str_replace( ',', '.', $text );
		if ( is_numeric( $normalized ) && 0.0 === (float) $normalized ) {
			return '';
		}

		return $text;
	}

	private function first_meaningful( mixed ...$values ): string {
		foreach ( $values as $value ) {
			$text = $this->meaningful_text( $value );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function rate_pickup_family( array $rate, string $fallback_rate_id = '' ): string {
		$explicit = (string) ( $rate['pickup_family'] ?? '' );
		if ( '' !== $explicit ) {
			return $this->session_manager->normalize_pickup_family( $explicit );
		}

		$rate_id = (string) ( $rate['_selected_rate_id'] ?? $rate['rate_id'] ?? $fallback_rate_id );
		$family = $this->session_manager->shipping_method_family( $rate_id );
		if ( str_ends_with( $family, ':pickup' ) ) {
			return $family;
		}

		$carrier = $this->session_manager->normalize_carrier_key_for_pickup( (string) ( $rate['carrier_key'] ?? $rate['service_key'] ?? '' ) );
		return '' !== $carrier ? $carrier . ':pickup' : $family;
	}

	/**
	 * @param array<string,mixed> $selection
	 */
	private function selection_pickup_family( array $selection, string $fallback_rate_id = '' ): string {
		$explicit = (string) ( $selection['pickup_family'] ?? '' );
		if ( '' !== $explicit ) {
			return $this->session_manager->normalize_pickup_family( $explicit );
		}

		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$snapshot_family = (string) ( $snapshot['pickup_family'] ?? '' );
		if ( '' !== $snapshot_family ) {
			return $this->session_manager->normalize_pickup_family( $snapshot_family );
		}

		$rate_id = (string) ( $selection['rate_id'] ?? $fallback_rate_id );
		$family = $this->session_manager->shipping_method_family( $rate_id );
		if ( str_ends_with( $family, ':pickup' ) ) {
			return $family;
		}

		$carrier = $this->session_manager->normalize_carrier_key_for_pickup( (string) ( $selection['carrier_key'] ?? $selection['carrier'] ?? $snapshot['carrier_key'] ?? '' ) );
		return '' !== $carrier ? $carrier . ':pickup' : $family;
	}

	private function carrier_from_family( string $family ): string {
		$parts = explode( ':', $family );
		$carrier = $this->session_manager->normalize_carrier_key_for_pickup( (string) ( $parts[0] ?? '' ) );
		return '' !== $carrier ? $carrier : RussianPostDomesticSettings::CARRIER_KEY;
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function selected_rate_id( array $rate ): string {
		return $this->session_manager->normalize_rate_id( (string) ( $rate['_selected_rate_id'] ?? $rate['rate_id'] ?? '' ) );
	}

	private function is_yandex_pickup_rate( string $rate_id ): bool {
		return YandexDeliverySettings::CARRIER_KEY . ':pickup' === $this->session_manager->shipping_method_family( $rate_id );
	}

	private function is_supported_pickup_family( string $rate_id ): bool {
		return str_ends_with( $this->session_manager->shipping_method_family( $rate_id ), ':pickup' );
	}

	private function is_same_supported_pickup_family( string $old_rate_id, string $new_rate_id ): bool {
		$old_family = $this->session_manager->shipping_method_family( $old_rate_id );
		$new_family = $this->session_manager->shipping_method_family( $new_rate_id );

		return str_ends_with( $old_family, ':pickup' ) && $old_family === $new_family;
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function is_courier_rate( array $rate ): bool {
		return CourierRateSupport::is_courier_meta( $rate )
			|| DeliveryType::COURIER === (string) ( $rate['delivery_type'] ?? '' )
			|| ! empty( $rate['requires_courier_address'] );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function validation_failure_context( string $reason, array $data, string $chosen_rate_id, string $selected_rate_id ): array {
		return array(
			'reason' => $reason,
			'chosen_rate_id' => $chosen_rate_id,
			'chosen_rate_family' => $this->session_manager->shipping_method_family( $chosen_rate_id ),
			'selected_rate_id' => $selected_rate_id,
			'session_has_pickup' => $this->session_manager->has_valid_pickup_selection(),
			'posted_point_id_present' => max( 0, (int) ( $data['wdc_pickup_point_id'] ?? 0 ) ) > 0,
			'posted_point_code_present' => '' !== $this->posted_string( $data, 'wdc_pickup_point_code' ),
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function chosen_shipping_methods( array $data = array() ): array {
		$posted = $data['shipping_method'] ?? array();
		if ( is_array( $posted ) ) {
			$posted = array_filter( array_map( 'strval', $posted ), static fn( string $value ): bool => '' !== trim( $value ) );
			if ( array() !== $posted ) {
				return array_values( $posted );
			}
		} elseif ( is_scalar( $posted ) && '' !== trim( (string) $posted ) ) {
			return array( (string) $posted );
		}

		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->session ) && is_object( WC()->session ) && method_exists( WC()->session, 'get' ) ) {
			$chosen = WC()->session->get( 'chosen_shipping_methods', array() );

			return is_array( $chosen ) ? array_map( 'strval', $chosen ) : array();
		}

		return array();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function posted_checkout_data(): array {
		if ( ! isset( $_POST ) || ! is_array( $_POST ) ) {
			return array();
		}

		$data = array();
		foreach ( $_POST as $key => $value ) {
			$data[ (string) $key ] = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
		}

		return $data;
	}

	/**
	 * @param array<int,string> $chosen_methods
	 */
	private function first_chosen_shipping_method( array $chosen_methods ): string {
		foreach ( $chosen_methods as $method ) {
			$method = trim( (string) $method );
			if ( '' !== $method ) {
				return $method;
			}
		}

		return '';
	}
}

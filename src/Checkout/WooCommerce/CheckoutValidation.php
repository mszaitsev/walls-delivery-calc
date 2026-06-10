<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\Runtime\CdekCarrier;
use WallsShop\WDC\Checkout\Validation\CheckoutAddressValidation;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;

defined( 'ABSPATH' ) || exit;

final class CheckoutValidation {
	public function __construct(
		private CheckoutSessionManager $session_manager,
		private ?CheckoutAddressValidation $address_validation = null,
		private ?RussianPostPickupPointRepository $pickup_repository = null
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_process', array( $this, 'preload_from_post' ), 5, 0 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 20, 2 );
		$this->debug_validation( 'wdc_checkout_validation_registered' );
	}

	public function preload_from_post(): void {
		$data = $this->posted_checkout_data();
		$chosen_methods = $this->chosen_shipping_methods( $data );
		$chosen_rate_id = $this->first_chosen_shipping_method( $chosen_methods );
		$this->debug_validation(
			'wdc_pickup_preload_from_post_start',
			array(
				'chosen_rate_id' => $chosen_rate_id,
				'chosen_rate_family' => $this->session_manager->shipping_method_family( $chosen_rate_id ),
				'posted_point_id_present' => max( 0, (int) ( $data['wdc_pickup_point_id'] ?? 0 ) ) > 0,
				'posted_point_code_present' => '' !== $this->posted_string( $data, 'wdc_pickup_point_code' ),
				'session_has_pickup' => $this->session_manager->has_valid_pickup_selection(),
			)
		);

		if ( ! $this->is_supported_pickup_family( $chosen_rate_id ) ) {
			$this->debug_validation( 'wdc_pickup_preload_from_post_skipped', array( 'reason' => 'not_pickup_family', 'chosen_rate_id' => $chosen_rate_id ) );
			return;
		}

		$point_id = max( 0, (int) ( $data['wdc_pickup_point_id'] ?? 0 ) );
		$point_code = $this->posted_string( $data, 'wdc_pickup_point_code' );
		if ( $point_id <= 0 && '' === $point_code ) {
			$this->debug_validation( 'wdc_pickup_preload_from_post_skipped', array( 'reason' => 'posted_point_missing' ) );
			return;
		}

		$restored = $this->restore_posted_pickup_selection( $data, $this->synthetic_pickup_rate( $chosen_rate_id ) );
		$this->debug_validation(
			$restored ? 'wdc_pickup_preload_from_post_success' : 'wdc_pickup_preload_from_post_skipped',
			array(
				'reason' => $restored ? 'restored_from_post' : 'restore_failed',
				'chosen_rate_id' => $chosen_rate_id,
				'chosen_rate_family' => $this->session_manager->shipping_method_family( $chosen_rate_id ),
				'session_has_pickup' => $this->session_manager->has_valid_pickup_selection(),
			)
		);
	}

	public function validate( mixed $data = array(), mixed $errors = null ): void {
		$data = is_array( $data ) ? $data : array();
		$chosen_methods = $this->chosen_shipping_methods( $data );
		$chosen_rate_id = $this->first_chosen_shipping_method( $chosen_methods );
		$chosen_rate_id_normalized = $this->session_manager->normalize_rate_id( $chosen_rate_id );
		$rate = $this->selected_rate( $data );
		$selected_rate_found = array() !== $rate;
		$synthetic_rate_created = false;
		if ( array() === $rate && $this->is_supported_pickup_family( $chosen_rate_id ) ) {
			$rate = $this->synthetic_pickup_rate( $chosen_rate_id );
			$synthetic_rate_created = true;
		}
		$this->debug_validation(
			'wdc_checkout_validation_start',
			array(
				'chosen_rate_id' => $chosen_rate_id,
				'chosen_rate_family' => $this->session_manager->shipping_method_family( $chosen_rate_id_normalized ),
				'posted_point_id_present' => max( 0, (int) ( $data['wdc_pickup_point_id'] ?? 0 ) ) > 0,
				'posted_point_code_present' => '' !== $this->posted_string( $data, 'wdc_pickup_point_code' ),
				'selected_rate_found' => $selected_rate_found,
				'synthetic_rate_created' => $synthetic_rate_created,
				'session_has_pickup' => $this->session_manager->has_valid_pickup_selection(),
			)
		);
		if ( array() === $rate ) {
			$this->debug_validation( 'wdc_pickup_validation_failed', $this->validation_failure_context( 'selected_rate_missing', $data, $chosen_rate_id, '' ) );
			return;
		}

		$delivery_type = (string) ( $rate['delivery_type'] ?? '' );
		$this->validate_city( $delivery_type, $data, $errors );
		if ( $this->is_courier_rate( $rate ) ) {
			$this->validate_courier_address( $data, $errors );
			return;
		}

		if ( DeliveryType::PICKUP !== $delivery_type ) {
			return;
		}

		if ( $this->rate_skips_pickup_selection( $rate ) ) {
			return;
		}

		$selected_rate_id = $this->selected_rate_id( $rate );
		$matches_before_restore = $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), $selected_rate_id );
		if ( $matches_before_restore ) {
			$this->session_manager->update_pickup_selection_rate_id( $selected_rate_id );
			$this->debug_validation( 'wdc_pickup_validation_passed', array( 'reason' => 'session_match', 'selected_rate_id' => $selected_rate_id ) );
			return;
		}
		$restored = $this->restore_posted_pickup_selection( $data, $rate );
		if ( $restored ) {
			$this->session_manager->update_pickup_selection_rate_id( $selected_rate_id );
			$this->debug_validation(
				'wdc_pickup_restore_from_post_success',
				array(
					'pass_reason' => 'restored_from_post',
					'selected_rate_id' => $selected_rate_id,
					'session_has_pickup' => $this->session_manager->has_valid_pickup_selection(),
				)
			);
			return;
		}

		$matches_after_restore = $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), $selected_rate_id );
		$this->debug_validation(
			'wdc_pickup_validation_failed',
			array_merge(
				$this->validation_failure_context( 'no_session_no_post_point', $data, $chosen_rate_id, $selected_rate_id ),
				array(
					'restore_result' => $restored,
					'matches_after_restore' => $matches_after_restore,
				)
			)
		);
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
		$message = RussianPostDomesticSettings::CARRIER_KEY === (string) ( $rate['carrier_key'] ?? '' )
			? __( 'Выберите пункт выдачи Почты России.', 'walls-delivery-calc' )
			: __( 'Выберите пункт выдачи.', 'walls-delivery-calc' );
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

			if ( $this->session_manager->is_russian_post_pickup_family( $rate_id ) ) {
				foreach ( $rates as $rate ) {
					if ( is_array( $rate ) && $this->is_russian_post_pickup_rate( $rate ) ) {
						return $this->with_selected_rate_id( $rate, $rate_id );
					}
				}
			}
			if ( $this->session_manager->is_cdek_pickup_family( $rate_id ) ) {
				foreach ( $rates as $rate ) {
					if ( is_array( $rate ) && $this->is_cdek_pickup_rate( $rate ) ) {
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
	private function synthetic_russian_post_pickup_rate( string $selected_rate_id ): array {
		$normalized = $this->session_manager->normalize_rate_id( $selected_rate_id );
		$rate_id = '' !== $normalized ? $normalized : RussianPostDomesticSettings::checkout_group_id( DeliveryType::PICKUP );

		return array(
			'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
			'rate_id' => $rate_id,
			'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
			'delivery_type' => DeliveryType::PICKUP,
			'_selected_rate_id' => $rate_id,
			'_synthetic' => true,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function synthetic_cdek_pickup_rate( string $selected_rate_id ): array {
		$normalized = $this->session_manager->normalize_rate_id( $selected_rate_id );
		$rate_id = '' !== $normalized ? $normalized : CdekCarrier::checkout_group_id( DeliveryType::PICKUP );

		return array(
			'carrier_key' => CdekCarrier::KEY,
			'rate_id' => $rate_id,
			'service_key' => CdekCarrier::KEY,
			'delivery_type' => DeliveryType::PICKUP,
			'_selected_rate_id' => $rate_id,
			'_synthetic' => true,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function synthetic_pickup_rate( string $selected_rate_id ): array {
		return $this->session_manager->is_cdek_pickup_family( $selected_rate_id )
			? $this->synthetic_cdek_pickup_rate( $selected_rate_id )
			: $this->synthetic_russian_post_pickup_rate( $selected_rate_id );
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
		$this->debug_validation(
			'wdc_pickup_restore_from_post_attempt',
			array(
				'posted_point_id_present' => $point_id > 0,
				'posted_point_code_present' => '' !== $point_code,
				'selected_rate_id' => $this->selected_rate_id( $rate ),
			)
		);
		if ( $point_id <= 0 && '' === $point_code ) {
			$this->debug_validation( 'wdc_pickup_restore_from_post_failed', array( 'reason' => 'posted_point_missing' ) );
			return false;
		}

		$is_cdek_rate = $this->is_cdek_pickup_rate( $rate );
		$selection = array();
		if ( $is_cdek_rate && '' !== $point_code ) {
			$selection = $this->selection_from_current_cdek_session( $point_code );
		}
		if ( array() === $selection && ! $is_cdek_rate ) {
			$selection = $point_id > 0 ? $this->selection_from_pickup_row( $point_id ) : array();
		}
		$this->debug_validation(
			'wdc_pickup_restore_lookup_by_id',
			array(
				'posted_point_id_present' => $point_id > 0,
				'success' => array() !== $selection,
			)
		);
		if ( array() === $selection && ! $is_cdek_rate && '' !== $point_code ) {
			$selection = $this->selection_from_pickup_code( $point_code );
			$this->debug_validation(
				'wdc_pickup_restore_lookup_by_code',
				array(
					'posted_point_code_present' => '' !== $point_code,
					'success' => array() !== $selection,
				)
			);
		}
		$minimal_restore_used = false;
		if ( array() === $selection ) {
			$minimal_restore_used = true;
			$selection = array(
				'point_id' => $point_id,
				'id' => $point_id > 0 ? (string) $point_id : ( ( $this->is_cdek_pickup_rate( $rate ) && '' !== $point_code ) ? 'cdek:' . $point_code : '' ),
				'point_code' => $point_code,
				'point_type' => '',
				'point_address' => '',
				'point_postcode' => '',
				'snapshot' => array(
					'id' => $point_id,
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

		$selection['carrier_key'] = (string) ( $rate['carrier_key'] ?? ( $this->is_cdek_pickup_rate( $rate ) ? CdekCarrier::KEY : RussianPostDomesticSettings::CARRIER_KEY ) );
		$selection['rate_id'] = $this->selected_rate_id( $rate ) ?: ( CdekCarrier::KEY === $selection['carrier_key'] ? CdekCarrier::checkout_group_id( DeliveryType::PICKUP ) : RussianPostDomesticSettings::checkout_group_id( DeliveryType::PICKUP ) );
		$selection['selected_at'] = gmdate( 'c' );
		$this->session_manager->save_pickup_selection( $selection );
		$this->session_manager->save_checkout_pickup_point( $this->checkout_pickup_point_from_selection( $selection ) );
		$this->debug_validation(
			'wdc_pickup_restore_from_post_saved',
			array(
				'pass_reason' => $minimal_restore_used ? 'restored_minimal' : 'restored_from_post',
				'minimal_restore_used' => $minimal_restore_used,
				'selected_rate_id' => (string) $selection['rate_id'],
				'session_has_pickup' => $this->session_manager->has_valid_pickup_selection(),
			)
		);

		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function selection_from_current_cdek_session( string $point_code ): array {
		$current = $this->session_manager->pickup_selection();
		if ( array() !== $current && CdekCarrier::KEY === (string) ( $current['carrier_key'] ?? '' ) && $point_code === (string) ( $current['point_code'] ?? '' ) ) {
			return $current;
		}

		$checkout = $this->session_manager->checkout_pickup_point();
		if ( array() === $checkout || $point_code !== (string) ( $checkout['point_code'] ?? '' ) ) {
			return array();
		}

		$snapshot = is_array( $checkout['snapshot'] ?? null ) ? $checkout['snapshot'] : $checkout;

		return array(
			'id' => (string) ( $checkout['id'] ?? $snapshot['id'] ?? ( 'cdek:' . $point_code ) ),
			'carrier_key' => CdekCarrier::KEY,
			'point_code' => $point_code,
			'point_type' => (string) ( $checkout['point_type'] ?? $snapshot['point_type'] ?? '' ),
			'point_name' => (string) ( $checkout['point_name'] ?? $snapshot['point_name'] ?? '' ),
			'point_address' => (string) ( $checkout['point_address'] ?? $checkout['address'] ?? $snapshot['address'] ?? '' ),
			'point_postcode' => (string) ( $checkout['point_postcode'] ?? $checkout['postcode'] ?? $snapshot['postcode'] ?? '' ),
			'city_name' => (string) ( $checkout['city_name'] ?? $snapshot['city'] ?? '' ),
			'region_name' => (string) ( $checkout['region_name'] ?? $snapshot['region'] ?? '' ),
			'lat' => $checkout['lat'] ?? $snapshot['lat'] ?? null,
			'lng' => $checkout['lng'] ?? $snapshot['lng'] ?? null,
			'point_work_time' => (string) ( $checkout['work_time'] ?? $snapshot['work_time'] ?? '' ),
			'description' => (string) ( $checkout['description'] ?? $snapshot['description'] ?? '' ),
			'storage_notice' => (string) ( $checkout['storage_notice'] ?? $snapshot['storage_notice'] ?? '' ),
			'cdek_code' => (string) ( $checkout['cdek_code'] ?? $snapshot['cdek_code'] ?? $point_code ),
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
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function selection_from_pickup_row_data( array $row ): array {

		$snapshot = array(
			'id' => (int) ( $row['id'] ?? 0 ),
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
			'point_code' => $selection['point_code'] ?? $snapshot['point_code'] ?? '',
			'point_type' => $selection['point_type'] ?? $snapshot['point_type'] ?? '',
			'postcode' => $selection['point_postcode'] ?? $snapshot['postcode'] ?? '',
			'address' => $selection['point_address'] ?? $snapshot['address'] ?? '',
			'lat' => $selection['lat'] ?? $snapshot['lat'] ?? null,
			'lng' => $selection['lng'] ?? $snapshot['lng'] ?? null,
			'point_name' => $selection['point_name'] ?? $snapshot['point_name'] ?? '',
			'point_address' => $selection['point_address'] ?? $snapshot['address'] ?? '',
			'point_postcode' => $selection['point_postcode'] ?? $snapshot['postcode'] ?? '',
			'city_name' => $selection['city_name'] ?? $snapshot['city'] ?? '',
			'region_name' => $selection['region_name'] ?? $snapshot['region'] ?? '',
			'work_time' => $selection['point_work_time'] ?? $snapshot['work_time'] ?? '',
			'description' => $selection['description'] ?? $selection['point_comment'] ?? $snapshot['description'] ?? '',
			'storage_notice' => $selection['storage_notice'] ?? $snapshot['storage_notice'] ?? '',
			'cdek_code' => $selection['cdek_code'] ?? $snapshot['cdek_code'] ?? '',
			'carrier_key' => $selection['carrier_key'] ?? $snapshot['carrier_key'] ?? '',
			'snapshot' => array() !== $snapshot ? $snapshot : array(
				'id' => $selection['point_id'] ?? 0,
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

	/**
	 * @param array<string,mixed> $rate
	 */
	private function selected_rate_id( array $rate ): string {
		return $this->session_manager->normalize_rate_id( (string) ( $rate['_selected_rate_id'] ?? $rate['rate_id'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function is_russian_post_pickup_rate( array $rate ): bool {
		return RussianPostDomesticSettings::CARRIER_KEY === (string) ( $rate['carrier_key'] ?? '' )
			&& RussianPostDomesticSettings::SERVICE_KEY === (string) ( $rate['service_key'] ?? '' )
			&& DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' )
			&& $this->session_manager->is_russian_post_pickup_family( (string) ( $rate['rate_id'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function is_cdek_pickup_rate( array $rate ): bool {
		return CdekCarrier::KEY === (string) ( $rate['carrier_key'] ?? '' )
			&& DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' )
			&& $this->session_manager->is_cdek_pickup_family( (string) ( $rate['rate_id'] ?? $rate['_selected_rate_id'] ?? '' ) );
	}

	private function is_supported_pickup_family( string $rate_id ): bool {
		return $this->session_manager->is_russian_post_pickup_family( $rate_id ) || $this->session_manager->is_cdek_pickup_family( $rate_id );
	}

	private function is_same_supported_pickup_family( string $old_rate_id, string $new_rate_id ): bool {
		return $this->session_manager->is_same_pickup_family( $old_rate_id, $new_rate_id ) || $this->session_manager->is_same_cdek_pickup_family( $old_rate_id, $new_rate_id );
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
	 * @param array<string,mixed> $context
	 */
	private function debug_validation( string $message, array $context = array() ): void {
		if ( ! $this->debug_logging_enabled() ) {
			return;
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $message, array_merge( $context, array( 'source' => 'walls-delivery-calc' ) ) );
			return;
		}

		if ( function_exists( 'error_log' ) ) {
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $context ) : json_encode( $context );
			error_log( '[walls-delivery-calc] debug: ' . $message . ' ' . ( false !== $encoded ? $encoded : '' ) );
		}
	}

	private function debug_logging_enabled(): bool {
		if ( defined( 'WDC_PICKUP_DEBUG' ) && WDC_PICKUP_DEBUG ) {
			return true;
		}

		return defined( 'WP_DEBUG' ) && WP_DEBUG;
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

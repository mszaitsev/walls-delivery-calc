<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
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
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 20, 2 );
	}

	public function validate( mixed $data = array(), mixed $errors = null ): void {
		$data = is_array( $data ) ? $data : array();
		$rate = $this->selected_rate();
		if ( array() === $rate ) {
			return;
		}

		$delivery_type = (string) ( $rate['delivery_type'] ?? '' );
		$this->validate_city( $delivery_type, $data, $errors );

		if ( DeliveryType::PICKUP !== $delivery_type ) {
			return;
		}

		if ( $this->rate_skips_pickup_selection( $rate ) ) {
			return;
		}

		$selected_rate_id = $this->selected_rate_id( $rate );
		if ( $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), $selected_rate_id ) ) {
			$this->session_manager->update_pickup_selection_rate_id( $selected_rate_id );
			return;
		}

		if ( $this->restore_posted_pickup_selection( $data, $rate )
			&& $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), $selected_rate_id )
		) {
			$this->session_manager->update_pickup_selection_rate_id( $selected_rate_id );
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
		$message = RussianPostDomesticSettings::PICKUP_SERVICE_KEY === (string) ( $rate['service_key'] ?? '' )
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
	 * @return array<string,mixed>
	 */
	private function selected_rate(): array {
		$rates = $this->session_manager->rates();
		foreach ( $this->chosen_shipping_methods() as $rate_id ) {
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
				if ( is_array( $rate ) && $this->session_manager->is_same_pickup_family( $rate_id, (string) ( $rate['rate_id'] ?? '' ) ) ) {
					return $this->with_selected_rate_id( $rate, $rate_id );
				}
			}
		}

		return array();
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
		if ( RussianPostDomesticSettings::CARRIER_KEY !== (string) ( $rate['carrier_key'] ?? '' )
			|| ! $this->session_manager->is_russian_post_pickup_family( $this->selected_rate_id( $rate ) )
		) {
			return false;
		}

		$point_id   = max( 0, (int) ( $data['wdc_pickup_point_id'] ?? 0 ) );
		$point_code = $this->posted_string( $data, 'wdc_pickup_point_code' );
		if ( $point_id <= 0 && '' === $point_code ) {
			return false;
		}

		$selection = $point_id > 0 ? $this->selection_from_pickup_row( $point_id ) : array();
		if ( array() === $selection ) {
			$selection = array(
				'point_id' => $point_id,
				'point_code' => $point_code,
			);
		}

		if ( '' === (string) ( $selection['point_code'] ?? '' ) ) {
			$selection['point_code'] = $point_code;
		}

		if ( '' === (string) ( $selection['point_code'] ?? '' ) ) {
			return false;
		}

		$selection['carrier_key'] = RussianPostDomesticSettings::CARRIER_KEY;
		$selection['rate_id'] = $this->selected_rate_id( $rate );
		$selection['selected_at'] = gmdate( 'c' );
		$this->session_manager->save_pickup_selection( $selection );
		if ( array() !== ( $selection['snapshot'] ?? array() ) ) {
			$this->session_manager->save_checkout_pickup_point(
				array(
					'id' => $selection['point_id'] ?? 0,
					'point_code' => $selection['point_code'] ?? '',
					'point_type' => $selection['point_type'] ?? '',
					'postcode' => $selection['point_postcode'] ?? '',
					'address' => $selection['point_address'] ?? '',
					'lat' => $selection['lat'] ?? null,
					'lng' => $selection['lng'] ?? null,
					'snapshot' => $selection['snapshot'],
				)
			);
		}

		return true;
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
	 * @return array<int,string>
	 */
	private function chosen_shipping_methods(): array {
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->session ) && is_object( WC()->session ) && method_exists( WC()->session, 'get' ) ) {
			$chosen = WC()->session->get( 'chosen_shipping_methods', array() );

			return is_array( $chosen ) ? array_map( 'strval', $chosen ) : array();
		}

		return array();
	}
}

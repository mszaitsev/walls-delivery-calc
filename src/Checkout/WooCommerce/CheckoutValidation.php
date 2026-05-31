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
		$rate = $this->selected_rate( $data );
		$this->debug_validation(
			'wdc_checkout_validation_start',
			array(
				'chosen_shipping_methods' => $this->chosen_shipping_methods( $data ),
				'posted_point_id' => max( 0, (int) ( $data['wdc_pickup_point_id'] ?? 0 ) ),
				'posted_point_code' => $this->posted_string( $data, 'wdc_pickup_point_code' ),
				'selected_rate' => $this->rate_debug_context( $rate ),
				'session_pickup_selection' => $this->session_manager->pickup_selection(),
			)
		);
		if ( array() === $rate ) {
			$this->debug_validation( 'wdc_pickup_validation_failed', array( 'reason' => 'selected_rate_missing' ) );
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
		$matches_before_restore = $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), $selected_rate_id );
		if ( $matches_before_restore ) {
			$this->session_manager->update_pickup_selection_rate_id( $selected_rate_id );
			return;
		}
		$this->debug_validation(
			'wdc_pickup_validation_session_match_failed',
			array(
				'selected_rate_id' => $selected_rate_id,
				'carrier_key' => (string) ( $rate['carrier_key'] ?? '' ),
				'session_pickup_selection' => $this->session_manager->pickup_selection(),
			)
		);

		$restored = $this->restore_posted_pickup_selection( $data, $rate );
		$matches_after_restore = $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), $selected_rate_id );
		if ( $restored && $matches_after_restore ) {
			$this->session_manager->update_pickup_selection_rate_id( $selected_rate_id );
			$this->debug_validation( 'wdc_pickup_restore_from_post_success', array( 'selected_rate_id' => $selected_rate_id, 'session_pickup_selection' => $this->session_manager->pickup_selection() ) );
			return;
		}

		$this->debug_validation(
			'wdc_pickup_validation_failed',
			array(
				'reason' => $restored ? 'restored_selection_did_not_match' : 'no_session_or_posted_pickup',
				'restore_result' => $restored,
				'matches_after_restore' => $matches_after_restore,
				'session_pickup_selection' => $this->session_manager->pickup_selection(),
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
				if ( is_array( $rate ) && $this->session_manager->is_same_pickup_family( $rate_id, (string) ( $rate['rate_id'] ?? '' ) ) ) {
					return $this->with_selected_rate_id( $rate, $rate_id );
				}
			}

			if ( $this->session_manager->is_russian_post_pickup_family( $rate_id ) ) {
				foreach ( $rates as $rate ) {
					if ( is_array( $rate ) && $this->is_russian_post_pickup_rate( $rate ) ) {
						return $this->with_selected_rate_id( $rate, $rate_id );
					}
				}
				return $this->with_selected_rate_id(
					array(
						'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
						'rate_id' => RussianPostDomesticSettings::PICKUP_SERVICE_KEY,
						'service_key' => RussianPostDomesticSettings::PICKUP_SERVICE_KEY,
						'delivery_type' => DeliveryType::PICKUP,
					),
					$rate_id
				);
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
		$this->debug_validation(
			'wdc_pickup_restore_from_post_attempt',
			array(
				'posted_point_id' => max( 0, (int) ( $data['wdc_pickup_point_id'] ?? 0 ) ),
				'posted_point_code' => $this->posted_string( $data, 'wdc_pickup_point_code' ),
				'selected_rate_id' => $this->selected_rate_id( $rate ),
				'rate' => $this->rate_debug_context( $rate ),
			)
		);
		if ( RussianPostDomesticSettings::CARRIER_KEY !== (string) ( $rate['carrier_key'] ?? '' )
			|| ! $this->session_manager->is_russian_post_pickup_family( $this->selected_rate_id( $rate ) )
		) {
			$this->debug_validation( 'wdc_pickup_restore_from_post_failed', array( 'reason' => 'unsupported_rate', 'rate' => $this->rate_debug_context( $rate ) ) );
			return false;
		}

		$point_id   = max( 0, (int) ( $data['wdc_pickup_point_id'] ?? 0 ) );
		$point_code = $this->posted_string( $data, 'wdc_pickup_point_code' );
		if ( $point_id <= 0 && '' === $point_code ) {
			$this->debug_validation( 'wdc_pickup_restore_from_post_failed', array( 'reason' => 'posted_point_missing' ) );
			return false;
		}

		$selection = $point_id > 0 ? $this->selection_from_pickup_row( $point_id ) : array();
		if ( array() === $selection && '' !== $point_code ) {
			$selection = $this->selection_from_pickup_code( $point_code );
		}
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
		$this->session_manager->save_checkout_pickup_point( $this->checkout_pickup_point_from_selection( $selection ) );

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
			&& RussianPostDomesticSettings::PICKUP_SERVICE_KEY === (string) ( $rate['service_key'] ?? '' )
			&& DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' )
			&& $this->session_manager->is_russian_post_pickup_family( (string) ( $rate['rate_id'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $rate
	 * @return array<string,mixed>
	 */
	private function rate_debug_context( array $rate ): array {
		return array(
			'rate_id' => (string) ( $rate['rate_id'] ?? '' ),
			'selected_rate_id' => (string) ( $rate['_selected_rate_id'] ?? '' ),
			'service_key' => (string) ( $rate['service_key'] ?? '' ),
			'carrier_key' => (string) ( $rate['carrier_key'] ?? '' ),
			'delivery_type' => (string) ( $rate['delivery_type'] ?? '' ),
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
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Address;

use WallsShop\WDC\Checkout\Locations\CheckoutCityResolver;
use WallsShop\WDC\Checkout\Locations\LocationCoordinateEnricher;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Address\AddressNormalizationResult;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class CheckoutAddressRuntime {
	private bool $order_processed_cleanup_done = false;

	public function __construct(
		private CheckoutAddressNormalizer $normalizer,
		private CheckoutCityResolver $city_resolver,
		private CheckoutSessionManager $session_manager,
		private ?LocationCoordinateEnricher $coordinate_enricher = null
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'update_order_review' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'clear_checkout_session_after_order_processed' ), 20, 1 );
		add_action( 'woocommerce_thankyou', array( $this, 'clear_checkout_session_after_order_processed' ), 20, 1 );
	}

	public function update_order_review( mixed $posted_data ): void {
		$data = $this->parse_posted_data( $posted_data );
		$this->resolve_checkout_address( $data );
	}

	public function clear_checkout_session_after_order_processed( mixed $order_id = null ): void {
		$this->session_manager->clear_normalized_address();
		$this->order_processed_cleanup_done = true;
	}

	/**
	 * @param array<string,mixed> $checkoutData
	 */
	public function resolve_checkout_address( array $checkoutData ): AddressNormalizationResult {
		$context     = $this->context_from_checkout_data( $checkoutData );
		$previous_location_fingerprint = $this->session_manager->current_location_fingerprint();
		$trusted_session_location = $this->trusted_session_location_for_transient_checkout( $context );
		$session_manual = ! $this->order_processed_cleanup_done && $this->has_matching_manual_session_context( $context );
		if ( $session_manual ) {
			$context['selected_source'] = 'manual';
		}
		$fingerprint = $this->fingerprint_from_context( $context );
		$fingerprint_changed = '' !== $this->session_manager->address_fingerprint() && $fingerprint !== $this->session_manager->address_fingerprint();

		if ( $fingerprint_changed ) {
			if ( ! $session_manual ) {
				$this->session_manager->clear_normalized_address_result();
			}
			$this->clear_shipping_rate_cache();
		}

		$selected = $this->selected_location_from_context( $context );
		if ( array() === $selected && array() !== $trusted_session_location ) {
			$selected = $trusted_session_location;
		}
		if ( array() !== $selected ) {
			$selected = $this->enrich_location_coordinates( $selected );
			if ( $this->order_processed_cleanup_done ) {
				return $this->normalizer->normalize( $this->raw_address( $context ), $context );
			}
			$this->session_manager->save_city_context( $this->city_context_from_location( $selected ) );
			$this->session_manager->save_selected_city( $selected );
			$this->session_manager->save_fallback_city( '' );
			$this->reconcile_pickup_selections_after_address_change( $checkoutData, $context, $previous_location_fingerprint, $fingerprint_changed );
			$result = $this->normalizer->normalize( $this->raw_address( $context ), $context );
			$this->session_manager->save_normalized_address_result( $result );
			$this->session_manager->save_address_fingerprint( $fingerprint );

			return $result;
		}

		$location = 'manual' === (string) ( $context['selected_source'] ?? '' )
			? null
			: $this->city_resolver->resolve_city( (string) $context['city'], (string) $context['country_code'] );
		if ( $location instanceof Location ) {
			$location_data = $location->to_array();
			$location_data = $this->enrich_location_coordinates( $location_data );
			$context['region_name'] = (string) ( $location_data['region_name'] ?? '' );
			$context['region_code'] = (string) ( $location_data['region_code'] ?? '' );
			if ( '' === (string) ( $context['postcode'] ?? '' ) ) {
				$context['postcode'] = (string) ( $location_data['postal_code'] ?? '' );
			}
		}

		$raw      = $this->raw_address( $context );
		$result   = $this->normalizer->normalize( $raw, $context );
		if ( $this->order_processed_cleanup_done ) {
			return $result;
		}
		$is_manual_source = 'manual' === (string) ( $context['selected_source'] ?? '' );

		if ( $location instanceof Location ) {
			$this->session_manager->save_selected_city( $location_data );
			$this->session_manager->save_city_context( $this->city_context_from_location( $location_data ) );
		} elseif ( $is_manual_source ) {
			$this->session_manager->save_selected_city( array() );
			$this->session_manager->save_city_context( $this->manual_city_context( $context ) );
		} else {
			$this->session_manager->save_selected_city( array() );
			$this->session_manager->save_city_context( array() );
		}

		if ( $is_manual_source && $result->address->fallback && ! $location instanceof Location ) {
			$this->session_manager->save_fallback_city( (string) $context['city'] );
		} else {
			$this->session_manager->save_fallback_city( '' );
		}

		$this->reconcile_pickup_selections_after_address_change( $checkoutData, $context, $previous_location_fingerprint, $fingerprint_changed );
		$this->session_manager->save_normalized_address_result( $result );
		$this->session_manager->save_address_fingerprint( $fingerprint );

		return $result;
	}

	/**
	 * Normalize a WooCommerce shipping package without changing checkout city ownership.
	 *
	 * @param array<string,mixed> $package_data
	 */
	public function normalize_package_address( array $package_data ): AddressNormalizationResult {
		$context = $this->context_from_checkout_data( $package_data, false );
		$context = $this->package_context_with_session_identity( $context );

		return $this->normalizer->normalize( $this->raw_address( $context ), $context );
	}

	/**
	 * @param array<string,mixed> $checkoutData
	 */
	public function fingerprint_from_checkout_data( array $checkoutData ): string {
		return $this->fingerprint_from_context( $this->context_from_checkout_data( $checkoutData ) );
	}

	/**
	 * @param array<string,mixed> $posted_data
	 * @return array<string,mixed>
	 */
	private function parse_posted_data( mixed $posted_data ): array {
		$data = array();
		if ( is_string( $posted_data ) ) {
			parse_str( $posted_data, $data );
		} elseif ( is_array( $posted_data ) ) {
			$data = $posted_data;
		}

		return $data;
	}

	/**
	 * @param array<string,mixed> $checkoutData
	 */
	private function should_preserve_pickup_selection_for_rate_switch( array $checkoutData, array $context, string $current_rate_id ): bool {
		$selection = $this->session_manager->pickup_selection();
		if ( array() === $selection || ( '' === trim( (string) ( $selection['point_code'] ?? '' ) ) && '' === trim( (string) ( $selection['point_id'] ?? '' ) ) ) ) {
			return false;
		}

		$old_rate_id = (string) ( $selection['rate_id'] ?? '' );
		$new_rate_id = $current_rate_id;
		if ( '' === $new_rate_id ) {
			return false;
		}

		if ( '' === $old_rate_id ) {
			return $this->session_manager->is_russian_post_pickup_family( $new_rate_id );
		}

		return $this->session_manager->is_same_pickup_family( $old_rate_id, $new_rate_id );
	}

	/**
	 * @param array<string,mixed> $checkoutData
	 * @param array<string,string> $context
	 */
	private function reconcile_pickup_selections_after_address_change( array $checkoutData, array $context, string $previous_location_fingerprint, bool $fingerprint_changed ): void {
		$selections = $this->session_manager->pickup_selections();
		if ( ! $fingerprint_changed || array() === $selections ) {
			return;
		}

		$pickup_selection = $this->session_manager->pickup_selection();
		if ( array() === $pickup_selection ) {
			$pickup_selection = reset( $selections );
			$pickup_selection = is_array( $pickup_selection ) ? $pickup_selection : array();
		}
		if ( $this->posted_destination_conflicts_with_pickup( $context, $pickup_selection ) ) {
			$this->session_manager->clear_pickup_selection( 'destination_changed' );
			return;
		}

		$current_location_fingerprint = $this->session_manager->current_location_fingerprint();
		$selection_location_fingerprint = $this->pickup_selection_destination_fingerprint( $pickup_selection );
		$known_location_fingerprint = '' !== $previous_location_fingerprint ? $previous_location_fingerprint : $selection_location_fingerprint;
		if ( '' !== $current_location_fingerprint && '' !== $known_location_fingerprint && $current_location_fingerprint !== $known_location_fingerprint ) {
			$this->session_manager->clear_pickup_selection( 'destination_changed' );
			return;
		}

		if ( array() !== $pickup_selection && ! $this->pickup_selection_has_destination_fingerprint( $pickup_selection ) ) {
			$this->session_manager->clear_pickup_selection( 'destination_changed' );
			return;
		}

		$current_rate_id = $this->selected_shipping_method_from_checkout_data( $checkoutData );
		if ( $this->should_preserve_pickup_selection_for_rate_switch( $checkoutData, $context, $current_rate_id ) ) {
			$this->session_manager->update_pickup_selection_rate_id( $current_rate_id );
			return;
		}

		$this->session_manager->clear_pickup_selection_if_allowed( 'address_fingerprint_changed', $current_rate_id );
	}

	/**
	 * @param array<string,mixed> $selection
	 */
	private function pickup_selection_has_destination_fingerprint( array $selection ): bool {
		return '' !== $this->pickup_selection_destination_fingerprint( $selection );
	}

	/**
	 * @param array<string,mixed> $selection
	 */
	private function pickup_selection_destination_fingerprint( array $selection ): string {
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();

		return trim( (string) ( $selection['destination_fingerprint'] ?? $snapshot['destination_fingerprint'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $checkoutData
	 */
	private function selected_shipping_method_from_checkout_data( array $checkoutData ): string {
		$value = $checkoutData['shipping_method'] ?? '';
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		if ( ( ! is_scalar( $value ) || '' === trim( (string) $value ) )
			&& function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->session ) && is_object( WC()->session ) && method_exists( WC()->session, 'get' )
		) {
			$chosen = WC()->session->get( 'chosen_shipping_methods', array() );
			if ( is_array( $chosen ) ) {
				$value = reset( $chosen );
			}
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return $this->session_manager->normalize_rate_id( (string) $value );
	}

	/**
	 * @param array<string,string> $context
	 * @param array<string,mixed> $selection
	 */
	private function posted_destination_conflicts_with_pickup( array $context, array $selection ): bool {
		$selection_snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$posted_country = strtoupper( trim( (string) ( $context['country_code'] ?? '' ) ) );
		$point_country = strtoupper( trim( (string) ( $selection['country_code'] ?? $selection_snapshot['country_code'] ?? '' ) ) );
		if ( '' !== $posted_country && '' !== $point_country && $posted_country !== $point_country ) {
			return true;
		}

		$posted_fias = $this->normalized_guid( (string) ( $context['selected_fias_id'] ?? '' ) );
		$point_fias = $this->normalized_guid( (string) ( $selection_snapshot['fias_location_guid'] ?? $selection['fias_location_guid'] ?? '' ) );
		if ( '' !== $posted_fias && '' !== $point_fias ) {
			return $posted_fias !== $point_fias;
		}

		$posted_city = $this->normalized_text( (string) ( $context['selected_display_name'] ?? $context['city'] ?? '' ) );
		$point_city = $this->normalized_text( (string) ( $selection_snapshot['city'] ?? $selection['city'] ?? '' ) );
		if ( '' !== $posted_city && '' !== $point_city && ! str_contains( $posted_city, $point_city ) && ! str_contains( $point_city, $posted_city ) ) {
			return true;
		}

		$posted_postcode = $this->normalized_text( (string) ( $context['postcode'] ?? '' ) );
		$point_postcode = $this->normalized_text( (string) ( $selection_snapshot['postcode'] ?? $selection['point_postcode'] ?? '' ) );
		return '' !== $posted_postcode && '' !== $point_postcode && $posted_postcode !== $point_postcode && '' === $posted_city && '' === $point_city;
	}

	private function normalized_guid( string $value ): string {
		return strtolower( str_replace( '-', '', trim( $value ) ) );
	}

	private function normalized_text( string $value ): string {
		$value = trim( $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * @param array<string,mixed> $checkoutData
	 * @return array<string,string>
	 */
	private function context_from_checkout_data( array $checkoutData, bool $resolve_postcode = true ): array {
		$country   = $this->value( $checkoutData, 'shipping_country', 'billing_country', $this->value( $checkoutData, 'country', 'country', 'RU' ) );
		$city      = $this->value( $checkoutData, 'shipping_city', 'billing_city', $this->value( $checkoutData, 'city', 'city', '' ) );
		$region    = $this->value( $checkoutData, 'shipping_state', 'billing_state', $this->value( $checkoutData, 'state', 'region', '' ) );
		$postcode  = $this->value( $checkoutData, 'shipping_postcode', 'billing_postcode', $this->value( $checkoutData, 'postcode', 'postcode', '' ) );
		$address_1 = $this->value( $checkoutData, 'shipping_address_1', 'billing_address_1', $this->value( $checkoutData, 'address', 'street', '' ) );
		$address_2 = $this->value( $checkoutData, 'shipping_address_2', 'billing_address_2', '' );
		$selected_region = $this->value( $checkoutData, 'wdc_platform_location_region_name', 'wdc_platform_location_region_name', '' );
		$selected_region_type = $this->value( $checkoutData, 'wdc_platform_location_region_type', 'wdc_platform_location_region_type', '' );
		if ( '' !== $selected_region ) {
			$region = $selected_region;
		}

		$selected_postcode = $this->value( $checkoutData, 'wdc_platform_location_postcode', 'wdc_platform_location_postcode', '' );
		if ( '' !== $selected_postcode ) {
			$postcode = $selected_postcode;
		}

		if ( $resolve_postcode && '' === $postcode && '' !== $city ) {
			$postcode = (string) ( $this->city_resolver->resolve_postcode( $city, $country ) ?? '' );
		}

		return array(
			'country_code'          => strtoupper( $country ),
			'city'                  => $city,
			'region_name'           => $region,
			'postcode'              => $postcode,
			'address_1'             => $address_1,
			'address_2'             => $address_2,
			'selected_location_id'  => $this->value( $checkoutData, 'wdc_platform_location_id', 'wdc_platform_location_id', '' ),
			'selected_fias_id'      => $this->value( $checkoutData, 'wdc_platform_location_fias_id', 'wdc_platform_location_fias_id', '' ),
			'selected_gar_id'       => $this->value( $checkoutData, 'wdc_platform_location_gar_id', 'wdc_platform_location_gar_id', '' ),
			'selected_gar_object_id' => $this->value( $checkoutData, 'wdc_platform_location_gar_object_id', 'wdc_platform_location_gar_object_id', '' ),
			'selected_display_name' => $this->value( $checkoutData, 'wdc_platform_location_display_name', 'wdc_platform_location_display_name', '' ),
			'selected_country_code' => strtoupper( $this->value( $checkoutData, 'wdc_platform_location_country_code', 'wdc_platform_location_country_code', '' ) ),
			'selected_region_name'  => $selected_region,
			'selected_region_type'  => $selected_region_type,
			'selected_district_name' => $this->value( $checkoutData, 'wdc_platform_location_district_name', 'wdc_platform_location_district_name', '' ),
			'selected_district_type' => $this->value( $checkoutData, 'wdc_platform_location_district_type', 'wdc_platform_location_district_type', '' ),
			'selected_city_name'    => $this->value( $checkoutData, 'wdc_platform_location_city_name', 'wdc_platform_location_city_name', '' ),
			'selected_city_type'    => $this->value( $checkoutData, 'wdc_platform_location_city_type', 'wdc_platform_location_city_type', '' ),
			'selected_place_name'   => $this->value( $checkoutData, 'wdc_platform_location_place_name', 'wdc_platform_location_place_name', '' ),
			'selected_place_type'   => $this->value( $checkoutData, 'wdc_platform_location_place_type', 'wdc_platform_location_place_type', '' ),
			'selected_source'       => $this->value( $checkoutData, 'wdc_platform_location_selected_source', 'wdc_platform_location_selected_source', '' ),
			'selected_lat'          => $this->value( $checkoutData, 'wdc_platform_location_lat', 'wdc_platform_location_lat', '' ),
			'selected_lng'          => $this->value( $checkoutData, 'wdc_platform_location_lng', 'wdc_platform_location_lng', '' ),
		);
	}

	/**
	 * @param array<string,string> $context
	 * @return array<string,string>
	 */
	private function package_context_with_session_identity( array $context ): array {
		$identity = array_merge( $this->session_manager->selected_city(), $this->session_manager->city_context() );
		$identity_country = strtoupper( trim( (string) ( $identity['country_code'] ?? '' ) ) );
		if ( array() === $identity || ( '' !== $identity_country && $identity_country !== $context['country_code'] ) ) {
			return $context;
		}

		$location_id = trim( (string) ( $identity['location_id'] ?? $identity['id'] ?? '' ) );
		$is_manual = 'manual' === (string) ( $identity['selected_source'] ?? $identity['source'] ?? '' ) || ! empty( $identity['is_manual_city'] );
		$context['selected_location_id'] = $is_manual ? '' : $location_id;
		$context['selected_fias_id'] = $is_manual ? '' : trim( (string) ( $identity['fias_id'] ?? '' ) );
		$context['selected_gar_id'] = $is_manual ? '' : trim( (string) ( $identity['gar_id'] ?? '' ) );
		$context['selected_gar_object_id'] = $is_manual ? '' : trim( (string) ( $identity['gar_object_id'] ?? '' ) );
		$context['selected_display_name'] = trim( (string) ( $identity['display_name'] ?? '' ) );
		$context['selected_country_code'] = $identity_country;
		$context['selected_region_name'] = trim( (string) ( $identity['region_name'] ?? '' ) );
		$context['selected_region_type'] = trim( (string) ( $identity['region_type'] ?? '' ) );
		$context['selected_district_name'] = trim( (string) ( $identity['district_name'] ?? '' ) );
		$context['selected_district_type'] = trim( (string) ( $identity['district_type'] ?? '' ) );
		$context['selected_city_name'] = trim( (string) ( $identity['city_name'] ?? '' ) );
		$context['selected_city_type'] = trim( (string) ( $identity['city_type'] ?? '' ) );
		$context['selected_place_name'] = trim( (string) ( $identity['place_name'] ?? $identity['settlement_name'] ?? '' ) );
		$context['selected_place_type'] = trim( (string) ( $identity['place_type'] ?? $identity['settlement_type'] ?? '' ) );
		$context['selected_source'] = $is_manual ? 'manual' : trim( (string) ( $identity['selected_source'] ?? $identity['source'] ?? '' ) );
		$context['selected_lat'] = trim( (string) ( $identity['latitude'] ?? $identity['lat'] ?? '' ) );
		$context['selected_lng'] = trim( (string) ( $identity['longitude'] ?? $identity['lng'] ?? $identity['lon'] ?? '' ) );

		if ( '' === $context['region_name'] ) {
			$context['region_name'] = $context['selected_region_name'];
		}
		if ( '' === $context['postcode'] ) {
			$context['postcode'] = trim( (string) ( $identity['postcode'] ?? $identity['postal_code'] ?? '' ) );
		}

		return $context;
	}

	/**
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	private function trusted_session_location_for_transient_checkout( array $context ): array {
		if ( '' !== $context['selected_location_id'] || '' !== $context['selected_fias_id'] || '' !== $context['selected_gar_id'] || 'manual' === $context['selected_source'] ) {
			return array();
		}

		$identity = array_merge( $this->session_manager->city_context(), $this->session_manager->selected_city() );
		$location_id = is_numeric( $identity['location_id'] ?? $identity['id'] ?? null ) ? (int) ( $identity['location_id'] ?? $identity['id'] ) : 0;
		if ( $location_id <= 0 || 'manual' === (string) ( $identity['selected_source'] ?? $identity['source'] ?? '' ) ) {
			return array();
		}

		$session_country = strtoupper( trim( (string) ( $identity['country_code'] ?? '' ) ) );
		if ( '' !== $session_country && $session_country !== $context['country_code'] ) {
			return array();
		}

		$posted_city = $this->normalized_text( $context['city'] );
		$session_city = $this->normalized_text( (string) ( $identity['place_name'] ?? $identity['settlement_name'] ?? $identity['city_name'] ?? '' ) );
		if ( '' === $posted_city || '' === $session_city || ( ! str_contains( $posted_city, $session_city ) && ! str_contains( $session_city, $posted_city ) ) ) {
			return array();
		}

		$identity['id'] = $location_id;
		$identity['location_id'] = $location_id;
		return $identity;
	}

	/**
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	private function selected_location_from_context( array $context ): array {
		if ( 'manual' === (string) ( $context['selected_source'] ?? '' ) ) {
			return array();
		}

		if ( '' === $context['selected_location_id'] && '' === $context['selected_fias_id'] && '' === $context['selected_gar_id'] && '' === $context['selected_display_name'] ) {
			return array();
		}

		if ( '' !== $context['selected_country_code'] && $context['selected_country_code'] !== $context['country_code'] ) {
			return array();
		}

		$selected_city_name = '' !== $context['selected_city_name'] ? $context['selected_city_name'] : $context['city'];
		$selected_place_name = '' !== $context['selected_place_name'] ? $context['selected_place_name'] : $selected_city_name;

		return array(
			'id'              => $context['selected_location_id'],
			'fias_id'         => $context['selected_fias_id'],
			'gar_id'          => $context['selected_gar_id'],
			'gar_object_id'   => $context['selected_gar_object_id'],
			'country_code'    => $context['country_code'],
			'region_name'     => '' !== $context['selected_region_name'] ? $context['selected_region_name'] : $context['region_name'],
			'region_type'     => $context['selected_region_type'],
			'district_name'   => $context['selected_district_name'],
			'district_type'   => $context['selected_district_type'],
			'region_code'     => '',
			'city_name'       => $selected_city_name,
			'city_type'       => $context['selected_city_type'],
			'settlement_name' => $selected_place_name,
			'settlement_type' => $context['selected_place_type'],
			'place_name'      => $selected_place_name,
			'place_type'      => $context['selected_place_type'],
			'display_name'    => $context['selected_display_name'],
			'postal_code'     => $context['postcode'],
			'latitude'        => $context['selected_lat'],
			'longitude'       => $context['selected_lng'],
			'active'          => true,
			'source'          => 'local_db',
			'selected_source' => $context['selected_source'],
			'is_manual_city'  => false,
		);
	}

	/**
	 * @param array<string,mixed> $location
	 * @return array<string,mixed>
	 */
	private function city_context_from_location( array $location ): array {
		$city_name = trim( (string) ( $location['city_name'] ?? '' ) );
		$place_name = trim( (string) ( $location['place_name'] ?? $location['settlement_name'] ?? '' ) );
		if ( '' === $city_name ) {
			$city_name = $place_name;
		}
		$settlement_name = trim( (string) ( $location['settlement_name'] ?? '' ) );
		if ( '' === $settlement_name ) {
			$settlement_name = '' !== $place_name ? $place_name : $city_name;
		}

		$context = array(
			'country_code'    => strtoupper( (string) ( $location['country_code'] ?? 'RU' ) ),
			'location_id'     => (string) ( $location['id'] ?? '' ),
			'city_name'       => $city_name,
			'settlement_name' => $settlement_name,
			'display_name'    => (string) ( $location['display_name'] ?? '' ),
			'region_name'     => (string) ( $location['region_name'] ?? '' ),
			'region_code'     => (string) ( $location['region_code'] ?? '' ),
			'postcode'        => (string) ( $location['postal_code'] ?? '' ),
			'fias_id'         => (string) ( $location['fias_id'] ?? '' ),
			'gar_id'          => (string) ( $location['gar_id'] ?? '' ),
			'source'          => 'local_db',
			'is_manual_city'  => false,
		);
		$lat = $this->numeric_value( $location['latitude'] ?? $location['lat'] ?? null );
		$lng = $this->numeric_value( $location['longitude'] ?? $location['lng'] ?? null );
		if ( $this->has_usable_coordinates( $lat, $lng ) ) {
			$context['lat'] = $lat;
			$context['lng'] = $lng;
			$context['latitude'] = $lat;
			$context['longitude'] = $lng;
		}

		return $context;
	}

	/**
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	private function manual_city_context( array $context ): array {
		return array(
			'country_code'    => strtoupper( (string) ( $context['country_code'] ?? 'RU' ) ),
			'location_id'     => '',
			'city_name'       => $context['city'],
			'settlement_name' => '',
			'display_name'    => $context['city'],
			'region_name'     => (string) ( $context['region_name'] ?? '' ),
			'region_code'     => '',
			'postcode'        => $context['postcode'],
			'fias_id'         => '',
			'gar_id'          => '',
			'source'          => 'manual',
			'selected_source' => 'manual',
			'is_manual_city'  => true,
		);
	}

	/** @param array<string,string> $context */
	private function has_matching_manual_session_context( array $context ): bool {
		$session_context = $this->session_manager->city_context();
		if ( 'manual' !== (string) ( $session_context['selected_source'] ?? $session_context['source'] ?? '' ) ) {
			return false;
		}

		$session_country = strtoupper( trim( (string) ( $session_context['country_code'] ?? '' ) ) );
		$current_country = strtoupper( trim( (string) ( $context['country_code'] ?? '' ) ) );
		if ( '' === $session_country || '' === $current_country || $session_country !== $current_country ) {
			return false;
		}

		$session_city = $this->normalized_manual_identity_text( (string) ( $session_context['city_name'] ?? $session_context['display_name'] ?? '' ) );
		$current_city = $this->normalized_manual_identity_text( (string) ( $context['city'] ?? '' ) );

		return '' !== $session_city && '' !== $current_city && $session_city === $current_city;
	}

	private function normalized_manual_identity_text( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		$value = str_replace( array( 'ё', 'Ё' ), array( 'е', 'е' ), $value );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * @param array<string,string> $context
	 */
	private function fingerprint_from_context( array $context ): string {
		return sha1(
			implode(
				'|',
				array_map(
					static fn ( string $value ): string => function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $value ), 'UTF-8' ) : strtolower( trim( $value ) ),
					array(
						$context['country_code'],
						$context['city'],
						$context['postcode'],
						$context['address_1'],
						$context['address_2'],
						$context['selected_location_id'],
						$context['selected_fias_id'],
						$context['selected_gar_id'],
						$context['selected_display_name'],
					)
				)
			)
		);
	}

	/**
	 * @param array<string,string> $context
	 */
	private function raw_address( array $context ): string {
		return trim(
			implode(
				' ',
				array_filter(
					array(
						$context['country_code'],
						$context['postcode'],
						$context['city'],
						$context['address_1'],
						$context['address_2'],
					),
					static fn ( string $value ): bool => '' !== trim( $value )
				)
			)
		);
	}

	/**
	 * @param array<string,mixed> $location
	 * @return array<string,mixed>
	 */
	private function enrich_location_coordinates( array $location ): array {
		if ( ! $this->coordinate_enricher instanceof LocationCoordinateEnricher ) {
			return $location;
		}

		return $this->coordinate_enricher->enrich( $location );
	}

	private function numeric_value( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}

	private function has_usable_coordinates( ?float $lat, ?float $lng ): bool {
		if ( null === $lat || null === $lng ) {
			return false;
		}
		if ( abs( $lat ) < 0.000001 && abs( $lng ) < 0.000001 ) {
			return false;
		}

		return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
	}

	private function clear_shipping_rate_cache(): void {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->session ) || ! is_object( WC()->session ) ) {
			return;
		}

		$session = WC()->session;
		for ( $index = 0; $index < 20; $index++ ) {
			$key = 'shipping_for_package_' . $index;
			if ( method_exists( $session, '__unset' ) ) {
				$session->__unset( $key );
				continue;
			}

			if ( method_exists( $session, 'set' ) ) {
				$session->set( $key, null );
			}
		}
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function value( array $data, string $primary, string $secondary, string $default ): string {
		$value = $data[ $primary ] ?? $data[ $secondary ] ?? $default;
		if ( is_array( $value ) ) {
			return '';
		}

		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
		$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) );

		return trim( $value );
	}
}

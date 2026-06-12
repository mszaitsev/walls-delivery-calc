<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\Runtime\CdekCarrier;
use WallsShop\WDC\Domain\Address\AddressNormalizationResult;

defined( 'ABSPATH' ) || exit;

final class CheckoutSessionManager {
	private const DELIVERY_TYPE_KEY = 'wdc_platform_selected_delivery_type';
	private const PICKUP_SELECTION_KEY = 'wdc_platform_pickup_selection';
	private const PICKUP_SELECTIONS_KEY = 'wdc_platform_pickup_selections';
	private const CHECKOUT_PICKUP_POINT_KEY = 'wdc_pickup_point';
	private const PICKUP_CARRIER_KEY = 'wdc_platform_selected_pickup_carrier';
	private const SORT_MODE_KEY     = 'wdc_platform_checkout_sort_mode';
	private const RATES_KEY         = 'wdc_platform_rates';
	private const SELECTED_TARIFFS_KEY = 'wdc_platform_selected_tariffs';
	private const DEBUG_KEY         = 'wdc_platform_debug';
	private const NORMALIZED_ADDRESS_KEY = 'wdc_platform_normalized_address';
	private const SELECTED_CITY_KEY      = 'wdc_platform_selected_city';
	private const CITY_CONTEXT_KEY       = 'wdc_platform_city_context';
	private const FALLBACK_CITY_KEY      = 'wdc_platform_fallback_city';
	private const ADDRESS_FINGERPRINT_KEY = 'wdc_platform_address_fingerprint';

	public function save_selected_delivery_type( string $delivery_type ): void {
		$this->set( self::DELIVERY_TYPE_KEY, $delivery_type );
	}

	public function selected_delivery_type(): string {
		return (string) $this->get( self::DELIVERY_TYPE_KEY, '' );
	}

	/**
	 * @param array<string,mixed> $selection
	 */
	public function save_pickup_selection( array $selection ): void {
		$selection = $this->normalize_pickup_selection_payload( $selection );
		$family = (string) ( $selection['pickup_family'] ?? '' );
		if ( '' !== $family ) {
			$this->save_pickup_selection_for_family( $family, $selection );
			return;
		}
		$this->set( self::PICKUP_SELECTION_KEY, $selection );
		$this->set( self::PICKUP_CARRIER_KEY, (string) ( $selection['carrier_key'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $selection
	 * @return array<string,mixed>
	 */
	public function save_pickup_selection_for_family( string $family, array $selection ): array {
		$family = $this->normalize_pickup_family( $family );
		$selection = $this->normalize_pickup_selection_payload( $selection );
		if ( '' === $family ) {
			$family = $this->normalize_pickup_family( (string) ( $selection['pickup_family'] ?? '' ) );
		}
		if ( '' === $family ) {
			return $selection;
		}

		$carrier = $this->normalize_carrier_key_for_pickup( (string) ( $selection['carrier_key'] ?? $selection['carrier'] ?? explode( ':', $family )[0] ?? '' ) );
		$service = $this->normalize_carrier_key_for_pickup( (string) ( $selection['service_key'] ?? $carrier ) );
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$selection['pickup_family'] = $family;
		$snapshot['pickup_family'] = $family;
		if ( '' !== $carrier ) {
			$selection['carrier_key'] = $carrier;
			$selection['carrier'] = $selection['carrier'] ?? $carrier;
			$snapshot['carrier_key'] = $snapshot['carrier_key'] ?? $carrier;
		}
		if ( '' !== $service ) {
			$selection['service_key'] = $service;
			$snapshot['service_key'] = $snapshot['service_key'] ?? $service;
		}
		$selection['snapshot'] = $snapshot;

		$selections = $this->raw_pickup_selections();
		$selections[ $family ] = $selection;
		$this->set_raw_session_array( self::PICKUP_SELECTIONS_KEY, $selections );
		$raw_after = $this->raw_pickup_selections();
		if ( ! isset( $raw_after[ $family ] ) ) {
			$this->log_pickup_warning(
				'Pickup selection bucket could not be saved.',
				array(
					'saved_family' => $family,
					'raw_pickup_selections_after_keys' => array_keys( $raw_after ),
				)
			);
		}

		$this->set( self::PICKUP_SELECTION_KEY, $selection );
		$this->set( self::CHECKOUT_PICKUP_POINT_KEY, $selection );
		$this->set( self::PICKUP_CARRIER_KEY, (string) ( $selection['carrier_key'] ?? '' ) );

		return $selection;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function pickup_selection(): array {
		$selection = $this->get( self::PICKUP_SELECTION_KEY, array() );

		return is_array( $selection ) ? $selection : array();
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function pickup_selections(): array {
		$selections = $this->raw_pickup_selections();
		$normalized = array();
		foreach ( $selections as $family => $selection ) {
			if ( ! is_string( $family ) || '' === trim( $family ) || ! is_array( $selection ) ) {
				continue;
			}
			$family = $this->normalize_pickup_family( $family );
			$selection = $this->normalize_pickup_selection_payload( $selection, false );
			if ( '' !== $family ) {
				$selection['pickup_family'] = (string) ( $selection['pickup_family'] ?? $family );
				$normalized[ $family ] = $selection;
			}
		}

		if ( array() !== $normalized ) {
			return $normalized;
		}

		$legacy = $this->pickup_selection();
		$legacy_family = $this->normalize_pickup_family( (string) ( $legacy['pickup_family'] ?? '' ) );
		if ( '' !== $legacy_family && $this->selection_has_point_identity( $legacy ) ) {
			$normalized[ $legacy_family ] = $this->normalize_pickup_selection_payload( $legacy, false );
		}

		return $normalized;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function pickup_selection_for_family( string $pickup_family ): array {
		$pickup_family = trim( $pickup_family );
		if ( '' === $pickup_family ) {
			return array();
		}
		$pickup_family = $this->normalize_pickup_family( $pickup_family );

		$selections = $this->pickup_selections();
		$selection = $selections[ $pickup_family ] ?? array();
		return is_array( $selection ) ? $selection : array();
	}

	public function selected_pickup_carrier(): string {
		return (string) $this->get( self::PICKUP_CARRIER_KEY, '' );
	}

	/**
	 * GLOBAL reset: clears all pickup families.
	 *
	 * Use only when checkout destination/location identity changes, checkout context
	 * is fully reset, or an explicit "clear everything" action is requested.
	 */
	public function clear_pickup_selection( string $reason = '' ): void {
		$this->set( self::PICKUP_SELECTION_KEY, array() );
		$this->set_raw_session_array( self::PICKUP_SELECTIONS_KEY, array() );
		$this->set( self::CHECKOUT_PICKUP_POINT_KEY, array() );
		$this->set( self::PICKUP_CARRIER_KEY, '' );
	}

	public function clear_pickup_selection_for_family( string $pickup_family, string $reason = '' ): void {
		$pickup_family = trim( $pickup_family );
		if ( '' === $pickup_family ) {
			$this->clear_pickup_selection( $reason );
			return;
		}

		$selections = $this->raw_pickup_selections();
		unset( $selections[ $pickup_family ] );
		$this->set_raw_session_array( self::PICKUP_SELECTIONS_KEY, $selections );
		$current = $this->pickup_selection();
		if ( $pickup_family === (string) ( $current['pickup_family'] ?? '' ) ) {
			$this->set( self::PICKUP_SELECTION_KEY, array() );
			$this->set( self::CHECKOUT_PICKUP_POINT_KEY, array() );
			$this->set( self::PICKUP_CARRIER_KEY, '' );
		}
	}

	public function clear_pickup_selection_if_allowed( string $reason, string $currentRateId = '' ): bool {
		$current_family = '' !== $currentRateId ? $this->shipping_method_family( $currentRateId ) : '';
		if ( '' !== $current_family && str_ends_with( $current_family, ':pickup' ) ) {
			if ( $this->has_valid_pickup_selection_for_family( $current_family ) ) {
				return false;
			}

			$this->clear_pickup_selection_for_family( $current_family, $reason );
			return true;
		}

		if ( ! $this->is_global_pickup_reset_reason( $reason ) ) {
			return false;
		}

		$this->clear_pickup_selection( $reason );

		return true;
	}

	public function has_valid_pickup_selection(): bool {
		$selection = $this->pickup_selection();

		return array() !== $selection
			&& ( '' !== trim( (string) ( $selection['point_code'] ?? '' ) ) || '' !== trim( (string) ( $selection['point_id'] ?? '' ) ) );
	}

	public function has_valid_pickup_selection_for_family( string $pickup_family ): bool {
		return $this->valid_pickup_selection_for_family( $pickup_family );
	}

	public function valid_pickup_selection_for_family( string $pickup_family ): bool {
		return $this->valid_pickup_selection_for_checkout( $pickup_family );
	}

	public function pickup_selection_has_identity_for_family( string $pickup_family ): bool {
		$selection = $this->pickup_selection_for_family( $pickup_family );

		return array() !== $selection
			&& $this->selection_has_point_identity( $selection )
			&& $this->selection_family_matches( $selection, $pickup_family );
	}

	public function valid_pickup_selection_for_checkout( string $pickup_family ): bool {
		$selection = $this->pickup_selection_for_family( $pickup_family );

		return array() !== $selection
			&& $this->selection_has_point_identity( $selection )
			&& $this->selection_family_matches( $selection, $pickup_family )
			&& $this->pickup_selection_location_matches_current( $selection );
	}

	/**
	 * @param array<string,mixed> $selection
	 */
	public function save_checkout_pickup_point( array $selection ): void {
		$selection = $this->normalize_pickup_selection_payload( $selection );
		$family = (string) ( $selection['pickup_family'] ?? '' );
		if ( '' !== $family ) {
			$this->save_pickup_selection_for_family( $family, $selection );
			return;
		}
		$this->set( self::CHECKOUT_PICKUP_POINT_KEY, $selection );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function checkout_pickup_point(): array {
		$selection = $this->get( self::CHECKOUT_PICKUP_POINT_KEY, array() );

		return is_array( $selection ) ? $selection : array();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function checkout_pickup_point_for_family( string $pickup_family ): array {
		$selection = $this->pickup_selection_for_family( $pickup_family );
		if ( array() !== $selection ) {
			return $selection;
		}

		return array();
	}

	public function pickup_selection_matches( string $carrierKey, string $rateId ): bool {
		$rate_family = $this->shipping_method_family( $rateId );
		$selection = str_ends_with( $rate_family, ':pickup' ) ? $this->pickup_selection_for_family( $rate_family ) : $this->pickup_selection();
		if ( array() === $selection || ! $this->selection_has_point_identity( $selection ) ) {
			return false;
		}

		if ( ! $this->pickup_selection_location_matches_current( $selection ) ) {
			return false;
		}

		$selection_family = (string) ( $selection['pickup_family'] ?? '' );
		if ( str_ends_with( $rate_family, ':pickup' ) && '' !== $selection_family ) {
			if ( $this->normalize_pickup_family( $selection_family ) !== $this->normalize_pickup_family( $rate_family ) ) {
				return false;
			}
			$selection_carrier = $this->normalize_carrier_key_for_pickup( (string) ( $selection['carrier_key'] ?? '' ) );
			$family_carrier = explode( ':', $rate_family )[0] ?? '';
			$expected_carrier = $this->normalize_carrier_key_for_pickup( trim( $carrierKey ) ?: $family_carrier );
			if ( '' === $expected_carrier ) {
				return true;
			}
			if ( '' === $selection_carrier ) {
				$selection_service = $this->normalize_carrier_key_for_pickup( (string) ( $selection['service_key'] ?? '' ) );
				return '' === $selection_service || $selection_service === $expected_carrier;
			}

			return $selection_carrier === $expected_carrier;
		}

		$selection_carrier = $this->normalize_carrier_key_for_pickup( (string) ( $selection['carrier_key'] ?? '' ) );
		$carrierKey = $this->normalize_carrier_key_for_pickup( $carrierKey );
		if ( '' !== $selection_carrier && '' !== $carrierKey && $selection_carrier !== $carrierKey ) {
			return false;
		}

		$selection_rate_id = trim( (string) ( $selection['rate_id'] ?? '' ) );
		if ( '' === $selection_rate_id ) {
			if ( '' !== $selection_family || '' !== $rate_family ) {
				return $selection_family === $rate_family;
			}

			return $selection_carrier === $carrierKey;
		}

		$selection_rate_id = $this->normalize_rate_id( $selection_rate_id );
		$rateId            = $this->normalize_rate_id( $rateId );
		if ( $selection_rate_id === $rateId ) {
			return true;
		}

		$selection_family = (string) ( $selection['pickup_family'] ?? $this->shipping_method_family( $selection_rate_id ) );
		if ( '' !== $selection_family || '' !== $rate_family ) {
			return '' !== $selection_family && $selection_family === $rate_family;
		}

		return $selection_carrier === $carrierKey;
	}

	public function update_pickup_selection_rate_id( string $rateId ): void {
		$family = $this->shipping_method_family( $rateId );
		$selection = str_ends_with( $family, ':pickup' ) ? $this->pickup_selection_for_family( $family ) : $this->pickup_selection();
		if ( array() === $selection ) {
			return;
		}

		$selection['rate_id'] = $this->normalize_rate_id( $rateId );
		$this->save_pickup_selection( $selection );
	}

	public function normalize_rate_id( string $rate_id ): string {
		$rate_id = trim( $rate_id );
		$prefix = NewShippingMethod::METHOD_ID . ':';
		if ( str_starts_with( $rate_id, $prefix ) ) {
			$rate_id = substr( $rate_id, strlen( $prefix ) );
		}

		$platform_prefix = 'wdc_platform:';
		if ( str_starts_with( $rate_id, $platform_prefix ) ) {
			return substr( $rate_id, strlen( $platform_prefix ) );
		}

		return $rate_id;
	}

	public function shipping_method_family( string $rate_id ): string {
		$rate_id = $this->normalize_rate_id( $rate_id );
		$parts = explode( ':', $rate_id );
		$pickup_index = array_search( 'pickup', $parts, true );
		if ( false !== $pickup_index && $pickup_index > 0 ) {
			return $this->normalize_pickup_family( $parts[0] . ':pickup' );
		}

		return $this->normalize_pickup_family( $rate_id );
	}

	public function normalize_carrier_key_for_pickup( string $carrier_key ): string {
		$carrier_key = trim( $carrier_key );
		if ( 'russian_post' === $carrier_key ) {
			return RussianPostDomesticSettings::CARRIER_KEY;
		}

		return $carrier_key;
	}

	public function normalize_pickup_family( string $pickup_family ): string {
		$pickup_family = trim( $pickup_family );
		if ( '' === $pickup_family ) {
			return '';
		}
		$parts = explode( ':', $pickup_family );
		$carrier = $this->normalize_carrier_key_for_pickup( (string) ( $parts[0] ?? '' ) );
		if ( '' === $carrier ) {
			return $pickup_family;
		}

		return str_ends_with( $pickup_family, ':pickup' ) ? $carrier . ':pickup' : $pickup_family;
	}

	public function is_russian_post_pickup_family( string $rate_id ): bool {
		return RussianPostDomesticSettings::checkout_group_id( \WallsShop\WDC\Domain\Quote\DeliveryType::PICKUP ) === $this->shipping_method_family( $rate_id );
	}

	public function is_same_pickup_family( string $oldRateId, string $newRateId ): bool {
		$pickup_family = RussianPostDomesticSettings::checkout_group_id( \WallsShop\WDC\Domain\Quote\DeliveryType::PICKUP );

		return $pickup_family === $this->shipping_method_family( $oldRateId )
			&& $pickup_family === $this->shipping_method_family( $newRateId );
	}

	public function is_cdek_pickup_family( string $rate_id ): bool {
		return CdekCarrier::checkout_group_id( \WallsShop\WDC\Domain\Quote\DeliveryType::PICKUP ) === $this->shipping_method_family( $rate_id );
	}

	public function is_same_cdek_pickup_family( string $oldRateId, string $newRateId ): bool {
		$pickup_family = CdekCarrier::checkout_group_id( \WallsShop\WDC\Domain\Quote\DeliveryType::PICKUP );

		return $pickup_family === $this->shipping_method_family( $oldRateId )
			&& $pickup_family === $this->shipping_method_family( $newRateId );
	}

	private function is_supported_pickup_family( string $rate_id ): bool {
		return str_ends_with( $this->shipping_method_family( $rate_id ), ':pickup' );
	}

	private function is_global_pickup_reset_reason( string $reason ): bool {
		return in_array(
			$reason,
			array(
				'address_fingerprint_changed',
				'destination_changed',
				'location_changed',
				'reset_selection',
			),
			true
		);
	}

	/**
	 * @param array<string,mixed> $selection
	 * @return array<string,mixed>
	 */
	private function normalize_pickup_selection_payload( array $selection, bool $with_current_location = true ): array {
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$carrier = $this->normalize_carrier_key_for_pickup( (string) ( $selection['carrier_key'] ?? $selection['carrier'] ?? $snapshot['carrier_key'] ?? $snapshot['carrier'] ?? '' ) );
		$service = $this->normalize_carrier_key_for_pickup( (string) ( $selection['service_key'] ?? $snapshot['service_key'] ?? $carrier ) );
		$family = $this->normalize_pickup_family( (string) ( $selection['pickup_family'] ?? $snapshot['pickup_family'] ?? '' ) );
		if ( '' === $family ) {
			$rate_family = $this->shipping_method_family( (string) ( $selection['rate_id'] ?? $snapshot['rate_id'] ?? '' ) );
			$family = str_ends_with( $rate_family, ':pickup' ) ? $rate_family : '';
		}
		if ( '' === $family ) {
			$family_source = '' !== $carrier ? $carrier : $service;
			$family = '' !== $family_source ? $family_source . ':pickup' : '';
		}

		if ( '' !== $carrier ) {
			$selection['carrier_key'] = $carrier;
			$selection['carrier'] = $selection['carrier'] ?? $carrier;
			$snapshot['carrier_key'] = $snapshot['carrier_key'] ?? $carrier;
		}
		if ( '' !== $service ) {
			$selection['service_key'] = $service;
			$snapshot['service_key'] = $snapshot['service_key'] ?? $service;
		}
		if ( '' !== $family ) {
			$selection['pickup_family'] = $family;
			$snapshot['pickup_family'] = $snapshot['pickup_family'] ?? $family;
		}
		if ( empty( $selection['point_id'] ) && ! empty( $selection['id'] ) ) {
			$selection['point_id'] = $selection['id'];
		}
		if ( is_array( $selection['snapshot'] ?? null ) && empty( $selection['snapshot']['point_id'] ) && ! empty( $selection['snapshot']['id'] ) ) {
			$selection['snapshot']['point_id'] = $selection['snapshot']['id'];
		}
		$selection['snapshot'] = $snapshot;

		if ( $with_current_location ) {
			$selection = $this->with_current_location_identity( $selection );
		}

		return $selection;
	}

	/**
	 * @param array<string,mixed> $selection
	 * @return array<string,mixed>
	 */
	private function with_current_location_identity( array $selection ): array {
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$context = $this->current_location_context();
		$selection = $this->normalize_location_aliases( $selection );
		$snapshot = $this->normalize_location_aliases( $snapshot );
		foreach ( array(
			'location_id' => array( 'location_id', 'id' ),
			'fias_id' => array( 'fias_id', 'city_fias_id', 'fias_location_guid' ),
			'gar_object_id' => array( 'gar_object_id', 'gar_id' ),
			'city_name' => array( 'city_name', 'settlement_name', 'place_name', 'city' ),
			'region_name' => array( 'region_name', 'state_value', 'region' ),
		) as $target => $sources ) {
			$value = (string) ( $selection[ $target ] ?? $snapshot[ $target ] ?? '' );
			if ( '' === trim( $value ) ) {
				foreach ( $sources as $source ) {
					if ( '' !== trim( (string) ( $context[ $source ] ?? '' ) ) ) {
						$value = (string) $context[ $source ];
						break;
					}
				}
			}
			if ( '' !== trim( $value ) ) {
				$selection[ $target ] = $value;
				$snapshot[ $target ] = $snapshot[ $target ] ?? $value;
			}
		}

		$current_fingerprint = $this->location_fingerprint( $context );
		$fingerprint = '' !== $current_fingerprint ? $current_fingerprint : (string) ( $selection['destination_fingerprint'] ?? $snapshot['destination_fingerprint'] ?? '' );
		if ( '' !== $fingerprint ) {
			$selection['destination_fingerprint'] = $fingerprint;
			$snapshot['destination_fingerprint'] = $snapshot['destination_fingerprint'] ?? $fingerprint;
		}
		$selection['snapshot'] = $snapshot;

		return $selection;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function current_location_context(): array {
		$context = $this->city_context();
		$selected_city = $this->selected_city();
		foreach ( $selected_city as $key => $value ) {
			if ( ! array_key_exists( $key, $context ) || '' === (string) $context[ $key ] || null === $context[ $key ] ) {
				$context[ $key ] = $value;
			}
		}

		return $this->normalize_location_aliases( $context );
	}

	/**
	 * @param array<string,mixed> $selection
	 */
	private function pickup_selection_location_matches_current( array $selection ): bool {
		$current = $this->location_fingerprint( $this->current_location_context() );
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$selected = (string) ( $selection['destination_fingerprint'] ?? $snapshot['destination_fingerprint'] ?? '' );
		if ( '' === $current || '' === $selected ) {
			return true;
		}

		return $current === $selected;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function location_fingerprint( array $context ): string {
		$context = $this->normalize_location_aliases( $context );
		foreach ( array( 'location_id', 'fias_id', 'gar_object_id' ) as $key ) {
			$value = $this->normalized_location_value( $context[ $key ] ?? '' );
			if ( '' !== $value ) {
				return $key . '=' . $value;
			}
		}

		$city = $this->normalized_location_value( $context['city_name'] ?? '' );
		$region = $this->normalized_location_value( $context['region_name'] ?? '' );
		if ( '' !== $city || '' !== $region ) {
			return 'place=' . $region . '|' . $city;
		}

		$postcode = $this->normalized_location_value( $context['postcode'] ?? '' );
		return '' !== $postcode ? 'postcode=' . $postcode : '';
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private function normalize_location_aliases( array $context ): array {
		$aliases = array(
			'fias_id' => array( 'fias_id', 'city_fias_id', 'fias_location_guid' ),
			'gar_object_id' => array( 'gar_object_id', 'gar_id' ),
			'city_name' => array( 'city_name', 'settlement_name', 'place_name', 'city' ),
			'region_name' => array( 'region_name', 'state_value', 'region' ),
			'postcode' => array( 'postcode', 'postal_code' ),
		);
		foreach ( $aliases as $target => $sources ) {
			if ( '' !== trim( (string) ( $context[ $target ] ?? '' ) ) ) {
				continue;
			}
			foreach ( $sources as $source ) {
				$value = trim( (string) ( $context[ $source ] ?? '' ) );
				if ( '' !== $value ) {
					$context[ $target ] = $value;
					break;
				}
			}
		}

		return $context;
	}

	private function normalized_location_value( mixed $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );

		return preg_replace( '/\s+/u', ' ', $value ) ?: $value;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_pickup_selections(): array {
		$selections = $this->get_raw_session_value( self::PICKUP_SELECTIONS_KEY, array() );
		$stored = array();
		if ( ! is_array( $selections ) ) {
			return $stored;
		}
		foreach ( $selections as $family => $selection ) {
			if ( ! is_string( $family ) || ! is_array( $selection ) ) {
				continue;
			}
			$family = $this->normalize_pickup_family( $family );
			if ( '' !== $family ) {
				$stored[ $family ] = $selection;
			}
		}

		return $stored;
	}

	/**
	 * @param array<string,mixed> $selection
	 */
	private function selection_has_point_identity( array $selection ): bool {
		return '' !== trim( (string) ( $selection['point_code'] ?? '' ) )
			|| '' !== trim( (string) ( $selection['point_id'] ?? $selection['id'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $selection
	 */
	private function selection_family_matches( array $selection, string $pickup_family ): bool {
		$selection_family = $this->normalize_pickup_family( (string) ( $selection['pickup_family'] ?? '' ) );
		$pickup_family = $this->normalize_pickup_family( $pickup_family );

		return '' !== $pickup_family && $selection_family === $pickup_family;
	}

	public function save_sort_mode( string $sort_mode ): void {
		$this->set( self::SORT_MODE_KEY, $sort_mode );
	}

	public function selected_sort_mode(): string {
		return (string) $this->get( self::SORT_MODE_KEY, '' );
	}

	/**
	 * @param array<string,array<string,mixed>> $rates
	 */
	public function save_rates( array $rates ): void {
		$this->set( self::RATES_KEY, $rates );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function rates(): array {
		$rates = $this->get( self::RATES_KEY, array() );

		return is_array( $rates ) ? $rates : array();
	}

	/**
	 * @param array<string,mixed> $selection
	 */
	public function save_selected_tariff( string $service_key, array $selection ): void {
		$selected = $this->selected_tariffs();
		$selected[ $service_key ] = $selection;
		$this->set( self::SELECTED_TARIFFS_KEY, $selected );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function selected_tariff( string $service_key ): array {
		$selected = $this->selected_tariffs();
		$value = $selected[ $service_key ] ?? array();

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function selected_tariffs(): array {
		$selected = $this->get( self::SELECTED_TARIFFS_KEY, array() );

		return is_array( $selected ) ? $selected : array();
	}

	/**
	 * @param array<string,mixed> $debug
	 */
	public function save_debug( array $debug ): void {
		$this->set( self::DEBUG_KEY, $debug );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function debug(): array {
		$debug = $this->get( self::DEBUG_KEY, array() );

		return is_array( $debug ) ? $debug : array();
	}

	public function save_normalized_address_result( AddressNormalizationResult $result ): void {
		$this->set( self::NORMALIZED_ADDRESS_KEY, $result->to_array() );
	}

	public function normalized_address_result(): ?AddressNormalizationResult {
		$result = $this->get( self::NORMALIZED_ADDRESS_KEY, array() );

		return is_array( $result ) && array() !== $result ? AddressNormalizationResult::from_array( $result ) : null;
	}

	public function clear_normalized_address(): void {
		$this->set( self::NORMALIZED_ADDRESS_KEY, array() );
		$this->set( self::SELECTED_CITY_KEY, array() );
		$this->set( self::CITY_CONTEXT_KEY, array() );
		$this->set( self::FALLBACK_CITY_KEY, '' );
	}

	/**
	 * @param array<string,mixed> $city
	 */
	public function save_selected_city( array $city ): void {
		$this->set( self::SELECTED_CITY_KEY, $city );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function selected_city(): array {
		$city = $this->get( self::SELECTED_CITY_KEY, array() );

		return is_array( $city ) ? $city : array();
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function save_city_context( array $context ): void {
		$this->set( self::CITY_CONTEXT_KEY, $context );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function city_context(): array {
		$context = $this->get( self::CITY_CONTEXT_KEY, array() );

		return is_array( $context ) ? $context : array();
	}

	public function save_fallback_city( string $city ): void {
		$this->set( self::FALLBACK_CITY_KEY, $city );
	}

	public function fallback_city(): string {
		return (string) $this->get( self::FALLBACK_CITY_KEY, '' );
	}

	public function save_address_fingerprint( string $fingerprint ): void {
		$this->set( self::ADDRESS_FINGERPRINT_KEY, $fingerprint );
	}

	public function address_fingerprint(): string {
		return (string) $this->get( self::ADDRESS_FINGERPRINT_KEY, '' );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function raw_pickup_selections(): array {
		return $this->stored_pickup_selections();
	}

	private function set( string $key, mixed $value ): void {
		$session = $this->session();
		if ( is_object( $session ) && method_exists( $session, 'set' ) ) {
			$session->set( $key, $value );
		}
	}

	/**
	 * @param array<string,mixed> $value
	 */
	private function set_raw_session_array( string $key, array $value ): void {
		$session = $this->session();
		if ( ! is_object( $session ) ) {
			return;
		}
		if ( method_exists( $session, 'set' ) ) {
			$session->set( $key, $value );
		}
		$this->write_session_storage_property( $session, $key, $value );
		if ( method_exists( $session, 'save_data' ) ) {
			$session->save_data();
		}
	}

	private function get( string $key, mixed $default ): mixed {
		$session = $this->session();
		if ( is_object( $session ) && method_exists( $session, 'get' ) ) {
			return $session->get( $key, $default );
		}

		return $default;
	}

	private function get_raw_session_value( string $key, mixed $default ): mixed {
		$session = $this->session();
		if ( ! is_object( $session ) ) {
			return $default;
		}
		if ( method_exists( $session, 'get' ) ) {
			$value = $session->get( $key, null );
			if ( null !== $value ) {
				return $value;
			}
		}

		return $this->read_session_storage_property( $session, $key, $default );
	}

	private function read_session_storage_property( object $session, string $key, mixed $default ): mixed {
		foreach ( array( 'data', '_data' ) as $property ) {
			if ( ! property_exists( $session, $property ) ) {
				continue;
			}
			try {
				$reflection = new \ReflectionProperty( $session, $property );
				$reflection->setAccessible( true );
				$data = $reflection->getValue( $session );
			} catch ( \Throwable ) {
				continue;
			}
			if ( is_array( $data ) && array_key_exists( $key, $data ) ) {
				return $data[ $key ];
			}
		}

		return $default;
	}

	private function write_session_storage_property( object $session, string $key, mixed $value ): void {
		foreach ( array( 'data', '_data' ) as $property ) {
			if ( ! property_exists( $session, $property ) ) {
				continue;
			}
			try {
				$reflection = new \ReflectionProperty( $session, $property );
				$reflection->setAccessible( true );
				$data = $reflection->getValue( $session );
				if ( ! is_array( $data ) ) {
					$data = array();
				}
				$data[ $key ] = $value;
				$reflection->setValue( $session, $data );
			} catch ( \Throwable ) {
				continue;
			}
		}
	}

	private function log_pickup_warning( string $message, array $context = array() ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array_merge( $context, array( 'source' => 'walls-delivery-calc' ) ) );
			return;
		}

		if ( function_exists( 'error_log' ) ) {
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $context ) : json_encode( $context );
			error_log( '[walls-delivery-calc] warning: ' . $message . ' ' . ( false !== $encoded ? $encoded : '' ) );
		}
	}

	private function session(): mixed {
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->session ) ) {
			return WC()->session;
		}

		return null;
	}

}

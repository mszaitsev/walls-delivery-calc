<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Providers;

use RuntimeException;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;

defined( 'ABSPATH' ) || exit;

final class CheckoutPickupPointProviderQueryResolver {
	public function __construct( private CheckoutSessionManager $session_manager ) {
	}

	public function resolve( string $shipping_method_id, string $carrier_key, string $pickup_family ): CarrierPickupPointQuery {
		$rate = $this->rate( $shipping_method_id );
		if ( array() === $rate ) {
			throw new RuntimeException( 'provider_rate_context_missing' );
		}
		$meta = is_array( $rate['meta'] ?? null ) ? $rate['meta'] : array();
		$rate_carrier = (string) ( $rate['carrier_key'] ?? $meta['carrier_key'] ?? '' );
		$rate_family = (string) ( $meta['pickup_family'] ?? '' );
		if ( ! (bool) ( $rate['requires_pickup_point'] ?? false ) || $rate_carrier !== $carrier_key || $rate_family !== $pickup_family ) {
			throw new RuntimeException( 'provider_rate_context_mismatch' );
		}
		$snapshot = is_array( $meta['pickup_provider_query'] ?? null ) ? $meta['pickup_provider_query'] : array();
		$cargo = is_array( $snapshot['cargo'] ?? null ) ? $snapshot['cargo'] : array();
		$query = new CarrierPickupPointQuery(
			(string) ( $snapshot['carrier_key'] ?? $carrier_key ),
			(int) ( $snapshot['location_id'] ?? 0 ),
			(string) ( $snapshot['country_code'] ?? 'RU' ),
			'',
			is_numeric( $snapshot['latitude'] ?? null ) ? (float) $snapshot['latitude'] : null,
			is_numeric( $snapshot['longitude'] ?? null ) ? (float) $snapshot['longitude'] : null,
			new PickupCargoConstraints(
				(int) ( $cargo['weight_g'] ?? 0 ),
				(int) ( $cargo['volume_cm3'] ?? 0 ),
				(int) ( $cargo['max_dimension_cm'] ?? 0 ),
				(int) ( $cargo['max_place_weight_g'] ?? 0 ),
				max( 1, (int) ( $cargo['places_count'] ?? 1 ) )
			),
			(string) ( $snapshot['purpose'] ?? CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP ),
			max( 1, (int) ( $snapshot['radius_km'] ?? 50 ) ),
			max( 1, (int) ( $snapshot['limit'] ?? 50 ) )
		);
		if ( array() !== $query->validate() ) {
			throw new RuntimeException( 'provider_rate_context_missing' );
		}

		return $query;
	}

	public function destination_fingerprint( string $shipping_method_id ): string {
		$rate = $this->rate( $shipping_method_id );
		$meta = is_array( $rate['meta'] ?? null ) ? $rate['meta'] : array();
		$snapshot = is_array( $meta['pickup_provider_query'] ?? null ) ? $meta['pickup_provider_query'] : array();

		return (string) ( $snapshot['destination_fingerprint'] ?? '' );
	}

	/** @return array<string,mixed> */
	private function rate( string $shipping_method_id ): array {
		$id = $this->session_manager->normalize_rate_id( $shipping_method_id );
		foreach ( $this->session_manager->rates() as $key => $rate ) {
			if ( ! is_array( $rate ) ) {
				continue;
			}
			$rate_id = $this->session_manager->normalize_rate_id( (string) ( $rate['rate_id'] ?? $key ) );
			if ( $rate_id === $id ) {
				return $rate;
			}
		}

		return array();
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Providers;

defined( 'ABSPATH' ) || exit;

final class CarrierPickupPointQuery {
	public const PURPOSE_DESTINATION_PICKUP = 'destination_pickup';

	public function __construct(
		public readonly string $carrier_key,
		public readonly int $location_id,
		public readonly string $country_code,
		public readonly string $fallback_address,
		public readonly ?float $latitude,
		public readonly ?float $longitude,
		public readonly PickupCargoConstraints $cargo,
		public readonly string $purpose = self::PURPOSE_DESTINATION_PICKUP,
		public readonly int $radius_km = 50,
		public readonly int $limit = 50
	) {
	}

	public function normalized_carrier_key(): string {
		return strtolower( trim( $this->carrier_key ) );
	}

	public function normalized_country_code(): string {
		return strtoupper( trim( $this->country_code ) );
	}

	/** @return array<int,string> */
	public function validate(): array {
		$errors = array();
		if ( ! preg_match( '/^[a-z0-9_\\-]+$/', $this->normalized_carrier_key() ) ) {
			$errors[] = 'carrier_key is invalid';
		}
		if ( ! preg_match( '/^[A-Z]{2}$/', $this->normalized_country_code() ) ) {
			$errors[] = 'country_code is invalid';
		}
		if ( self::PURPOSE_DESTINATION_PICKUP !== $this->purpose ) {
			$errors[] = 'purpose is unsupported';
		}
		if ( $this->location_id <= 0 && '' === trim( $this->fallback_address ) && ( null === $this->latitude || null === $this->longitude ) ) {
			$errors[] = 'location_id or fallback address/coordinates are required';
		}
		if ( ( null === $this->latitude ) !== ( null === $this->longitude ) ) {
			$errors[] = 'coordinates must contain both latitude and longitude';
		}
		if ( null !== $this->latitude && ( $this->latitude < -90 || $this->latitude > 90 ) ) {
			$errors[] = 'latitude must be between -90 and 90';
		}
		if ( null !== $this->longitude && ( $this->longitude < -180 || $this->longitude > 180 ) ) {
			$errors[] = 'longitude must be between -180 and 180';
		}
		if ( $this->radius_km < 1 ) {
			$errors[] = 'radius_km must be greater than 0';
		}
		if ( $this->limit < 1 ) {
			$errors[] = 'limit must be greater than 0';
		}

		return array_merge( $errors, $this->cargo->validate() );
	}
}

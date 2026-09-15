<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Providers;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

final class CarrierPickupPointProviderRegistry {
	/** @var array<string,CarrierPickupPointProviderInterface> */
	private array $providers = array();

	/** @param array<int,CarrierPickupPointProviderInterface> $providers */
	public function __construct( array $providers = array() ) {
		foreach ( $providers as $provider ) {
			$this->register( $provider );
		}
	}

	public function register( CarrierPickupPointProviderInterface $provider ): void {
		$key = $this->normalize_key( $provider->carrier_key() );
		if ( '' === $key ) {
			throw new InvalidArgumentException( 'Pickup provider carrier key is invalid.' );
		}
		if ( isset( $this->providers[ $key ] ) ) {
			throw new InvalidArgumentException( 'Duplicate pickup provider carrier key: ' . $key );
		}
		$this->providers[ $key ] = $provider;
	}

	public function has( string $carrier_key ): bool {
		return isset( $this->providers[ $this->normalize_key( $carrier_key ) ] );
	}

	public function get( string $carrier_key ): ?CarrierPickupPointProviderInterface {
		return $this->providers[ $this->normalize_key( $carrier_key ) ] ?? null;
	}

	/** @return array<string,CarrierPickupPointProviderInterface> */
	public function all(): array {
		return $this->providers;
	}

	private function normalize_key( string $key ): string {
		$key = strtolower( trim( $key ) );

		return preg_match( '/^[a-z0-9_\\-]+$/', $key ) ? $key : '';
	}
}

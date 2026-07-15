<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Documents;

defined( 'ABSPATH' ) || exit;

final class ShipmentDocumentProviderRegistry {
	/** @var array<string,CarrierShipmentDocumentProviderInterface> */
	private array $providers = array();

	/** @param array<int,CarrierShipmentDocumentProviderInterface> $providers */
	public function __construct( array $providers = array() ) {
		foreach ( $providers as $provider ) {
			$this->register( $provider );
		}
	}

	public function register( CarrierShipmentDocumentProviderInterface $provider ): void {
		$key = $this->sanitize_key( $provider->carrier_key() );
		if ( '' === $key ) {
			throw new \InvalidArgumentException( 'Shipment document provider carrier key must not be empty.' );
		}
		if ( isset( $this->providers[ $key ] ) ) {
			throw new \InvalidArgumentException( 'Duplicate shipment document provider key: ' . $key );
		}
		$this->providers[ $key ] = $provider;
	}

	public function get( string $carrier_key ): ?CarrierShipmentDocumentProviderInterface {
		$key = $this->sanitize_key( $carrier_key );

		return $this->providers[ $key ] ?? null;
	}

	/** @return array<int,string> */
	public function keys(): array {
		return array_keys( $this->providers );
	}

	private function sanitize_key( string $value ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( $value );
		}

		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' );
	}
}

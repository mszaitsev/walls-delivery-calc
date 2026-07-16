<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Modal;

defined( 'ABSPATH' ) || exit;

final class ShipmentModalExtensionRegistry {
	/** @var array<string,CarrierShipmentModalExtensionInterface> */
	private array $extensions = array();

	/** @param array<int,CarrierShipmentModalExtensionInterface> $extensions */
	public function __construct( array $extensions = array() ) {
		foreach ( $extensions as $extension ) {
			$this->register( $extension );
		}
	}

	public function register( CarrierShipmentModalExtensionInterface $extension ): void {
		$key = $this->sanitize_key( $extension->carrier_key() );
		if ( '' === $key ) {
			throw new \InvalidArgumentException( 'Shipment modal extension carrier key must not be empty.' );
		}
		if ( isset( $this->extensions[ $key ] ) ) {
			throw new \InvalidArgumentException( 'Duplicate shipment modal extension key: ' . $key );
		}
		$this->extensions[ $key ] = $extension;
	}

	public function get( string $carrier_key ): ?CarrierShipmentModalExtensionInterface {
		$key = $this->sanitize_key( $carrier_key );

		return $this->extensions[ $key ] ?? null;
	}

	/** @return array<int,string> */
	public function keys(): array {
		return array_keys( $this->extensions );
	}

	private function sanitize_key( string $value ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( $value );
		}

		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' );
	}
}

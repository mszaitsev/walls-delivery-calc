<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;

defined( 'ABSPATH' ) || exit;

final class CarrierShipmentAdapterRegistry {
	/**
	 * @var array<string,CarrierShipmentAdapterInterface>
	 */
	private array $adapters = array();

	/**
	 * @param array<int,CarrierShipmentAdapterInterface> $adapters
	 */
	public function __construct( array $adapters = array() ) {
		foreach ( $adapters as $adapter ) {
			$this->register( $adapter );
		}
	}

	public function register( CarrierShipmentAdapterInterface $adapter ): void {
		$key = trim( $adapter->carrier_key() );
		if ( '' === $key ) {
			throw new \InvalidArgumentException( 'Shipment adapter carrier key must not be empty.' );
		}
		if ( isset( $this->adapters[ $key ] ) ) {
			throw new \InvalidArgumentException( 'Duplicate shipment adapter key: ' . $key );
		}
		$this->adapters[ $key ] = $adapter;
	}

	public function get( string $carrier_key ): ?CarrierShipmentAdapterInterface {
		$carrier_key = trim( $carrier_key );

		return $this->adapters[ $carrier_key ] ?? null;
	}

	public function has( string $carrier_key ): bool {
		return $this->get( $carrier_key ) instanceof CarrierShipmentAdapterInterface;
	}

	/**
	 * @return array<string,CarrierShipmentAdapterInterface>
	 */
	public function all(): array {
		return $this->adapters;
	}
}

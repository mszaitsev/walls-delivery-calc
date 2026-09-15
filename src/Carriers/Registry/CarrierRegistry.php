<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Registry;

use InvalidArgumentException;
use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;

defined( 'ABSPATH' ) || exit;

final class CarrierRegistry {
	/** @var array<string,CarrierAdapterInterface> */
	private array $adapters = array();

	public function register( CarrierAdapterInterface $adapter ): void {
		$this->adapters[ $adapter->get_identity()->key ] = $adapter;
	}

	public function get( string $key ): CarrierAdapterInterface {
		if ( ! isset( $this->adapters[ $key ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Carrier adapter "%s" is not registered.', $key ) );
		}

		return $this->adapters[ $key ];
	}

	/**
	 * @return array<string,CarrierAdapterInterface>
	 */
	public function all(): array {
		return $this->adapters;
	}

	/**
	 * @return array<string,CarrierAdapterInterface>
	 */
	public function enabled(): array {
		return array_filter(
			$this->adapters,
			static fn ( CarrierAdapterInterface $adapter ): bool => $adapter->get_identity()->enabled
		);
	}

	/**
	 * @return array<string,CarrierAdapterInterface>
	 */
	public function for_country( string $countryCode ): array {
		return array_filter(
			$this->enabled(),
			static fn ( CarrierAdapterInterface $adapter ): bool => $adapter->supports_country( $countryCode )
		);
	}

	public function has( string $key ): bool {
		return isset( $this->adapters[ $key ] );
	}
}

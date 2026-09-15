<?php
declare(strict_types=1);

namespace WallsShop\WDC\Core;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

final class Container {
	/** @var array<string, callable(): object> */
	private array $factories = array();

	/** @var array<string, object> */
	private array $instances = array();

	/**
	 * @param callable(): object $factory
	 */
	public function register( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Service "%s" is not registered.', $id ) );
		}

		$service = ( $this->factories[ $id ] )();
		$this->instances[ $id ] = $service;

		return $service;
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || isset( $this->instances[ $id ] );
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Core;

defined( 'ABSPATH' ) || exit;

final class FeatureFlags {
	/** @var array<string, bool> */
	private array $flags = array(
		'legacy_shipping_enabled'     => true,
		'new_core_enabled'            => true,
		'new_checkout_flow_enabled'   => false,
		'new_carriers_enabled'        => false,
		'new_shipping_method_enabled' => false,
	);

	public function enabled( string $flag ): bool {
		return true === ( $this->flags[ $flag ] ?? false );
	}

	public function new_shipping_method_enabled(): bool {
		return $this->enabled( 'new_shipping_method_enabled' );
	}

	public function set( string $flag, bool $enabled ): void {
		$this->flags[ $flag ] = $enabled;
	}

	public function set_new_shipping_method_enabled( bool $enabled ): void {
		$this->set( 'new_shipping_method_enabled', $enabled );
	}

	/**
	 * @return array<string, bool>
	 */
	public function all(): array {
		return $this->flags;
	}
}

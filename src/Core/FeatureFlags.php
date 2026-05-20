<?php
declare(strict_types=1);

namespace WallsShop\WDC\Core;

defined( 'ABSPATH' ) || exit;

final class FeatureFlags {
	/** @var array<string, bool> */
	private array $flags = array(
		'legacy_shipping_enabled'    => true,
		'new_core_enabled'           => true,
		'new_checkout_flow_enabled'  => false,
		'new_carriers_enabled'       => false,
	);

	public function enabled( string $flag ): bool {
		return true === ( $this->flags[ $flag ] ?? false );
	}

	/**
	 * @return array<string, bool>
	 */
	public function all(): array {
		return $this->flags;
	}
}

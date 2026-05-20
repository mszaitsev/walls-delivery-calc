<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Services;

use WallsShop\WDC\Domain\Address\Address;

defined( 'ABSPATH' ) || exit;

interface PickupProviderInterface {
	public function supports_carrier( string $carrierKey ): bool;

	/**
	 * @return array<int,\WallsShop\WDC\Domain\Pickup\PickupPoint>
	 */
	public function get_points( string $carrierKey, Address $destination ): array;
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Providers;

use WallsShop\WDC\Domain\Pickup\PickupPoint;

defined( 'ABSPATH' ) || exit;

interface CarrierPickupPointProviderInterface {
	public function carrier_key(): string;

	/** @return array<int,PickupPoint> */
	public function search( CarrierPickupPointQuery $query ): array;

	public function resolve_selection( CarrierPickupPointSelectionQuery $query ): ?PickupPoint;
}

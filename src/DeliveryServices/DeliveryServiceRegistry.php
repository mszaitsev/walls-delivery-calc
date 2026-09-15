<?php
declare(strict_types=1);

namespace WallsShop\WDC\DeliveryServices;

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;

defined( 'ABSPATH' ) || exit;

final class DeliveryServiceRegistry {
	public function __construct(
		private DeliveryServiceRepository $services,
		private CarrierRegistry $carriers
	) {
	}

	/**
	 * @return array<int,DeliveryService>
	 */
	public function active_services(): array {
		return array_values(
			array_filter(
				$this->services->list_active(),
				static fn ( DeliveryService $service ): bool => $service->enabled && ! $service->deleted
			)
		);
	}

	public function carrier_for( DeliveryService $service ): ?CarrierAdapterInterface {
		return '' !== $service->carrier_key && $this->carriers->has( $service->carrier_key ) ? $this->carriers->get( $service->carrier_key ) : null;
	}
}

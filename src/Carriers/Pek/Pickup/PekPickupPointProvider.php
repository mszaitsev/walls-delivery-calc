<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Pickup;

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderInterface;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;

defined( 'ABSPATH' ) || exit;

final class PekPickupPointProvider implements CarrierPickupPointProviderInterface {
	public function __construct( private PekTerminalService $terminals ) {
	}

	public function carrier_key(): string {
		return PekSettings::CARRIER_KEY;
	}

	/** @return array<int,PickupPoint> */
	public function search( CarrierPickupPointQuery $query ): array {
		if ( PekSettings::CARRIER_KEY !== $query->normalized_carrier_key() || CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP !== $query->purpose || ! in_array( $query->normalized_country_code(), PekSettings::PLANNED_COUNTRIES, true ) ) {
			return array();
		}

		return $this->terminals->search( $query );
	}

	public function resolve_selection( CarrierPickupPointSelectionQuery $query ): ?PickupPoint {
		if ( array() !== $query->validate() || PekSettings::CARRIER_KEY !== $query->query->normalized_carrier_key() ) {
			return null;
		}

		return $this->terminals->resolve_selection( $query->query, $query->point_code );
	}
}

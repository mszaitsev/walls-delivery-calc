<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

use WallsShop\WDC\Carriers\Registry\CarrierRegistry;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsService {
	public function __construct(
		private ShipmentCostAnalyticsQuery $query,
		private CarrierRegistry $carriers
	) {
	}

	/**
	 * @return array<string,string>
	 */
	public function carrier_options(): array {
		$options = array();
		foreach ( $this->carriers->all() as $key => $adapter ) {
			$identity = $adapter->get_identity();
			$options[ (string) $key ] = $identity->name;
		}
		asort( $options );

		return $options;
	}

	public function result( ShipmentCostAnalyticsFilter $filter ): ShipmentCostAnalyticsResult {
		return $this->query->result( $filter, $this->carrier_options() );
	}
}

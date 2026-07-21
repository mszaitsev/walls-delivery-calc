<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsQuery {
	/**
	 * @return array<int,object>
	 */
	public function orders( ShipmentCostAnalyticsFilter $filter ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'date_created' => $filter->date_from_start() . '...' . $filter->date_to_end(),
				'limit' => -1,
				'return' => 'objects',
			)
		);

		return is_array( $orders ) ? array_values( array_filter( $orders, 'is_object' ) ) : array();
	}
}

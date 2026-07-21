<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsResult {
	/**
	 * @param array<int,ShipmentCostAnalyticsRow> $rows
	 */
	public function __construct(
		public readonly array $rows,
		public readonly ShipmentCostAnalyticsSummary $summary,
		public readonly int $total_rows,
		public readonly int $total_pages,
		public readonly int $current_page
	) {
	}
}

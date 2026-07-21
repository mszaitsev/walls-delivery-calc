<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsSummary {
	public function __construct(
		public readonly int $shipment_count,
		public readonly int $with_actual_count,
		public readonly int $without_actual_count,
		public readonly int $planned_total_kopecks,
		public readonly int $actual_total_kopecks,
		public readonly int $comparable_planned_total_kopecks,
		public readonly int $difference_total_kopecks,
		public readonly ?int $average_difference_percent_basis_points,
		public readonly int $over_threshold_count,
		public readonly int $comparable_count,
		public readonly int $skipped_without_selected_carrier = 0,
		public readonly int $skipped_without_matching_shipment = 0,
		public readonly int $skipped_ambiguous = 0
	) {
	}

	public function over_threshold_share_basis_points(): ?int {
		if ( $this->comparable_count <= 0 ) {
			return null;
		}

		return intdiv( $this->over_threshold_count * 10000, $this->comparable_count );
	}
}

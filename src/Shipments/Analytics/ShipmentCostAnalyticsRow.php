<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsRow {
	public function __construct(
		public readonly int $order_id,
		public readonly string $order_number,
		public readonly string $order_created_at,
		public readonly string $carrier_key,
		public readonly string $carrier_title,
		public readonly string $service_key,
		public readonly string $service_title,
		public readonly ?int $base_api_cost_kopecks,
		public readonly ?int $actual_cost_kopecks,
		public readonly string $actual_cost_source,
		public readonly string $actual_cost_source_detail,
		public readonly ?int $difference_kopecks,
		public readonly ?int $difference_percent_basis_points,
		public readonly string $threshold_status,
		public readonly string $order_edit_url
	) {
	}
}

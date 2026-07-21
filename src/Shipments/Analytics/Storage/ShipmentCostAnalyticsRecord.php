<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics\Storage;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsRecord {
	public function __construct(
		public readonly int $order_id,
		public readonly string $order_number,
		public readonly string $order_created_at,
		public readonly string $carrier_key,
		public readonly string $service_key,
		public readonly string $service_title,
		public readonly string $shipment_key,
		public readonly string $shipment_identifier,
		public readonly ?int $base_api_cost_kopecks,
		public readonly ?int $actual_cost_kopecks,
		public readonly string $actual_cost_currency,
		public readonly string $actual_cost_source,
		public readonly string $actual_cost_source_detail,
		public readonly ?string $actual_cost_updated_at,
		public readonly ?int $difference_kopecks,
		public readonly ?int $difference_percent_basis_points,
		public readonly string $threshold_status,
		public readonly string $indexed_at
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'order_id' => $this->order_id,
			'order_number' => $this->order_number,
			'order_created_at' => $this->order_created_at,
			'carrier_key' => $this->carrier_key,
			'service_key' => $this->service_key,
			'service_title' => $this->service_title,
			'shipment_key' => $this->shipment_key,
			'shipment_identifier' => $this->shipment_identifier,
			'base_api_cost_kopecks' => $this->base_api_cost_kopecks,
			'actual_cost_kopecks' => $this->actual_cost_kopecks,
			'actual_cost_currency' => $this->actual_cost_currency,
			'actual_cost_source' => $this->actual_cost_source,
			'actual_cost_source_detail' => $this->actual_cost_source_detail,
			'actual_cost_updated_at' => $this->actual_cost_updated_at,
			'difference_kopecks' => $this->difference_kopecks,
			'difference_percent_basis_points' => $this->difference_percent_basis_points,
			'threshold_status' => $this->threshold_status,
			'indexed_at' => $this->indexed_at,
		);
	}
}

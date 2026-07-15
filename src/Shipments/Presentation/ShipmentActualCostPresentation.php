<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Presentation;

defined( 'ABSPATH' ) || exit;

final readonly class ShipmentActualCostPresentation {
	public function __construct(
		public ?int $actual_cost_kopecks,
		public string $actual_cost_label,
		public string $actual_cost_compare_status,
		public string $actual_cost_compare_message
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'actual_cost_kopecks' => $this->actual_cost_kopecks,
			'actual_cost_label' => $this->actual_cost_label,
			'actual_cost_compare_status' => $this->actual_cost_compare_status,
			'actual_cost_compare_message' => $this->actual_cost_compare_message,
		);
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

defined( 'ABSPATH' ) || exit;

final class ShipmentActualCost {
	public function __construct(
		public readonly int $amount_kopecks,
		public readonly string $currency = 'RUB',
		public readonly string $source = 'carrier_api',
		public readonly string $source_detail = '',
		public readonly string $updated_at = ''
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_fields( string $fallback_updated_at ): array {
		return array(
			'actual_cost_kopecks' => max( 0, $this->amount_kopecks ),
			'actual_cost_currency' => '' !== trim( $this->currency ) ? strtoupper( trim( $this->currency ) ) : 'RUB',
			'actual_cost_source' => '' !== trim( $this->source ) ? trim( $this->source ) : 'carrier_api',
			'actual_cost_source_detail' => trim( $this->source_detail ),
			'actual_cost_updated_at' => '' !== trim( $this->updated_at ) ? trim( $this->updated_at ) : $fallback_updated_at,
		);
	}
}

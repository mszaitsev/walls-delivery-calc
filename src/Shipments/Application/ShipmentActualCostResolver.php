<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Shipments\Presentation\ShipmentActualCostComparisonService;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;

defined( 'ABSPATH' ) || exit;

final class ShipmentActualCostResolver {
	public function __construct(
		private ShipmentActualCostComparisonService $comparison,
		private ShipmentBaseApiCostResolver $base_costs
	) {
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	public function amount_kopecks( array $shipment ): ?int {
		return $this->positive_int_or_null( $shipment['actual_cost_kopecks'] ?? null );
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	public function presentation_payload( array $shipment, ?object $order ): array {
		$actual_kopecks = $this->amount_kopecks( $shipment );
		$base_kopecks = $this->base_costs->resolve_from_order( $order );
		$payload = $this->comparison->compare( $actual_kopecks, $base_kopecks )->to_array();
		$source = (string) ( $shipment['actual_cost_source'] ?? '' );

		return $payload + array(
			'base_api_cost_kopecks' => null === $actual_kopecks ? null : $base_kopecks,
			'actual_cost_source' => $source,
			'actual_cost_source_label' => $this->source_label( $source ),
			'actual_cost_updated_at' => (string) ( $shipment['actual_cost_updated_at'] ?? '' ),
			'actual_cost_is_manual' => 'manual' === $source,
		);
	}

	private function source_label( string $source ): string {
		$labels = array(
			'manual' => 'введено вручную',
			'carrier_api' => 'API перевозчика',
			'carrier_status' => 'API перевозчика',
			'carrier_reconciliation' => 'сверка перевозчика',
		);
		$label = $labels[ $source ] ?? ( '' !== $source ? $source : '' );

		return match ( $source ) {
			'manual', 'carrier_api', 'carrier_status', 'carrier_reconciliation' => function_exists( '__' ) ? __( $label, 'walls-delivery-calc' ) : $label,
			default => '' !== $source ? $source : '',
		};
	}

	private function positive_int_or_null( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) {
			$integer = (int) $value;

			return $integer > 0 ? $integer : null;
		}

		return null;
	}
}

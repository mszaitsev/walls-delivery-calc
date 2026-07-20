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
		foreach ( array( 'actual_cost_kopecks', 'russian_post_actual_cost_kopecks' ) as $key ) {
			$value = $this->non_negative_int_or_null( $shipment[ $key ] ?? null );
			if ( null !== $value ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	public function presentation_payload( array $shipment, ?object $order ): array {
		$actual_kopecks = $this->amount_kopecks( $shipment );
		$base_kopecks = $this->base_costs->resolve_from_order( $order );
		$payload = $this->comparison->compare( $actual_kopecks, $base_kopecks )->to_array();
		$source = (string) ( $shipment['actual_cost_source'] ?? $shipment['russian_post_actual_cost_source'] ?? '' );

		return $payload + array(
			'base_api_cost_kopecks' => null === $actual_kopecks ? null : $base_kopecks,
			'actual_cost_source' => $source,
			'actual_cost_source_label' => $this->source_label( $source ),
			'actual_cost_updated_at' => (string) ( $shipment['actual_cost_updated_at'] ?? '' ),
			'actual_cost_is_manual' => 'manual' === $source,
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	public function with_legacy_canonical_fields( array $shipment, string $updated_at ): array {
		if ( array_key_exists( 'actual_cost_kopecks', $shipment ) ) {
			return $shipment;
		}
		$legacy = $this->non_negative_int_or_null( $shipment['russian_post_actual_cost_kopecks'] ?? null );
		if ( null === $legacy ) {
			return $shipment;
		}

		$shipment['actual_cost_kopecks'] = $legacy;
		$shipment['actual_cost_currency'] = 'RUB';
		$shipment['actual_cost_source'] = 'legacy_import';
		$shipment['actual_cost_source_detail'] = (string) ( $shipment['russian_post_actual_cost_source'] ?? '' );
		$shipment['actual_cost_updated_at'] = $updated_at;

		return $shipment;
	}

	private function source_label( string $source ): string {
		$labels = array(
			'manual' => 'введено вручную',
			'carrier_api' => 'API перевозчика',
			'carrier_status' => 'API перевозчика',
			'carrier_reconciliation' => 'сверка перевозчика',
			'legacy_import' => 'legacy import',
		);
		$label = $labels[ $source ] ?? ( '' !== $source ? $source : '' );

		return match ( $source ) {
			'manual', 'carrier_api', 'carrier_status', 'carrier_reconciliation', 'legacy_import' => function_exists( '__' ) ? __( $label, 'walls-delivery-calc' ) : $label,
			default => '' !== $source ? $source : '',
		};
	}

	private function non_negative_int_or_null( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) {
			$integer = (int) $value;

			return $integer >= 0 ? $integer : null;
		}

		return null;
	}
}

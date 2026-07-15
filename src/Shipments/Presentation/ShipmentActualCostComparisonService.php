<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Presentation;

defined( 'ABSPATH' ) || exit;

final class ShipmentActualCostComparisonService {
	public function compare( ?int $actual_cost_kopecks, ?int $base_cost_kopecks ): ShipmentActualCostPresentation {
		if ( null === $actual_cost_kopecks ) {
			return new ShipmentActualCostPresentation( null, '', '', '' );
		}

		if ( $actual_cost_kopecks < 0 ) {
			throw new \InvalidArgumentException( 'Actual shipment cost must be a non-negative integer in kopecks.' );
		}
		if ( null !== $base_cost_kopecks && $base_cost_kopecks < 0 ) {
			throw new \InvalidArgumentException( 'Base shipment cost must be a non-negative integer in kopecks.' );
		}

		$status = 'neutral';
		$message = 'нет базовой стоимости для сравнения';
		if ( null !== $base_cost_kopecks && $base_cost_kopecks > 0 ) {
			$status = $actual_cost_kopecks * 100 <= $base_cost_kopecks * 103 ? 'ok' : 'warning';
			$message = 'Базовая стоимость API: ' . $this->format_rubles( $base_cost_kopecks ) . ' руб.';
		}

		return new ShipmentActualCostPresentation(
			$actual_cost_kopecks,
			$this->format_rubles( $actual_cost_kopecks ) . ' руб.',
			$status,
			$message
		);
	}

	public function format_rubles( int $kopecks ): string {
		if ( $kopecks < 0 ) {
			throw new \InvalidArgumentException( 'Shipment cost must be a non-negative integer in kopecks.' );
		}

		return number_format( $kopecks / 100, 2, '.', '' );
	}
}

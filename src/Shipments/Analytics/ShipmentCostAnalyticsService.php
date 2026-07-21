<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsService {
	public function __construct(
		private ShipmentCostAnalyticsQuery $query,
		private OrderShipmentRepository $shipments,
		private ShipmentBaseApiCostResolver $base_costs,
		private CarrierRegistry $carriers,
		private ShipmentCostThresholdPolicy $threshold
	) {
	}

	/**
	 * @return array<string,string>
	 */
	public function carrier_options(): array {
		$options = array();
		foreach ( $this->carriers->all() as $key => $adapter ) {
			$identity = $adapter->get_identity();
			$options[ (string) $key ] = $identity->name;
		}
		asort( $options );

		return $options;
	}

	public function result( ShipmentCostAnalyticsFilter $filter ): ShipmentCostAnalyticsResult {
		$rows = $this->all_rows( $filter );
		$this->sort_rows( $rows, $filter );
		$summary = $this->summary( $rows );
		$total = count( $rows );
		$total_pages = max( 1, (int) ceil( $total / $filter->per_page ) );
		$page = min( $filter->page, $total_pages );
		$offset = ( $page - 1 ) * $filter->per_page;

		return new ShipmentCostAnalyticsResult(
			array_slice( $rows, $offset, $filter->per_page ),
			$summary,
			$total,
			$total_pages
		);
	}

	/**
	 * @return array<int,ShipmentCostAnalyticsRow>
	 */
	private function all_rows( ShipmentCostAnalyticsFilter $filter ): array {
		$rows = array();
		$carrier_options = $this->carrier_options();
		foreach ( $this->query->orders( $filter ) as $order ) {
			if ( ! $this->matches_order_search( $order, $filter->order_search ) ) {
				continue;
			}
			$base_cost = $this->base_costs->resolve_from_order( $order );
			foreach ( $this->shipments->all_for_order( $order ) as $shipment_key => $shipment ) {
				if ( ! is_array( $shipment ) || ! $this->is_created_shipment( $shipment ) ) {
					continue;
				}
				$carrier_key = trim( (string) ( $shipment['carrier_key'] ?? $shipment_key ) );
				if ( null !== $filter->carrier_key && $carrier_key !== $filter->carrier_key ) {
					continue;
				}
				$actual = $this->positive_int_or_null( $shipment['actual_cost_kopecks'] ?? null );
				if ( ! $filter->include_missing_actual && null === $actual ) {
					continue;
				}
				$difference = null !== $base_cost && null !== $actual && $base_cost > 0 ? $actual - $base_cost : null;
				$percent = null !== $difference && null !== $base_cost && $base_cost > 0 ? intdiv( $difference * 10000, $base_cost ) : null;
				$status = $this->threshold->classify( $base_cost, $actual );
				$rows[] = new ShipmentCostAnalyticsRow(
					$this->order_id( $order ),
					$this->order_number( $order ),
					$this->order_created_at( $order ),
					$carrier_key,
					$carrier_options[ $carrier_key ] ?? $carrier_key,
					(string) ( $shipment['service_key'] ?? '' ),
					(string) ( $shipment['service_title'] ?? '' ),
					$base_cost,
					$actual,
					(string) ( $shipment['actual_cost_source'] ?? '' ),
					(string) ( $shipment['actual_cost_source_detail'] ?? '' ),
					$difference,
					$percent,
					$status,
					$this->order_edit_url( $order )
				);
			}
		}

		return $rows;
	}

	/**
	 * @param array<int,ShipmentCostAnalyticsRow> $rows
	 */
	private function sort_rows( array &$rows, ShipmentCostAnalyticsFilter $filter ): void {
		usort(
			$rows,
			function ( ShipmentCostAnalyticsRow $a, ShipmentCostAnalyticsRow $b ) use ( $filter ): int {
				$result = $this->compare_value( $this->sort_value( $a, $filter->orderby ), $this->sort_value( $b, $filter->orderby ), $filter->order );
				if ( 0 === $result ) {
					$result = $this->compare_value( $b->order_created_at, $a->order_created_at, 'asc' );
				}
				if ( 0 === $result ) {
					$result = $b->order_id <=> $a->order_id;
				}

				return $result;
			}
		);
	}

	private function sort_value( ShipmentCostAnalyticsRow $row, string $orderby ): mixed {
		return match ( $orderby ) {
			'order_number' => $row->order_number,
			'carrier' => $row->carrier_title,
			'base' => $row->base_api_cost_kopecks,
			'actual' => $row->actual_cost_kopecks,
			'difference' => $row->difference_kopecks,
			'difference_percent' => $row->difference_percent_basis_points,
			default => $row->order_created_at,
		};
	}

	private function compare_value( mixed $a, mixed $b, string $direction ): int {
		if ( null === $a && null === $b ) {
			return 0;
		}
		if ( null === $a ) {
			return 1;
		}
		if ( null === $b ) {
			return -1;
		}
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			$result = (int) $a <=> (int) $b;
		} else {
			$result = strnatcasecmp( (string) $a, (string) $b );
		}

		return 'desc' === $direction ? -$result : $result;
	}

	/**
	 * @param array<int,ShipmentCostAnalyticsRow> $rows
	 */
	private function summary( array $rows ): ShipmentCostAnalyticsSummary {
		$shipment_count = count( $rows );
		$with_actual = 0;
		$planned_total = 0;
		$actual_total = 0;
		$comparable_planned = 0;
		$difference_total = 0;
		$percent_total = 0;
		$comparable_count = 0;
		$over = 0;

		foreach ( $rows as $row ) {
			if ( null !== $row->base_api_cost_kopecks && $row->base_api_cost_kopecks > 0 ) {
				$planned_total += $row->base_api_cost_kopecks;
			}
			if ( null !== $row->actual_cost_kopecks && $row->actual_cost_kopecks > 0 ) {
				++$with_actual;
				$actual_total += $row->actual_cost_kopecks;
			}
			if ( null !== $row->difference_kopecks && null !== $row->difference_percent_basis_points && null !== $row->base_api_cost_kopecks ) {
				$comparable_planned += $row->base_api_cost_kopecks;
				$difference_total += $row->difference_kopecks;
				$percent_total += $row->difference_percent_basis_points;
				++$comparable_count;
			}
			if ( ShipmentCostThresholdPolicy::STATUS_OVER_THRESHOLD === $row->threshold_status ) {
				++$over;
			}
		}

		return new ShipmentCostAnalyticsSummary(
			$shipment_count,
			$with_actual,
			$shipment_count - $with_actual,
			$planned_total,
			$actual_total,
			$comparable_planned,
			$difference_total,
			$comparable_count > 0 ? intdiv( $percent_total, $comparable_count ) : null,
			$over,
			$comparable_count
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	private function is_created_shipment( array $shipment ): bool {
		foreach ( array( 'tracking_number', 'barcode', 'external_id', 'carrier_shipment_id', 'shipment_id', 'uuid', 'order_uuid' ) as $key ) {
			if ( '' !== trim( (string) ( $shipment[ $key ] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	private function matches_order_search( object $order, string $search ): bool {
		if ( '' === trim( $search ) ) {
			return true;
		}
		$number = $this->order_number( $order );
		$id = (string) $this->order_id( $order );

		return $search === $number || $search === $id;
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

	private function order_id( object $order ): int {
		return method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
	}

	private function order_number( object $order ): string {
		return method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $this->order_id( $order );
	}

	private function order_created_at( object $order ): string {
		if ( method_exists( $order, 'get_date_created' ) ) {
			$date = $order->get_date_created();
			if ( is_object( $date ) && method_exists( $date, 'date' ) ) {
				return (string) $date->date( 'Y-m-d H:i:s' );
			}
			if ( $date instanceof \DateTimeInterface ) {
				return $date->format( 'Y-m-d H:i:s' );
			}
		}

		return '';
	}

	private function order_edit_url( object $order ): string {
		foreach ( array( 'get_edit_order_url', 'get_edit_url' ) as $method ) {
			if ( method_exists( $order, $method ) ) {
				return (string) $order->{$method}();
			}
		}

		return '';
	}
}

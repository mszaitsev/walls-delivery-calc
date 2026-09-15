<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

use WallsShop\WDC\Shipments\Analytics\Storage\ShipmentCostAnalyticsRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsQuery {
	public function __construct(
		private ShipmentCostAnalyticsRepository $repository
	) {
	}

	/**
	 * @param array<string,string> $carrier_titles
	 */
	public function result( ShipmentCostAnalyticsFilter $filter, array $carrier_titles ): ShipmentCostAnalyticsResult {
		$where = $this->where_clause( $filter );
		$total = $this->count( $where );
		$total_pages = max( 1, (int) ceil( $total / $filter->per_page ) );
		$current_page = min( $filter->page, $total_pages );
		$offset = ( $current_page - 1 ) * $filter->per_page;
		$rows = $this->rows( $filter, $where, $offset, $carrier_titles );

		return new ShipmentCostAnalyticsResult(
			$rows,
			$this->summary( $where ),
			$total,
			$total_pages,
			$current_page
		);
	}

	/**
	 * @param array{sql:string,params:array<int,mixed>} $where
	 */
	private function count( array $where ): int {
		$value = $this->repository->get_var( 'SELECT COUNT(*) FROM ' . $this->repository->table_name() . $where['sql'], $where['params'] );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * @param array{sql:string,params:array<int,mixed>} $where
	 */
	private function summary( array $where ): ShipmentCostAnalyticsSummary {
		$row = $this->repository->get_results(
			'SELECT COUNT(*) AS shipment_count,
SUM(CASE WHEN actual_cost_kopecks IS NOT NULL AND actual_cost_kopecks > 0 THEN 1 ELSE 0 END) AS with_actual_count,
SUM(CASE WHEN actual_cost_kopecks IS NULL OR actual_cost_kopecks <= 0 THEN 1 ELSE 0 END) AS without_actual_count,
SUM(CASE WHEN base_api_cost_kopecks IS NOT NULL AND base_api_cost_kopecks > 0 THEN base_api_cost_kopecks ELSE 0 END) AS planned_total_kopecks,
SUM(CASE WHEN actual_cost_kopecks IS NOT NULL AND actual_cost_kopecks > 0 THEN actual_cost_kopecks ELSE 0 END) AS actual_total_kopecks,
SUM(CASE WHEN difference_kopecks IS NOT NULL THEN base_api_cost_kopecks ELSE 0 END) AS comparable_planned_total_kopecks,
SUM(CASE WHEN difference_kopecks IS NOT NULL THEN difference_kopecks ELSE 0 END) AS difference_total_kopecks,
AVG(difference_percent_basis_points) AS average_difference_percent_basis_points,
SUM(CASE WHEN threshold_status = \'over_threshold\' THEN 1 ELSE 0 END) AS over_threshold_count,
SUM(CASE WHEN difference_percent_basis_points IS NOT NULL THEN 1 ELSE 0 END) AS comparable_count
FROM ' . $this->repository->table_name() . $where['sql'],
			$where['params']
		);
		$data = $row[0] ?? array();

		return new ShipmentCostAnalyticsSummary(
			$this->int_value( $data['shipment_count'] ?? 0 ),
			$this->int_value( $data['with_actual_count'] ?? 0 ),
			$this->int_value( $data['without_actual_count'] ?? 0 ),
			$this->int_value( $data['planned_total_kopecks'] ?? 0 ),
			$this->int_value( $data['actual_total_kopecks'] ?? 0 ),
			$this->int_value( $data['comparable_planned_total_kopecks'] ?? 0 ),
			$this->int_value( $data['difference_total_kopecks'] ?? 0 ),
			null !== ( $data['average_difference_percent_basis_points'] ?? null ) ? $this->int_value( $data['average_difference_percent_basis_points'] ) : null,
			$this->int_value( $data['over_threshold_count'] ?? 0 ),
			$this->int_value( $data['comparable_count'] ?? 0 )
		);
	}

	/**
	 * @param array{sql:string,params:array<int,mixed>} $where
	 * @param array<string,string> $carrier_titles
	 * @return array<int,ShipmentCostAnalyticsRow>
	 */
	private function rows( ShipmentCostAnalyticsFilter $filter, array $where, int $offset, array $carrier_titles ): array {
		$params = array_merge( $where['params'], array( $filter->per_page, $offset ) );
		$records = $this->repository->get_results(
			'SELECT order_id, order_number, order_created_at, carrier_key, service_key, service_title, base_api_cost_kopecks, actual_cost_kopecks, actual_cost_source, actual_cost_source_detail, difference_kopecks, difference_percent_basis_points, threshold_status
FROM ' . $this->repository->table_name() . $where['sql'] . $this->order_by( $filter ) . ' LIMIT %d OFFSET %d',
			$params
		);
		$rows = array();
		foreach ( $records as $record ) {
			$carrier_key = (string) ( $record['carrier_key'] ?? '' );
			$rows[] = new ShipmentCostAnalyticsRow(
				$this->int_value( $record['order_id'] ?? 0 ),
				(string) ( $record['order_number'] ?? '' ),
				(string) ( $record['order_created_at'] ?? '' ),
				$carrier_key,
				$carrier_titles[ $carrier_key ] ?? $carrier_key,
				(string) ( $record['service_key'] ?? '' ),
				(string) ( $record['service_title'] ?? '' ),
				$this->nullable_int( $record['base_api_cost_kopecks'] ?? null ),
				$this->nullable_int( $record['actual_cost_kopecks'] ?? null ),
				(string) ( $record['actual_cost_source'] ?? '' ),
				(string) ( $record['actual_cost_source_detail'] ?? '' ),
				$this->nullable_int( $record['difference_kopecks'] ?? null ),
				$this->nullable_int( $record['difference_percent_basis_points'] ?? null ),
				(string) ( $record['threshold_status'] ?? ShipmentCostThresholdPolicy::STATUS_NOT_COMPARABLE ),
				$this->order_edit_url( $this->int_value( $record['order_id'] ?? 0 ) )
			);
		}

		return $rows;
	}

	/**
	 * @return array{sql:string,params:array<int,mixed>}
	 */
	private function where_clause( ShipmentCostAnalyticsFilter $filter ): array {
		$where = array( 'order_created_at >= %s', 'order_created_at <= %s' );
		$params = array( $this->local_to_utc( $filter->date_from_start() ), $this->local_to_utc( $filter->date_to_end() ) );
		if ( null !== $filter->carrier_key ) {
			$where[] = 'carrier_key = %s';
			$params[] = $filter->carrier_key;
		}
		if ( ! $filter->include_missing_actual ) {
			$where[] = 'actual_cost_kopecks IS NOT NULL';
			$where[] = 'actual_cost_kopecks > 0';
		}
		if ( '' !== $filter->order_search ) {
			if ( ctype_digit( $filter->order_search ) ) {
				$where[] = '(order_id = %d OR order_number = %s)';
				$params[] = (int) $filter->order_search;
				$params[] = $filter->order_search;
			} else {
				$where[] = 'order_number = %s';
				$params[] = $filter->order_search;
			}
		}

		return array(
			'sql' => ' WHERE ' . implode( ' AND ', $where ),
			'params' => $params,
		);
	}

	private function order_by( ShipmentCostAnalyticsFilter $filter ): string {
		$direction = 'asc' === $filter->order ? 'ASC' : 'DESC';
		$column = match ( $filter->orderby ) {
			'order_number' => 'order_number',
			'carrier' => 'carrier_key',
			'base' => 'base_api_cost_kopecks',
			'actual' => 'actual_cost_kopecks',
			'difference' => 'difference_kopecks',
			'difference_percent' => 'difference_percent_basis_points',
			default => 'order_created_at',
		};
		if ( in_array( $column, array( 'base_api_cost_kopecks', 'actual_cost_kopecks', 'difference_kopecks', 'difference_percent_basis_points' ), true ) ) {
			return " ORDER BY {$column} IS NULL ASC, {$column} {$direction}, order_created_at DESC, order_id DESC";
		}
		if ( 'order_number' === $column ) {
			return " ORDER BY order_number {$direction}, order_id {$direction}";
		}
		if ( 'carrier_key' === $column ) {
			return " ORDER BY carrier_key {$direction}, order_created_at DESC, order_id DESC";
		}

		return " ORDER BY order_created_at {$direction}, order_id {$direction}";
	}

	private function local_to_utc( string $datetime ): string {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $datetime, $timezone );
		if ( ! $date instanceof \DateTimeImmutable ) {
			return $datetime;
		}

		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	private function order_edit_url( int $order_id ): string {
		if ( $order_id <= 0 ) {
			return '';
		}
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( is_object( $order ) ) {
				foreach ( array( 'get_edit_order_url', 'get_edit_url' ) as $method ) {
					if ( method_exists( $order, $method ) ) {
						return (string) $order->{$method}();
					}
				}
			}
		}
		if ( function_exists( 'admin_url' ) ) {
			return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		}

		return '';
	}

	private function int_value( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	private function nullable_int( mixed $value ): ?int {
		return null !== $value && is_numeric( $value ) ? (int) $value : null;
	}
}

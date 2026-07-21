<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics\Storage;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsRepository {
	public function __construct(
		private ShipmentCostAnalyticsTable $table
	) {
	}

	public function upsert( ShipmentCostAnalyticsRecord $record ): void {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
			return;
		}
		$data = $record->to_array();
		$columns = array_keys( $data );
		$placeholders = array_map( fn( string $column ): string => $this->placeholder_for( $column ), $columns );
		$updates = array();
		foreach ( $columns as $column ) {
			if ( 'order_id' === $column ) {
				continue;
			}
			$updates[] = "{$column} = VALUES({$column})";
		}
		$query = 'INSERT INTO ' . $this->table->name() . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ') ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );
		$wpdb->query( $wpdb->prepare( $query, array_values( $data ) ) );
	}

	public function delete_by_order_id( int $order_id ): void {
		global $wpdb;
		if ( $order_id <= 0 || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table->name() . ' WHERE order_id = %d', $order_id ) );
	}

	/**
	 * @param array<int,mixed> $params
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $sql, array $params = array() ): array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return array();
		}
		$query = array() !== $params && method_exists( $wpdb, 'prepare' ) ? $wpdb->prepare( $sql, $params ) : $sql;
		$output = defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
		$rows = $wpdb->get_results( $query, $output );

		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * @param array<int,mixed> $params
	 */
	public function get_var( string $sql, array $params = array() ): mixed {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return null;
		}
		$query = array() !== $params && method_exists( $wpdb, 'prepare' ) ? $wpdb->prepare( $sql, $params ) : $sql;

		return $wpdb->get_var( $query );
	}

	public function table_name(): string {
		return $this->table->name();
	}

	private function placeholder_for( string $column ): string {
		return in_array( $column, array( 'order_id', 'base_api_cost_kopecks', 'actual_cost_kopecks', 'difference_kopecks', 'difference_percent_basis_points' ), true )
			? '%d'
			: '%s';
	}
}

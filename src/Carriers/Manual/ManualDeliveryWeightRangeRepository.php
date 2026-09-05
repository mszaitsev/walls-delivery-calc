<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Manual;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class ManualDeliveryWeightRangeRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	/**
	 * @return array<int,ManualDeliveryWeightRange>
	 */
	public function ranges( int $service_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT from_weight_g, to_weight_g, price_kopecks, sort_order FROM {$this->table()} WHERE service_id = %d ORDER BY from_weight_g ASC, to_weight_g ASC, sort_order ASC", $service_id ),
			ARRAY_A
		);

		return $this->normalize_ranges( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @param array<int,array<string,mixed>|ManualDeliveryWeightRange> $ranges
	 */
	public function replace_ranges( int $service_id, array $ranges ): void {
		$normalized = $this->normalize_ranges( $ranges );
		$this->validate_ranges( $normalized );

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$this->checked_delete( $service_id );
			$table = $this->table();
			$now = current_time( 'mysql' );
			foreach ( $normalized as $index => $range ) {
				$result = $this->wpdb->insert(
					$table,
					array(
						'service_id' => $service_id,
						'from_weight_g' => $range->from_weight_g,
						'to_weight_g' => $range->to_weight_g,
						'price_kopecks' => $range->price_kopecks,
						'sort_order' => $index + 1,
						'created_at' => $now,
						'updated_at' => $now,
					),
					array( '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
				);
				if ( false === $result ) {
					throw new RuntimeException( 'Failed to save manual delivery weight range.' );
				}
			}
			$this->wpdb->query( 'COMMIT' );
		} catch ( \Throwable $exception ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	public function clear( int $service_id ): void {
		$this->checked_delete( $service_id );
	}

	/**
	 * @param array<int,array<string,mixed>|ManualDeliveryWeightRange> $ranges
	 * @return array<int,ManualDeliveryWeightRange>
	 */
	public function normalize_ranges( array $ranges ): array {
		$result = array();
		foreach ( $ranges as $index => $range ) {
			if ( $range instanceof ManualDeliveryWeightRange ) {
				$result[] = $range;
				continue;
			}
			if ( ! is_array( $range ) ) {
				continue;
			}
			$result[] = new ManualDeliveryWeightRange(
				(int) ( $range['from_weight_g'] ?? 0 ),
				(int) ( $range['to_weight_g'] ?? 0 ),
				(int) ( $range['price_kopecks'] ?? -1 ),
				(int) ( $range['sort_order'] ?? $index + 1 )
			);
		}
		usort( $result, static fn( ManualDeliveryWeightRange $left, ManualDeliveryWeightRange $right ): int => ( $left->from_weight_g <=> $right->from_weight_g ) ?: ( $left->to_weight_g <=> $right->to_weight_g ) );

		return $result;
	}

	/**
	 * @param array<int,ManualDeliveryWeightRange> $ranges
	 */
	public function validate_ranges( array $ranges ): void {
		$seen = array();
		$previous_to = null;
		foreach ( $ranges as $index => $range ) {
			$row = $index + 1;
			if ( $range->from_weight_g < 0 || $range->to_weight_g < 0 ) {
				throw new \InvalidArgumentException( 'manual_weight_range_negative_weight' );
			}
			if ( $range->to_weight_g <= $range->from_weight_g ) {
				throw new \InvalidArgumentException( 'manual_weight_range_to_lte_from' );
			}
			if ( $range->price_kopecks < 0 ) {
				throw new \InvalidArgumentException( 'manual_weight_range_price_invalid' );
			}
			$key = $range->from_weight_g . '|' . $range->to_weight_g;
			if ( isset( $seen[ $key ] ) ) {
				throw new \InvalidArgumentException( 'manual_weight_range_duplicate' );
			}
			$seen[ $key ] = true;
			if ( null !== $previous_to && $range->from_weight_g < $previous_to ) {
				throw new \InvalidArgumentException( 'manual_weight_range_overlap' );
			}
			$previous_to = $range->to_weight_g;
			unset( $row );
		}
	}

	private function checked_delete( int $service_id ): void {
		$result = $this->wpdb->delete( $this->table(), array( 'service_id' => $service_id ), array( '%d' ) );
		if ( false === $result ) {
			throw new RuntimeException( 'Failed to clear manual delivery weight ranges.' );
		}
	}

	private function table(): string {
		return $this->wpdb->prefix . 'wdc_manual_delivery_weight_ranges';
	}
}

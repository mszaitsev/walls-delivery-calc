<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsQuery {
	/**
	 * @return iterable<int,array<int,object>>
	 */
	public function batches( ShipmentCostAnalyticsFilter $filter, int $batch_size = 200 ): iterable {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$batch_size = max( 1, min( 500, $batch_size ) );
		$page = 1;
		$max_pages = null;
		$iterations = 0;
		$max_iterations = 10000;

		while ( $iterations < $max_iterations ) {
			++$iterations;
			$result = wc_get_orders(
				array(
					'date_created' => $filter->date_from_start() . '...' . $filter->date_to_end(),
					'limit' => $batch_size,
					'page' => $page,
					'paginate' => true,
					'return' => 'ids',
					'orderby' => 'date',
					'order' => 'DESC',
				)
			);

			$ids = $this->order_ids_from_result( $result );
			$result_max_pages = $this->max_pages_from_result( $result );
			if ( null !== $result_max_pages ) {
				$max_pages = $result_max_pages;
			}
			if ( array() === $ids ) {
				break;
			}

			$orders = $this->orders_from_ids( $ids );
			if ( array() !== $orders ) {
				yield $orders;
			}

			if ( null !== $max_pages && $page >= $max_pages ) {
				break;
			}
			if ( null === $max_pages && count( $ids ) < $batch_size ) {
				break;
			}
			++$page;
		}
	}

	/**
	 * @return array<int,int>
	 */
	private function order_ids_from_result( mixed $result ): array {
		$orders = is_object( $result ) && isset( $result->orders ) ? $result->orders : $result;
		if ( ! is_array( $orders ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( mixed $order ): int {
						if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
							return (int) $order->get_id();
						}

						return is_numeric( $order ) ? (int) $order : 0;
					},
					$orders
				),
				static fn( int $id ): bool => $id > 0
			)
		);
	}

	private function max_pages_from_result( mixed $result ): ?int {
		if ( is_object( $result ) && isset( $result->max_num_pages ) && is_numeric( $result->max_num_pages ) ) {
			return max( 0, (int) $result->max_num_pages );
		}

		return null;
	}

	/**
	 * @param array<int,int> $ids
	 * @return array<int,object>
	 */
	private function orders_from_ids( array $ids ): array {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array();
		}
		$orders = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( is_object( $order ) ) {
				$orders[] = $order;
			}
		}

		return $orders;
	}
}

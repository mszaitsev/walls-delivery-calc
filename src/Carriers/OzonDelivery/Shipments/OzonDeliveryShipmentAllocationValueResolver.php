<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

use WallsShop\WDC\Domain\Common\MoneyParser;
use WallsShop\WDC\Domain\Package\ShipmentPlace;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentAllocationValueResolver {
	/**
	 * @param array<int,array<string,mixed>> $item_rows
	 * @param array<int,ShipmentPlace> $places
	 * @return array{place_values:array<int,int>,errors:array<int,string>,summary:array<string,mixed>}
	 */
	public function resolve( object $order, array $item_rows, array $places ): array {
		$place_numbers = array();
		foreach ( $places as $place ) {
			if ( $place instanceof ShipmentPlace && $place->place_number > 0 ) {
				$place_numbers[ $place->place_number ] = true;
			}
		}
		$errors = array();
		$place_values = array_fill_keys( array_keys( $place_numbers ), 0 );
		$order_items = $this->order_items( $order );
		$assigned = array();
		$rows_by_item = array();
		foreach ( $item_rows as $row ) {
			$item_id = $this->item_id( $row['item_key'] ?? '' );
			$place_number = (int) ( $row['place_number'] ?? 0 );
			$quantity = (int) ( $row['amount'] ?? 0 );
			if ( $item_id <= 0 || ! isset( $order_items[ $item_id ] ) ) {
				$errors[] = 'В распределении Ozon указан неизвестный товар заказа.';
				continue;
			}
			if ( $quantity <= 0 ) {
				$errors[] = 'Количество товара в грузоместе Ozon должно быть больше нуля.';
				continue;
			}
			if ( ! isset( $place_numbers[ $place_number ] ) ) {
				$errors[] = 'Товар Ozon назначен в несуществующее грузоместо.';
				continue;
			}
			$assigned[ $item_id ] = ( $assigned[ $item_id ] ?? 0 ) + $quantity;
			$rows_by_item[ $item_id ][] = array( 'place_number' => $place_number, 'quantity' => $quantity );
		}
		foreach ( $order_items as $item_id => $item ) {
			$qty = (int) $item['quantity'];
			$actual = (int) ( $assigned[ $item_id ] ?? 0 );
			if ( $actual !== $qty ) {
				$errors[] = sprintf( 'Товар заказа %d распределён по грузоместам Ozon некорректно.', $item_id );
			}
		}
		if ( array() !== $errors ) {
			return array( 'place_values' => $place_values, 'errors' => array_values( array_unique( $errors ) ), 'summary' => array() );
		}
		foreach ( $rows_by_item as $item_id => $rows ) {
			$item = $order_items[ $item_id ];
			$total_kopecks = (int) $item['total_kopecks'];
			$total_qty = (int) $item['quantity'];
			$cumulative = 0;
			$previous_value = 0;
			foreach ( $rows as $row ) {
				$cumulative += (int) $row['quantity'];
				$current_value = intdiv( $total_kopecks * $cumulative, $total_qty );
				$share = $current_value - $previous_value;
				$previous_value = $current_value;
				$place_values[ (int) $row['place_number'] ] += $share;
			}
		}
		foreach ( $place_values as $place_number => $kopecks ) {
			if ( $kopecks <= 0 ) {
				$errors[] = sprintf( 'Грузоместо %d Ozon не содержит товаров с объявленной стоимостью.', (int) $place_number );
			}
		}

		return array(
			'place_values' => $place_values,
			'errors' => $errors,
			'summary' => array(
				'order_item_count' => count( $order_items ),
				'assigned_item_row_count' => count( $item_rows ),
				'total_declared_kopecks' => array_sum( $place_values ),
				'value_policy' => 'woocommerce_line_total_excluding_tax_discounted_prorated_by_quantity',
			),
		);
	}

	/** @return array<int,array{quantity:int,total_kopecks:int}> */
	private function order_items( object $order ): array {
		if ( ! method_exists( $order, 'get_items' ) ) {
			return array();
		}
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_id' ) ) {
				continue;
			}
			$id = (int) $item->get_id();
			$quantity = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0;
			$total = method_exists( $item, 'get_total' ) ? (string) $item->get_total() : '0';
			$kopecks = MoneyParser::numeric_to_kopecks( $total );
			if ( $id > 0 && $quantity > 0 && null !== $kopecks ) {
				$items[ $id ] = array( 'quantity' => $quantity, 'total_kopecks' => max( 0, $kopecks ) );
			}
		}

		return $items;
	}

	private function item_id( mixed $value ): int {
		$value = trim( (string) $value );
		if ( str_starts_with( $value, 'order-item-' ) ) {
			$value = substr( $value, 11 );
		}

		return ctype_digit( $value ) ? (int) $value : 0;
	}
}

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
		$invalid_rows = 0;
		foreach ( $item_rows as $row ) {
			$place_number = (int) ( $row['place_number'] ?? 0 );
			$quantity = (int) ( $row['amount'] ?? 0 );
			if ( $quantity <= 0 ) {
				$errors[] = 'Количество товара в грузоместе Ozon должно быть больше нуля.';
				++$invalid_rows;
				continue;
			}
			if ( ! isset( $place_numbers[ $place_number ] ) ) {
				$errors[] = 'Товар Ozon назначен в несуществующее грузоместо.';
				++$invalid_rows;
				continue;
			}
			$unit_price = $this->unit_price_kopecks( $row );
			if ( null === $unit_price || $unit_price < 0 ) {
				$errors[] = sprintf( 'В грузоместе %d указана некорректная цена товара.', $place_number );
				++$invalid_rows;
				continue;
			}
			$place_values[ $place_number ] += $quantity * $unit_price;
		}
		if ( array() !== $errors ) {
			return array(
				'place_values' => $place_values,
				'errors' => array_values( array_unique( $errors ) ),
				'summary' => array(
					'assigned_item_row_count' => count( $item_rows ),
					'invalid_item_row_count' => $invalid_rows,
					'total_declared_kopecks' => array_sum( $place_values ),
					'value_policy' => 'shipment_modal_quantity_times_unit_price',
				),
			);
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
				'assigned_item_row_count' => count( $item_rows ),
				'invalid_item_row_count' => $invalid_rows,
				'total_declared_kopecks' => array_sum( $place_values ),
				'value_policy' => 'shipment_modal_quantity_times_unit_price',
			),
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function unit_price_kopecks( array $row ): ?int {
		if ( array_key_exists( 'unit_price_kopecks', $row ) ) {
			$value = $row['unit_price_kopecks'];
			if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( trim( $value ) ) ) ) {
				return (int) $value;
			}

			return null;
		}

		return MoneyParser::numeric_to_kopecks( (string) ( $row['cost'] ?? '' ) );
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

defined( 'ABSPATH' ) || exit;

final class RussianPostShipmentActualCostExtractor {
	/**
	 * @param array<int,mixed> $orders
	 * @return array<string,mixed>|null
	 */
	public function select_search_result( array $orders, string $barcode ): ?array {
		$rows = array_values( array_filter( $orders, 'is_array' ) );
		if ( array() === $rows ) {
			return null;
		}
		$matches = array_values(
			array_filter(
				$rows,
				fn ( array $row ): bool => $this->row_matches_barcode( $row, $barcode )
			)
		);
		if ( array() !== $matches ) {
			return $matches[0];
		}
		if ( 1 === count( $rows ) ) {
			return $rows[0];
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public function cost_from_row( array $row, string $source ): ?ShipmentActualCost {
		if ( ! array_key_exists( 'total-rate-wo-vat', $row ) || ! array_key_exists( 'total-vat', $row ) ) {
			return null;
		}
		if ( ! is_numeric( $row['total-rate-wo-vat'] ) || ! is_numeric( $row['total-vat'] ) ) {
			return null;
		}

		$cost_kopecks = max( 0, (int) $row['total-rate-wo-vat'] ) + max( 0, (int) $row['total-vat'] );
		if ( $cost_kopecks <= 0 ) {
			return null;
		}

		return new ShipmentActualCost( $cost_kopecks, 'RUB', 'carrier_api', $source );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function row_matches_barcode( array $row, string $barcode ): bool {
		$normalized = $this->normalize_barcode( $barcode );
		foreach ( array( 'barcode', 'mail-id', 'mail_id', 'tracking-number', 'tracking_number' ) as $key ) {
			if ( $normalized === $this->normalize_barcode( (string) ( $row[ $key ] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	private function normalize_barcode( string $barcode ): string {
		return strtoupper( preg_replace( '/\s+/', '', trim( $barcode ) ) ?? '' );
	}
}

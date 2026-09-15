<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Allocation;

/**
 * A quantity of one original order row allocated to one shipment place.
 */
final class ShipmentAllocationItem {
	/**
	 * @param array<string,mixed> $identity Stable source identifiers (order_item_id preferred).
	 */
	public function __construct(
		public readonly string $source_item_id,
		public readonly array $identity,
		public readonly string $name,
		public readonly string $sku,
		public readonly int $quantity,
		public readonly int $unit_price_kopecks,
		public readonly int $assessed_unit_price_kopecks,
		public readonly int $weight_g = 0
	) {
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();
		if ( '' === trim( $this->source_item_id ) ) {
			$errors[] = 'source_item_id is required';
		}
		if ( array() === $this->identity ) {
			$errors[] = 'identity is required';
		}
		if ( $this->quantity <= 0 ) {
			$errors[] = 'quantity must be greater than 0';
		}
		if ( $this->unit_price_kopecks < 0 ) {
			$errors[] = 'unit_price_kopecks must be greater than or equal to 0';
		}
		if ( $this->assessed_unit_price_kopecks < 0 ) {
			$errors[] = 'assessed_unit_price_kopecks must be greater than or equal to 0';
		}
		if ( $this->weight_g < 0 ) {
			$errors[] = 'weight_g must be greater than or equal to 0';
		}

		return $errors;
	}
}

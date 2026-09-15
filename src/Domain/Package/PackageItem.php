<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Package;

use WallsShop\WDC\Domain\Common\Money;

final class PackageItem {
	public function __construct(
		public readonly string $sku,
		public readonly string $name,
		public readonly int $quantity,
		public readonly Money $unit_price,
		public readonly Money $total_price,
		public readonly int $weight_g = 0,
		public readonly int $length_cm = 0,
		public readonly int $width_cm = 0,
		public readonly int $height_cm = 0
	) {
	}

	public function get_total_weight_g(): int {
		return $this->weight_g * $this->quantity;
	}

	public function get_volume_cm3(): int {
		return $this->length_cm * $this->width_cm * $this->height_cm * $this->quantity;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'sku'         => $this->sku,
			'name'        => $this->name,
			'quantity'    => $this->quantity,
			'unit_price'  => $this->unit_price->to_array(),
			'total_price' => $this->total_price->to_array(),
			'weight_g'    => $this->weight_g,
			'length_cm'   => $this->length_cm,
			'width_cm'    => $this->width_cm,
			'height_cm'   => $this->height_cm,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['sku'] ?? '' ),
			(string) ( $data['name'] ?? '' ),
			(int) ( $data['quantity'] ?? 0 ),
			Money::from_array( is_array( $data['unit_price'] ?? null ) ? $data['unit_price'] : array() ),
			Money::from_array( is_array( $data['total_price'] ?? null ) ? $data['total_price'] : array() ),
			(int) ( $data['weight_g'] ?? 0 ),
			(int) ( $data['length_cm'] ?? 0 ),
			(int) ( $data['width_cm'] ?? 0 ),
			(int) ( $data['height_cm'] ?? 0 )
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( $this->quantity <= 0 ) {
			$errors[] = 'quantity must be greater than 0';
		}

		foreach ( array( 'weight_g' => $this->weight_g, 'length_cm' => $this->length_cm, 'width_cm' => $this->width_cm, 'height_cm' => $this->height_cm ) as $field => $value ) {
			if ( $value < 0 ) {
				$errors[] = $field . ' must be greater than or equal to 0';
			}
		}

		return array_merge( $errors, $this->unit_price->validate(), $this->total_price->validate() );
	}
}

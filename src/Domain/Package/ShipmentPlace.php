<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Package;

use WallsShop\WDC\Domain\Common\Money;

final class ShipmentPlace {
	/**
	 * @param array<int,PackageItem> $items
	 */
	public function __construct(
		public readonly int $place_number,
		public readonly int $weight_g,
		public readonly int $length_cm,
		public readonly int $width_cm,
		public readonly int $height_cm,
		public readonly Money $declared_value,
		public readonly array $items = array(),
		public readonly bool $combined_items = false,
		public readonly string $combined_name = ''
	) {
	}

	public function get_volume_cm3(): int {
		return $this->length_cm * $this->width_cm * $this->height_cm;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'place_number'   => $this->place_number,
			'weight_g'       => $this->weight_g,
			'length_cm'      => $this->length_cm,
			'width_cm'       => $this->width_cm,
			'height_cm'      => $this->height_cm,
			'declared_value' => $this->declared_value->to_array(),
			'items'          => array_map( static fn ( PackageItem $item ): array => $item->to_array(), $this->items ),
			'combined_items' => $this->combined_items,
			'combined_name'  => $this->combined_name,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		$items = array_map(
			static fn ( mixed $item ): PackageItem => PackageItem::from_array( is_array( $item ) ? $item : array() ),
			is_array( $data['items'] ?? null ) ? $data['items'] : array()
		);

		return new self(
			(int) ( $data['place_number'] ?? 0 ),
			(int) ( $data['weight_g'] ?? 0 ),
			(int) ( $data['length_cm'] ?? 0 ),
			(int) ( $data['width_cm'] ?? 0 ),
			(int) ( $data['height_cm'] ?? 0 ),
			Money::from_array( is_array( $data['declared_value'] ?? null ) ? $data['declared_value'] : array() ),
			$items,
			(bool) ( $data['combined_items'] ?? false ),
			(string) ( $data['combined_name'] ?? '' )
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( $this->place_number <= 0 ) {
			$errors[] = 'place_number must be greater than 0';
		}

		foreach ( array( 'weight_g' => $this->weight_g, 'length_cm' => $this->length_cm, 'width_cm' => $this->width_cm, 'height_cm' => $this->height_cm ) as $field => $value ) {
			if ( $value <= 0 ) {
				$errors[] = $field . ' must be greater than 0';
			}
		}

		return array_merge( $errors, $this->declared_value->validate() );
	}
}

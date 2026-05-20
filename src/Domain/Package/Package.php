<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Package;

use WallsShop\WDC\Domain\Common\Money;

final class Package {
	/**
	 * @param array<int,PackageItem> $items
	 */
	public function __construct(
		public readonly array $items,
		public readonly Money $declared_value,
		public readonly Money $cart_total,
		public readonly int $weight_g = 0,
		public readonly int $packaging_weight_g = 0,
		public readonly int $total_weight_g = 0,
		public readonly ?int $length_cm = null,
		public readonly ?int $width_cm = null,
		public readonly ?int $height_cm = null,
		public readonly ?int $volume_cm3 = null,
		public readonly string $source = 'cart'
	) {
	}

	/**
	 * @param array<int,PackageItem> $items
	 */
	public static function from_items( array $items, int $packaging_weight_g, Money $cart_total, Money $declared_value ): self {
		$weight_g = 0;
		$volume   = 0;

		foreach ( $items as $item ) {
			if ( $item instanceof PackageItem ) {
				$weight_g += $item->get_total_weight_g();
				$volume   += $item->get_volume_cm3();
			}
		}

		return new self( $items, $declared_value, $cart_total, $weight_g, $packaging_weight_g, $weight_g + $packaging_weight_g, null, null, null, $volume, 'cart' );
	}

	/**
	 * @return array<int,PackageItem>
	 */
	public function get_items(): array {
		return $this->items;
	}

	public function get_total_quantity(): int {
		return array_reduce(
			$this->items,
			static fn ( int $total, PackageItem $item ): int => $total + $item->quantity,
			0
		);
	}

	public function get_total_weight_g(): int {
		return $this->total_weight_g;
	}

	public function get_total_volume_cm3(): int {
		return $this->volume_cm3 ?? array_reduce(
			$this->items,
			static fn ( int $total, PackageItem $item ): int => $total + $item->get_volume_cm3(),
			0
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'items'              => array_map( static fn ( PackageItem $item ): array => $item->to_array(), $this->items ),
			'declared_value'     => $this->declared_value->to_array(),
			'cart_total'         => $this->cart_total->to_array(),
			'weight_g'           => $this->weight_g,
			'packaging_weight_g' => $this->packaging_weight_g,
			'total_weight_g'     => $this->total_weight_g,
			'length_cm'          => $this->length_cm,
			'width_cm'           => $this->width_cm,
			'height_cm'          => $this->height_cm,
			'volume_cm3'         => $this->volume_cm3,
			'source'             => $this->source,
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
			$items,
			Money::from_array( is_array( $data['declared_value'] ?? null ) ? $data['declared_value'] : array() ),
			Money::from_array( is_array( $data['cart_total'] ?? null ) ? $data['cart_total'] : array() ),
			(int) ( $data['weight_g'] ?? 0 ),
			(int) ( $data['packaging_weight_g'] ?? 0 ),
			(int) ( $data['total_weight_g'] ?? 0 ),
			array_key_exists( 'length_cm', $data ) && null !== $data['length_cm'] ? (int) $data['length_cm'] : null,
			array_key_exists( 'width_cm', $data ) && null !== $data['width_cm'] ? (int) $data['width_cm'] : null,
			array_key_exists( 'height_cm', $data ) && null !== $data['height_cm'] ? (int) $data['height_cm'] : null,
			array_key_exists( 'volume_cm3', $data ) && null !== $data['volume_cm3'] ? (int) $data['volume_cm3'] : null,
			(string) ( $data['source'] ?? 'cart' )
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( ! in_array( $this->source, array( 'cart', 'order', 'manual' ), true ) ) {
			$errors[] = 'source must be cart, order, or manual';
		}

		foreach ( array( 'weight_g' => $this->weight_g, 'packaging_weight_g' => $this->packaging_weight_g, 'total_weight_g' => $this->total_weight_g ) as $field => $value ) {
			if ( $value < 0 ) {
				$errors[] = $field . ' must be greater than or equal to 0';
			}
		}

		foreach ( $this->items as $item ) {
			$errors = array_merge( $errors, $item->validate() );
		}

		return array_merge( $errors, $this->declared_value->validate(), $this->cart_total->validate() );
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Packaging;

defined( 'ABSPATH' ) || exit;

final class PackagingParcel {
	public function __construct(
		public readonly int $weight_g,
		public readonly float $length_cm,
		public readonly float $width_cm,
		public readonly float $height_cm,
		public readonly int $quantity = 1
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'weight_g' => $this->weight_g,
			'length_cm' => $this->length_cm,
			'width_cm' => $this->width_cm,
			'height_cm' => $this->height_cm,
			'quantity' => $this->quantity,
		);
	}
}
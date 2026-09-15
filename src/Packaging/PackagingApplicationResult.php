<?php
declare(strict_types=1);

namespace WallsShop\WDC\Packaging;

use WallsShop\WDC\Domain\Package\Package;

defined( 'ABSPATH' ) || exit;

final class PackagingApplicationResult {
	public function __construct(
		public readonly int $original_products_weight_g,
		public readonly int $packaging_weight_g,
		public readonly int $final_package_weight_g,
		public readonly bool $include_packaging_weight,
		public readonly string $packaging_weight_mode,
		public readonly Package $package
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_meta(): array {
		return array(
			'products_weight_g' => $this->original_products_weight_g,
			'packaging_weight_g' => $this->packaging_weight_g,
			'package_weight_with_packaging_g' => $this->final_package_weight_g,
			'include_packaging_weight' => $this->include_packaging_weight,
			'packaging_weight_mode' => $this->packaging_weight_mode,
		);
	}
}

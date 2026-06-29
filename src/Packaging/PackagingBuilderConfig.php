<?php
declare(strict_types=1);

namespace WallsShop\WDC\Packaging;

defined( 'ABSPATH' ) || exit;

final class PackagingBuilderConfig {
	public function __construct(
		public readonly int $default_weight_g,
		public readonly float $default_length_cm,
		public readonly float $default_width_cm,
		public readonly float $default_height_cm,
		public readonly float $default_declared_value_rub
	) {
	}

	public static function defaults(): self {
		return new self( 500, 20.0, 15.0, 10.0, 1.0 );
	}
}

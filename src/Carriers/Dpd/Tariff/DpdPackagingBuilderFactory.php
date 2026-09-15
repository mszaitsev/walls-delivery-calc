<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Tariff;

use WallsShop\WDC\Packaging\PackagingBuilder;
use WallsShop\WDC\Packaging\PackagingBuilderConfig;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;

defined( 'ABSPATH' ) || exit;

final class DpdPackagingBuilderFactory {
	public function __construct(
		private PackagingWeightCalculator $packaging_weight_calculator
	) {
	}

	public function config(): PackagingBuilderConfig {
		return new PackagingBuilderConfig( 1000, 20.0, 20.0, 20.0, 1000.0 );
	}

	public function create(): PackagingBuilder {
		return new PackagingBuilder( $this->config(), $this->packaging_weight_calculator );
	}
}
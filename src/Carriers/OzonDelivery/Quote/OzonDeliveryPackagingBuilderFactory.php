<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Quote;

use WallsShop\WDC\Packaging\PackagingBuilder;
use WallsShop\WDC\Packaging\PackagingBuilderConfig;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryPackagingBuilderFactory {
	public function __construct( private ?PackagingWeightCalculator $packaging_weight_calculator = null ) {
	}

	public function config(): PackagingBuilderConfig {
		return new PackagingBuilderConfig( 500, 20.0, 15.0, 10.0, 1.0, 50.0, 50.0, 30.0 );
	}

	public function create(): PackagingBuilder {
		return new PackagingBuilder( $this->config(), $this->packaging_weight_calculator );
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Manual;

defined( 'ABSPATH' ) || exit;

final class ManualDeliveryPricingService {
	public function __construct(
		private ManualDeliverySettings $settings,
		private ManualDeliveryWeightRangeRepository $ranges,
		private ManualDeliveryPricingCalculator $calculator
	) {
	}

	public function calculate_for_service( int $service_id, int $chargeable_weight_g ): ManualDeliveryPricingResult {
		$config = $this->settings->pricing_config( $service_id, $this->ranges->ranges( $service_id ) );

		return $this->calculator->calculate( $config, $chargeable_weight_g );
	}
}

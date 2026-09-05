<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Manual;

use WallsShop\WDC\Domain\Common\Money;

defined( 'ABSPATH' ) || exit;

final class ManualDeliveryPricingCalculator {
	public function calculate( ManualDeliveryPricingConfig $config, int $chargeable_weight_g ): ManualDeliveryPricingResult {
		$chargeable_weight_g = max( 0, $chargeable_weight_g );
		$billing_weight_g = $this->billing_weight_g( $chargeable_weight_g, $config->billing_weight_step_g );

		return match ( $config->mode ) {
			ManualDeliverySettings::PRICING_MODE_FLAT => $this->flat( $config, $chargeable_weight_g ),
			ManualDeliverySettings::PRICING_MODE_PER_KG => $this->per_kg( $config, $chargeable_weight_g, $billing_weight_g ),
			ManualDeliverySettings::PRICING_MODE_WEIGHT_RANGES => $this->weight_ranges( $config, $chargeable_weight_g, $billing_weight_g ),
			default => ManualDeliveryPricingResult::unavailable( $config->mode, $chargeable_weight_g, $billing_weight_g, 'manual_pricing_mode_invalid' ),
		};
	}

	private function flat( ManualDeliveryPricingConfig $config, int $chargeable_weight_g ): ManualDeliveryPricingResult {
		if ( $config->flat_price_kopecks < 0 ) {
			return ManualDeliveryPricingResult::unavailable( $config->mode, $chargeable_weight_g, 0, 'manual_flat_price_invalid' );
		}

		return new ManualDeliveryPricingResult( true, Money::from_kopecks( $config->flat_price_kopecks ), $config->mode, $chargeable_weight_g, 0, null, 'manual_flat' );
	}

	private function per_kg( ManualDeliveryPricingConfig $config, int $chargeable_weight_g, int $billing_weight_g ): ManualDeliveryPricingResult {
		if ( $chargeable_weight_g <= 0 ) {
			return ManualDeliveryPricingResult::unavailable( $config->mode, $chargeable_weight_g, $billing_weight_g, 'manual_weight_missing' );
		}
		if ( $config->price_per_kg_kopecks < 0 || $config->billing_weight_step_g < 1 ) {
			return ManualDeliveryPricingResult::unavailable( $config->mode, $chargeable_weight_g, $billing_weight_g, 'manual_per_kg_config_invalid' );
		}

		$price_kopecks = $this->round_divide( $config->price_per_kg_kopecks * $billing_weight_g, 1000 );
		if ( null !== $config->minimum_price_kopecks ) {
			$price_kopecks = max( $price_kopecks, $config->minimum_price_kopecks );
		}

		return new ManualDeliveryPricingResult( true, Money::from_kopecks( $price_kopecks ), $config->mode, $chargeable_weight_g, $billing_weight_g, null, 'manual_per_kg' );
	}

	private function weight_ranges( ManualDeliveryPricingConfig $config, int $chargeable_weight_g, int $billing_weight_g ): ManualDeliveryPricingResult {
		if ( $chargeable_weight_g <= 0 ) {
			return ManualDeliveryPricingResult::unavailable( $config->mode, $chargeable_weight_g, $billing_weight_g, 'manual_weight_missing' );
		}
		foreach ( $config->ranges as $range ) {
			if ( ! $range instanceof ManualDeliveryWeightRange ) {
				continue;
			}
			if ( $billing_weight_g > $range->from_weight_g && $billing_weight_g <= $range->to_weight_g ) {
				return new ManualDeliveryPricingResult( true, Money::from_kopecks( $range->price_kopecks ), $config->mode, $chargeable_weight_g, $billing_weight_g, $range, 'manual_weight_range' );
			}
		}

		return ManualDeliveryPricingResult::unavailable( $config->mode, $chargeable_weight_g, $billing_weight_g, 'manual_weight_range_not_found' );
	}

	public function billing_weight_g( int $chargeable_weight_g, int $step_g ): int {
		$chargeable_weight_g = max( 0, $chargeable_weight_g );
		$step_g = max( 1, $step_g );
		if ( 0 === $chargeable_weight_g ) {
			return 0;
		}

		return intdiv( $chargeable_weight_g + $step_g - 1, $step_g ) * $step_g;
	}

	private function round_divide( int $value, int $divisor ): int {
		return intdiv( $value + intdiv( $divisor, 2 ), $divisor );
	}
}

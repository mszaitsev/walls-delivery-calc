<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Quote;

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Package\Package;

defined( 'ABSPATH' ) || exit;

final class PekLightCargoSurchargePolicy {
	public function __construct( private PekSettings $settings ) {
	}

	public function evaluate( Package $package ): PekLightCargoSurchargeResult {
		$product_weight_g = max( 0, $package->weight_g );
		$weight_limit_g = $this->settings->light_cargo_weight_limit_g();
		$bag_price_kopecks = $this->settings->light_cargo_bag_price_kopecks();
		$sealing_price_kopecks = $this->settings->light_cargo_sealing_price_kopecks();
		if ( $product_weight_g <= 0 ) {
			return $this->result( false, false, $product_weight_g, $weight_limit_g, 0, 0, PekLightCargoSurchargeResult::REASON_WEIGHT_NOT_KNOWN );
		}
		if ( $product_weight_g >= $weight_limit_g ) {
			return $this->result( false, false, $product_weight_g, $weight_limit_g, 0, 0, PekLightCargoSurchargeResult::REASON_WEIGHT_AT_OR_ABOVE_LIMIT );
		}
		$total = $bag_price_kopecks + $sealing_price_kopecks;
		if ( $total <= 0 ) {
			return $this->result( true, false, $product_weight_g, $weight_limit_g, 0, 0, PekLightCargoSurchargeResult::REASON_ZERO_SURCHARGE );
		}

		return $this->result( true, true, $product_weight_g, $weight_limit_g, $bag_price_kopecks, $sealing_price_kopecks, PekLightCargoSurchargeResult::REASON_APPLIED );
	}

	private function result( bool $eligible, bool $applied, int $product_weight_g, int $weight_limit_g, int $bag_price_kopecks, int $sealing_price_kopecks, string $reason ): PekLightCargoSurchargeResult {
		$surcharges = array();
		if ( $applied && $bag_price_kopecks > 0 ) {
			$surcharges[] = array( 'code' => 'light_cargo_bag', 'title' => 'Мешок', 'price_kopecks' => $bag_price_kopecks );
		}
		if ( $applied && $sealing_price_kopecks > 0 ) {
			$surcharges[] = array( 'code' => 'light_cargo_sealing', 'title' => 'Пломбировка', 'price_kopecks' => $sealing_price_kopecks );
		}

		return new PekLightCargoSurchargeResult(
			$eligible,
			$applied,
			$product_weight_g,
			$weight_limit_g,
			$bag_price_kopecks,
			$sealing_price_kopecks,
			$bag_price_kopecks + $sealing_price_kopecks,
			$reason,
			$surcharges
		);
	}
}

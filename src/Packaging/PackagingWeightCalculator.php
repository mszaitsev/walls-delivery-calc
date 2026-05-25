<?php
declare(strict_types=1);

namespace WallsShop\WDC\Packaging;

use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class PackagingWeightCalculator {
	public const SETTINGS_KEY = 'packaging_weight_tiers';
	public const PACKAGING_SKU = 'WDC_PACKAGING';

	public function __construct( private SettingsRepository $settings ) {
	}

	public function packaging_weight_for_cart_weight( int $cart_weight_g ): int {
		$cart_weight_g = max( 0, $cart_weight_g );
		foreach ( $this->tiers() as $tier ) {
			if ( $cart_weight_g >= $tier['cart_weight_from_g'] && $cart_weight_g <= $tier['cart_weight_to_g'] ) {
				return $tier['packaging_weight_g'];
			}
		}

		return 0;
	}

	public function apply_to_package( Package $package, DeliveryService $service ): PackagingApplicationResult {
		$products_weight = max( 0, $package->weight_g );
		$mode = DeliveryService::normalize_packaging_weight_mode( $service->packaging_weight_mode );
		if ( ! $service->include_packaging_weight ) {
			return new PackagingApplicationResult( $products_weight, 0, $package->get_total_weight_g(), false, $mode, $package );
		}

		$packaging_weight = $this->packaging_weight_for_cart_weight( $products_weight );
		if ( $packaging_weight <= 0 ) {
			return new PackagingApplicationResult( $products_weight, 0, $package->get_total_weight_g(), true, $mode, $package );
		}

		if ( DeliveryService::PACKAGING_WEIGHT_PACKAGE_ITEM === $mode ) {
			$packaging_item = new PackageItem( self::PACKAGING_SKU, 'Упаковка', 1, Money::from_rubles( 0 ), Money::from_rubles( 0 ), $packaging_weight, 1, 1, 1 );
			$updated = Package::from_items( array_merge( $package->items, array( $packaging_item ) ), 0, $package->cart_total, $package->declared_value );
		} else {
			$updated = $package->with_packaging_weight( $packaging_weight );
		}

		return new PackagingApplicationResult( $products_weight, $packaging_weight, $products_weight + $packaging_weight, true, $mode, $updated );
	}

	/**
	 * @return array<int,array{cart_weight_from_g:int,cart_weight_to_g:int,packaging_weight_g:int}>
	 */
	public function tiers(): array {
		$rows = $this->settings->get_array( self::SETTINGS_KEY, array() );
		$tiers = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$from = max( 0, (int) ( $row['cart_weight_from_g'] ?? $row['from_weight_g'] ?? 0 ) );
			$to = max( 0, (int) ( $row['cart_weight_to_g'] ?? $row['to_weight_g'] ?? 0 ) );
			$weight = max( 0, (int) ( $row['packaging_weight_g'] ?? 0 ) );
			if ( $to < $from ) {
				continue;
			}
			$tiers[] = array( 'cart_weight_from_g' => $from, 'cart_weight_to_g' => $to, 'packaging_weight_g' => $weight );
		}
		usort( $tiers, static fn( array $a, array $b ): int => $a['cart_weight_from_g'] <=> $b['cart_weight_from_g'] );

		return $tiers;
	}
}

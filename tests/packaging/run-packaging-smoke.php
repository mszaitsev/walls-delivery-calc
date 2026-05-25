<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

$GLOBALS['wdc_packaging_options'] = array();
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_packaging_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_packaging_options'][ $key ] = $value; return true; }

function packaging_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$settings = new SettingsRepository();
$calculator = new PackagingWeightCalculator( $settings );
packaging_assert( array() === $settings->defaults()[ PackagingWeightCalculator::SETTINGS_KEY ], 'No default packaging tiers must be created.' );
packaging_assert( 0 === $calculator->packaging_weight_for_cart_weight( 1000 ), 'Empty tiers must return 0 packaging weight.' );

$settings->set(
	PackagingWeightCalculator::SETTINGS_KEY,
	array(
		array( 'cart_weight_from_g' => 0, 'cart_weight_to_g' => 1000, 'packaging_weight_g' => 150 ),
		array( 'cart_weight_from_g' => 1001, 'cart_weight_to_g' => 3000, 'packaging_weight_g' => 250 ),
	)
);
$calculator = new PackagingWeightCalculator( $settings );
packaging_assert( 150 === $calculator->packaging_weight_for_cart_weight( 0 ), 'Lower boundary must be inclusive.' );
packaging_assert( 150 === $calculator->packaging_weight_for_cart_weight( 1000 ), 'Upper boundary must be inclusive.' );
packaging_assert( 250 === $calculator->packaging_weight_for_cart_weight( 1001 ), 'Second tier lower boundary must work.' );
packaging_assert( 0 === $calculator->packaging_weight_for_cart_weight( 4000 ), 'Non-matching weight must return 0.' );

$item = new PackageItem( 'SKU', 'Item', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 1000 );
$package = Package::from_items( array( $item ), 0, Money::from_rubles( 100 ), Money::from_rubles( 100 ) );
$service = DeliveryService::from_array( array( 'service_key' => 'svc', 'include_packaging_weight' => 1, 'packaging_weight_mode' => DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT ) );
$result = $calculator->apply_to_package( $package, $service );
packaging_assert( 150 === $result->packaging_weight_g && 1150 === $result->package->get_total_weight_g() && 1 === count( $result->package->items ), 'total_weight mode must increase package weight without adding item.' );

$disabled = DeliveryService::from_array( array( 'service_key' => 'svc', 'include_packaging_weight' => 0, 'packaging_weight_mode' => DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT ) );
$result = $calculator->apply_to_package( $package, $disabled );
packaging_assert( 0 === $result->packaging_weight_g && 1000 === $result->package->get_total_weight_g(), 'Disabled service packaging must leave package unchanged.' );

$service = DeliveryService::from_array( array( 'service_key' => 'svc', 'include_packaging_weight' => 1, 'packaging_weight_mode' => DeliveryService::PACKAGING_WEIGHT_PACKAGE_ITEM ) );
$result = $calculator->apply_to_package( $package, $service );
$packaging_item = $result->package->items[1] ?? null;
packaging_assert( $packaging_item instanceof PackageItem && PackagingWeightCalculator::PACKAGING_SKU === $packaging_item->sku && 150 === $packaging_item->weight_g && 1 === $packaging_item->length_cm && 1 === $packaging_item->width_cm && 1 === $packaging_item->height_cm && 0 === $packaging_item->total_price->get_kopecks(), 'package_item mode must add WDC_PACKAGING virtual item.' );

echo "Packaging smoke test passed.\n";

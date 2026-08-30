<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Packaging\PackagingBuilder;
use WallsShop\WDC\Packaging\PackagingBuilderConfig;
use WallsShop\WDC\Packaging\PackagingException;
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

$packaging_request = static function ( array $items ): QuoteRequest {
	$money = Money::from_rubles( 1000 );
	return new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Новосибирск' ), Package::from_items( $items, 0, $money, $money ), '', $money, '2026-08-30' );
};
$limits = new PackagingBuilderConfig( 500, 20.0, 15.0, 10.0, 1.0, 50.0, 50.0, 30.0 );
packaging_assert( $limits->parcel_dimensions_allowed( 50, 30, 20 ) && $limits->parcel_dimensions_allowed( 30, 50, 20 ) && $limits->parcel_dimensions_allowed( 30, 20, 50 ) && ! $limits->parcel_dimensions_allowed( 40, 40, 40 ), 'Optional parcel limits must allow rotation and reject 40x40x40 for 50x50x30.' );
$limited_builder = new PackagingBuilder( $limits );
$one = $limited_builder->build( $packaging_request( array( new PackageItem( 'one', 'One', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), 1000, 50, 30, 20 ) ) ) );
packaging_assert( 1 === count( $one->parcels() ), 'A limit-compatible Ozon-sized item must stay in one parcel.' );
$two = $limited_builder->build( $packaging_request( array( new PackageItem( 'two', 'Two', 2, Money::from_rubles( 500 ), Money::from_rubles( 1000 ), 1000, 48, 48, 30 ) ) ) );
packaging_assert( 2 === count( $two->parcels() ), 'Two units that do not share one limited box must become two parcels.' );
$three = $limited_builder->build( $packaging_request( array( new PackageItem( 'three', 'Three', 3, Money::from_rubles( 334 ), Money::from_rubles( 1000 ), 1000, 48, 48, 30 ) ) ) );
packaging_assert( count( $three->parcels() ) >= 3 && 'multi_boxes_3d' === (string) ( $three->diagnostics['packing_strategy'] ?? '' ), 'Limited packing must create more than two boxes instead of an oversized stacked parcel.' );
foreach ( $three->parcels() as $parcel ) {
	packaging_assert( $limits->parcel_dimensions_allowed( $parcel->length_cm, $parcel->width_cm, $parcel->height_cm ), 'Every limited multi-box parcel must fit the configured dimensions.' );
}
$live_like = $limited_builder->build( $packaging_request( array( new PackageItem( 'live-like', 'Live-like', 3, Money::from_rubles( 334 ), Money::from_rubles( 1000 ), 6650, 37, 48, 20 ) ) ) );
packaging_assert( count( $live_like->parcels() ) > 1 && 'items_stacked_rows' !== (string) ( $live_like->diagnostics['packing_strategy'] ?? '' ), 'A live-like aggregate that would stack above 30 cm must become multiple limited parcels.' );
try {
	$limited_builder->build( $packaging_request( array( new PackageItem( 'oversize', 'Oversize', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), 1000, 60, 10, 10 ) ) ) );
	packaging_assert( false, 'An indivisible oversize item must fail closed under parcel limits.' );
} catch ( PackagingException $exception ) {
	packaging_assert( 'packaging_item_oversize' === $exception->safe_code, 'Oversize item must use the generic controlled packaging error.' );
}
$generic_stacked = ( new PackagingBuilder( PackagingBuilderConfig::defaults() ) )->build( $packaging_request( array( new PackageItem( 'generic', 'Generic', 3, Money::from_rubles( 334 ), Money::from_rubles( 1000 ), 1000, 48, 48, 30 ) ) ) );
packaging_assert( 'items_stacked_rows' === (string) ( $generic_stacked->diagnostics['packing_strategy'] ?? '' ), 'Without configured parcel limits the historical stacked fallback must remain available.' );

echo "Packaging smoke test passed.\n";

<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdParcelBuilder;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;

function dpd_parcel_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['wdc_dpd_parcel_builder_options'][ $key ] ?? $default;
}

function update_option( string $key, mixed $value, bool|string $autoload = false ): bool {
	$GLOBALS['wdc_dpd_parcel_builder_options'][ $key ] = $value;
	return true;
}

function wp_salt( string $scheme = '' ): string {
	return 'dpd-parcel-builder-smoke-' . $scheme;
}

function dpd_parcel_request( Package $package ): QuoteRequest {
	return new QuoteRequest(
		'RU',
		new Address( country_code: 'RU', city: 'Москва', postcode: '101000' ),
		$package,
		'',
		$package->cart_total,
		'2026-06-17',
		array( 'selected_location_id' => '200' )
	);
}

/**
 * @param array<int,PackageItem> $items
 */
function dpd_parcel_package( array $items ): Package {
	$total = 0;
	$weight = 0;
	foreach ( $items as $item ) {
		$total += (int) round( $item->total_price->get_rubles() );
		$weight += $item->weight_g * $item->quantity;
	}

	return new Package( $items, Money::from_rubles( $total ), Money::from_rubles( $total ), $weight, 0, $weight, null, null, null, null, 'cart' );
}

function dpd_parcel_item( string $sku, int $quantity, int $weight_g, int $length, int $width, int $height ): PackageItem {
	return new PackageItem( $sku, $sku, $quantity, Money::from_rubles( 100 ), Money::from_rubles( 100 * $quantity ), $weight_g, $length, $width, $height );
}

function dpd_parcel_build( DpdParcelBuilder $builder, Package $package ): array {
	return $builder->build( dpd_parcel_request( $package ) );
}

/**
 * @param array<int,array<string,mixed>> $units
 * @return array<string,mixed>|null
 */
function dpd_parcel_pack_units_in_box( DpdParcelBuilder $builder, array $units ): ?array {
	$method = new ReflectionMethod( $builder, 'pack_units_in_box' );
	$method->setAccessible( true );
	return $method->invoke( $builder, $units, array( 'length' => 50, 'width' => 50, 'height' => 30 ), 'box_50_50_30' );
}

$GLOBALS['wdc_dpd_parcel_builder_options'] = array(
	'wdc_core_settings' => array(
		PackagingWeightCalculator::SETTINGS_KEY => array(
		array( 'cart_weight_from_g' => 0, 'cart_weight_to_g' => 999, 'packaging_weight_g' => 50 ),
		array( 'cart_weight_from_g' => 1000, 'cart_weight_to_g' => 4999, 'packaging_weight_g' => 100 ),
		array( 'cart_weight_from_g' => 5000, 'cart_weight_to_g' => 999999, 'packaging_weight_g' => 200 ),
	),
	),
);

$settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$builder = new DpdParcelBuilder( $settings, new PackagingWeightCalculator( new SettingsRepository() ) );

$regular_4 = dpd_parcel_build( $builder, dpd_parcel_package( array( dpd_parcel_item( '36x11x11', 4, 1000, 36, 11, 11 ) ) ) );
dpd_parcel_assert( 1 === (int) $regular_4['parcels_count'], '4 regular items must fit one parcel.' );
dpd_parcel_assert( ! ( 36.0 === (float) $regular_4['parcel_dimensions'][0]['length'] && 11.0 === (float) $regular_4['parcel_dimensions'][0]['width'] && 11.0 === (float) $regular_4['parcel_dimensions'][0]['height'] ), '4 regular items must not be passed as one item dimensions.' );

$row_2 = dpd_parcel_pack_units_in_box(
	$builder,
	array(
		array( 'source' => 'row-a', 'length' => 20, 'width' => 20, 'height' => 10, 'weight_g' => 500, 'index' => 1 ),
		array( 'source' => 'row-b', 'length' => 20, 'width' => 20, 'height' => 10, 'weight_g' => 500, 'index' => 2 ),
	)
);
dpd_parcel_assert( null !== $row_2, '2 items 20x20x10 must fit in one row.' );
dpd_parcel_assert( 40 === (int) $row_2['length'] && 20 === (int) $row_2['width'] && 10 === (int) $row_2['height'], '2 items 20x20x10 must occupy 40x20x10, without doubled row width.' );

$row_3 = dpd_parcel_pack_units_in_box(
	$builder,
	array(
		array( 'source' => 'row-a', 'length' => 15, 'width' => 10, 'height' => 10, 'weight_g' => 300, 'index' => 1 ),
		array( 'source' => 'row-b', 'length' => 15, 'width' => 10, 'height' => 10, 'weight_g' => 300, 'index' => 2 ),
		array( 'source' => 'row-c', 'length' => 15, 'width' => 10, 'height' => 10, 'weight_g' => 300, 'index' => 3 ),
	)
);
dpd_parcel_assert( null !== $row_3, 'Several items in one row must not prematurely fallback.' );
dpd_parcel_assert( 45 === (int) $row_3['length'] && 10 === (int) $row_3['width'] && 10 === (int) $row_3['height'], '3 row items must stay in one compact row.' );

$grid_4 = dpd_parcel_build( $builder, dpd_parcel_package( array( dpd_parcel_item( '20x20x10', 4, 500, 20, 20, 10 ) ) ) );
$grid_dims = $grid_4['identical_grid_blocks_dimensions'][0] ?? array();
dpd_parcel_assert( 1 === (int) $grid_4['parcels_count'] && 1 === (int) $grid_4['identical_grid_blocks_count'], '4 identical items must use identical grid optimization.' );
dpd_parcel_assert( in_array( implode( 'x', array( (int) ( $grid_dims['length'] ?? 0 ), (int) ( $grid_dims['width'] ?? 0 ), (int) ( $grid_dims['height'] ?? 0 ) ) ), array( '40x40x10', '40x20x20', '20x40x20' ), true ), '4 identical 20x20x10 items must form a compact grid block.' );

$grid_5 = dpd_parcel_build( $builder, dpd_parcel_package( array( dpd_parcel_item( '20x20x10', 5, 500, 20, 20, 10 ) ) ) );
dpd_parcel_assert( 1 === (int) $grid_5['parcels_count'] && 'one_box_3d' === $grid_5['package_builder_source'], '5 identical 20x20x10 items must fit one 3D box.' );

$many_items = array();
for ( $i = 0; $i < 30; ++$i ) {
	$many_items[] = dpd_parcel_item( 'mixed-' . $i, 1, 120 + $i, 10 + ( $i % 5 ), 8 + ( $i % 4 ), 4 + ( $i % 3 ) );
}
$many = dpd_parcel_build( $builder, dpd_parcel_package( $many_items ) );
dpd_parcel_assert( '' === (string) $many['packing_limit_reason'], '30 different items must stay within packer safety limits.' );
dpd_parcel_assert( (int) $many['parcels_count'] <= 2 || 'items_stacked_rows' === (string) $many['package_builder_source'], '30 different items must finish as <=2 parcels or fallback.' );

$long = dpd_parcel_build( $builder, dpd_parcel_package( array( dpd_parcel_item( 'long', 1, 300, 70, 5, 5 ) ) ) );
dpd_parcel_assert( 1 === (int) $long['parcels_count'] && 1 === (int) $long['long_item_parcels_count'] && 'long_items_only' === (string) $long['package_builder_source'], 'Long item must become its own parcel.' );
dpd_parcel_assert( (int) $long['parcel_dimensions'][0]['packaging_weight_g'] > 0 && (int) $long['parcel_dimensions'][0]['final_weight_g'] > (int) $long['parcel_dimensions'][0]['goods_weight_g'], 'Long item parcel must include packaging weight.' );

$mixed = dpd_parcel_build( $builder, dpd_parcel_package( array( dpd_parcel_item( 'long', 1, 300, 70, 5, 5 ), dpd_parcel_item( 'regular', 2, 1000, 36, 11, 11 ) ) ) );
dpd_parcel_assert( 2 === (int) $mixed['parcels_count'] && 1 === (int) $mixed['long_item_parcels_count'] && 2 === (int) $mixed['regular_items_count'], 'Mixed basket must produce one long parcel plus one ordinary parcel.' );

$small_items = array();
for ( $i = 0; $i < 100; ++$i ) {
	$small_items[] = dpd_parcel_item( 'small-' . $i, 1, 5, 2, 2, 2 );
}
$small = dpd_parcel_build( $builder, dpd_parcel_package( $small_items ) );
dpd_parcel_assert( 100 === (int) $small['small_items_count'] && ! empty( $small['small_items_block_dimensions'] ), '100 small items must be aggregated into a synthetic block.' );

$small_regular_items = $small_items;
for ( $i = 0; $i < 10; ++$i ) {
	$small_regular_items[] = dpd_parcel_item( 'regular-' . $i, 1, 300, 12, 10, 8 );
}
$small_regular = dpd_parcel_build( $builder, dpd_parcel_package( $small_regular_items ) );
dpd_parcel_assert( 100 === (int) $small_regular['small_items_count'] && '' === (string) $small_regular['packing_limit_reason'], 'Small items must not count against expanded packer item limit.' );

$one_weight = dpd_parcel_build( $builder, dpd_parcel_package( array( dpd_parcel_item( 'weight', 1, 1000, 20, 20, 10 ) ) ) );
dpd_parcel_assert( (int) $one_weight['packaging_weight_g'] > 0 && (int) $one_weight['final_weight_g'] > (int) $one_weight['goods_weight_g'], 'One parcel must include packaging weight.' );

$two_box = dpd_parcel_build( $builder, dpd_parcel_package( array( dpd_parcel_item( 'two-box', 5, 2000, 49, 25, 15 ) ) ) );
dpd_parcel_assert( 2 === (int) $two_box['parcels_count'] && 'two_boxes_3d' === (string) $two_box['package_builder_source'], 'Large ordinary basket must split into two 3D boxes.' );
foreach ( $two_box['parcel_dimensions'] as $parcel ) {
	dpd_parcel_assert( (int) $parcel['packaging_weight_g'] > 0 && (int) $parcel['final_weight_g'] > (int) $parcel['goods_weight_g'], 'Each two-box parcel must include packaging weight.' );
}

$fallback = dpd_parcel_build( $builder, dpd_parcel_package( array( dpd_parcel_item( 'fallback', 3, 1000, 49, 49, 30 ) ) ) );
dpd_parcel_assert( 'items_stacked_rows' === (string) $fallback['package_builder_source'], 'Oversized ordinary basket must fallback to stacked rows without fatal.' );

echo "DPD parcel builder smoke test passed.\n";

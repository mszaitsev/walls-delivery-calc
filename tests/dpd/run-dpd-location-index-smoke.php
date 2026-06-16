<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
	}
}

require_once __DIR__ . '/../../src/Locations/ValueObjects/Location.php';
require_once __DIR__ . '/../../src/Locations/Storage/LocationRepository.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdLocationIndex.php';

use WallsShop\WDC\Carriers\Dpd\Geography\DpdLocationIndex;
use WallsShop\WDC\Locations\Storage\LocationRepository;

function dpd_location_index_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->locations = array(
	array(
		'id' => 10,
		'country_code' => 'RU',
		'active' => 1,
		'fias_id' => '8DEA00E3-9AAB-4D8E-887C-EF2AAA546456',
		'city_fias_id' => '',
		'kladr_id' => '5400000100000',
		'city_kladr_id' => '',
		'region_name' => 'Новосибирская',
		'district_name' => '',
		'place_name' => 'Новосибирск',
		'settlement_name' => 'Новосибирск',
		'city_name' => 'Новосибирск',
		'place_type' => 'г',
		'settlement_type' => 'г',
		'city_type' => 'г',
	),
	array(
		'id' => 11,
		'country_code' => 'RU',
		'active' => 1,
		'fias_id' => '11111111-2222-3333-4444-555555555555',
		'city_fias_id' => '',
		'kladr_id' => '5400000200000',
		'city_kladr_id' => '',
		'region_name' => 'Новосибирская',
		'district_name' => 'Один',
		'place_name' => 'Дубль',
		'settlement_name' => 'Дубль',
		'city_name' => 'Дубль',
		'place_type' => 'с',
		'settlement_type' => 'с',
		'city_type' => 'с',
	),
	array(
		'id' => 12,
		'country_code' => 'RU',
		'active' => 1,
		'fias_id' => '22222222-2222-3333-4444-555555555555',
		'city_fias_id' => '',
		'kladr_id' => '5400000300000',
		'city_kladr_id' => '',
		'region_name' => 'Новосибирская',
		'district_name' => 'Один',
		'place_name' => 'Дубль',
		'settlement_name' => 'Дубль',
		'city_name' => 'Дубль',
		'place_type' => 'с',
		'settlement_type' => 'с',
		'city_type' => 'с',
	),
	array(
		'id' => 13,
		'country_code' => 'KZ',
		'active' => 1,
		'fias_id' => '33333333-2222-3333-4444-555555555555',
		'kladr_id' => '9900000100000',
	),
);

$index = new DpdLocationIndex( new LocationRepository( $GLOBALS['wpdb'] ) );
$index->build( 2 );

dpd_location_index_assert( 10 === $index->match_fias( '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ), 'unique FIAS key matches location_id' );
dpd_location_index_assert( 10 === $index->match_kladr( 'RU54000001000' ), 'KLADR RU prefix and trailing zero normalization matches location_id' );
dpd_location_index_assert( $index->is_ambiguous( $index->match_name( 'Новосибирская', 'Один', 'Дубль', 'с' ) ), 'duplicate conservative name key is ambiguous' );
dpd_location_index_assert( 0 === $index->match_fias( '33333333-2222-3333-4444-555555555555' ), 'non-RU rows are excluded from index' );

$export = $index->export();
$loaded = new DpdLocationIndex( new LocationRepository( $GLOBALS['wpdb'] ) );
$loaded->load( $export );
dpd_location_index_assert( 10 === $loaded->match_kladr( '5400000100000' ), 'export/load preserves KLADR index' );

echo "DPD location index smoke OK\n";

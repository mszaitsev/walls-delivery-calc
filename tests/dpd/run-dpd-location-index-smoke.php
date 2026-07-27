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
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyMatcher.php';

use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyMatcher;
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
		'region_name' => 'Novosibirskaya',
		'district_name' => '',
		'place_name' => 'Novosibirsk',
		'settlement_name' => 'Novosibirsk',
		'city_name' => 'Novosibirsk',
		'place_type' => 'g',
		'settlement_type' => 'g',
		'city_type' => 'g',
	),
	array(
		'id' => 14,
		'country_code' => 'RU',
		'active' => 1,
		'fias_id' => '',
		'city_fias_id' => '8DEA00E3-9AAB-4D8E-887C-EF2AAA546456',
		'kladr_id' => '',
		'city_kladr_id' => '',
		'region_name' => 'Novosibirskaya',
		'district_name' => 'Shadow',
		'place_name' => 'Shadow',
		'settlement_name' => 'Shadow',
		'city_name' => 'Novosibirsk',
		'place_type' => 's',
		'settlement_type' => 's',
		'city_type' => 'g',
	),
	array(
		'id' => 11,
		'country_code' => 'RU',
		'active' => 1,
		'fias_id' => '11111111-2222-3333-4444-555555555555',
		'city_fias_id' => '',
		'kladr_id' => '5400000200000',
		'city_kladr_id' => '',
		'region_name' => 'Novosibirskaya',
		'district_name' => 'One',
		'place_name' => 'DuplicateName',
		'settlement_name' => 'DuplicateName',
		'city_name' => 'DuplicateName',
		'place_type' => 's',
		'settlement_type' => 's',
		'city_type' => 's',
	),
	array(
		'id' => 12,
		'country_code' => 'RU',
		'active' => 1,
		'fias_id' => '22222222-2222-3333-4444-555555555555',
		'city_fias_id' => '',
		'kladr_id' => '5400000300000',
		'city_kladr_id' => '',
		'region_name' => 'Novosibirskaya',
		'district_name' => 'One',
		'place_name' => 'DuplicateName',
		'settlement_name' => 'DuplicateName',
		'city_name' => 'DuplicateName',
		'place_type' => 's',
		'settlement_type' => 's',
		'city_type' => 's',
	),
	array(
		'id' => 20,
		'country_code' => 'RU',
		'active' => 1,
		'fias_id' => '99999999-2222-3333-4444-555555555555',
		'city_fias_id' => '',
		'kladr_id' => '',
		'city_kladr_id' => '',
		'region_name' => 'Novosibirskaya',
		'district_name' => 'One',
		'place_name' => 'OwnDuplicate',
		'settlement_name' => 'OwnDuplicate',
		'city_name' => 'OwnDuplicate',
		'place_type' => 's',
		'settlement_type' => 's',
		'city_type' => 's',
	),
	array(
		'id' => 21,
		'country_code' => 'RU',
		'active' => 1,
		'fias_id' => '99999999-2222-3333-4444-555555555555',
		'city_fias_id' => '',
		'kladr_id' => '',
		'city_kladr_id' => '',
		'region_name' => 'Novosibirskaya',
		'district_name' => 'Two',
		'place_name' => 'OtherOwnDuplicate',
		'settlement_name' => 'OtherOwnDuplicate',
		'city_name' => 'OtherOwnDuplicate',
		'place_type' => 's',
		'settlement_type' => 's',
		'city_type' => 's',
	),
	array(
		'id' => 30,
		'country_code' => 'RU',
		'active' => 1,
		'fias_id' => '',
		'city_fias_id' => '77777777-2222-3333-4444-555555555555',
		'kladr_id' => '',
		'city_kladr_id' => '',
		'region_name' => 'Novosibirskaya',
		'district_name' => '',
		'place_name' => 'CityFallback',
		'settlement_name' => 'CityFallback',
		'city_name' => 'CityFallback',
		'place_type' => 'g',
		'settlement_type' => 'g',
		'city_type' => 'g',
	),
	array(
		'id' => 13,
		'country_code' => 'KZ',
		'active' => 1,
		'fias_id' => '33333333-2222-3333-4444-555555555555',
		'kladr_id' => '9900000100000',
	),
);

$locations = new LocationRepository( $GLOBALS['wpdb'] );
$index = new DpdLocationIndex( $locations );
$index->build( 2 );
$matcher = new DpdGeographyMatcher( $index, $locations );

$own_shadow_match = $matcher->match( array( 'country_code' => 'RU', 'fias' => '8dea00e3-9aab-4d8e-887c-ef2aaa546456', 'region' => 'Novosibirskaya', 'district' => '', 'settlement' => 'Novosibirsk', 'settlement_type' => 'g' ) );
dpd_location_index_assert( 'matched' === $own_shadow_match['status'] && 'own_fias' === $own_shadow_match['method'] && 10 === (int) $own_shadow_match['location_id'], 'own FIAS wins over a different row using the same GUID as city_fias_id.' );
dpd_location_index_assert( array( 10 ) === $index->match_own_fias( '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ) && array( 14 ) === $index->match_city_fias( '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ), 'own FIAS and city FIAS candidate buckets are separated.' );
$resolved_own_ambiguity = $matcher->match( array( 'country_code' => 'RU', 'fias' => '99999999-2222-3333-4444-555555555555', 'region' => 'Novosibirskaya', 'district' => 'One', 'settlement' => 'OwnDuplicate', 'settlement_type' => 's' ) );
dpd_location_index_assert( 'matched' === $resolved_own_ambiguity['status'] && 20 === (int) $resolved_own_ambiguity['location_id'] && ! empty( $resolved_own_ambiguity['resolved_after_fias_disambiguation'] ), 'ambiguous own FIAS can be resolved by row hierarchy metadata.' );
$true_own_ambiguity = $matcher->match( array( 'country_code' => 'RU', 'fias' => '99999999-2222-3333-4444-555555555555', 'region' => 'Novosibirskaya', 'district' => '', 'settlement' => '', 'settlement_type' => '' ) );
dpd_location_index_assert( 'ambiguous' === $true_own_ambiguity['status'] && 'own_fias' === $true_own_ambiguity['method'] && ! empty( $true_own_ambiguity['true_fias_ambiguity'] ), 'true duplicate own FIAS remains ambiguous instead of falling back.' );
$city_fias_fallback = $matcher->match( array( 'country_code' => 'RU', 'fias' => '77777777-2222-3333-4444-555555555555', 'region' => 'Novosibirskaya', 'district' => '', 'settlement' => 'CityFallback', 'settlement_type' => 'g' ) );
dpd_location_index_assert( 'matched' === $city_fias_fallback['status'] && 'city_fias' === $city_fias_fallback['method'] && 30 === (int) $city_fias_fallback['location_id'], 'city_fias remains available as a fallback only when own FIAS has no candidates.' );
dpd_location_index_assert( 10 === $index->match_kladr( 'RU54000001000' ), 'KLADR RU prefix and trailing zero normalization matches location_id' );
dpd_location_index_assert( $index->is_ambiguous( $index->match_name( 'Novosibirskaya', 'One', 'DuplicateName', 's' ) ), 'duplicate conservative name key is ambiguous' );
dpd_location_index_assert( array() === $index->match_own_fias( '33333333-2222-3333-4444-555555555555' ), 'non-RU rows are excluded from own FIAS index' );

$stats = $index->stats();
dpd_location_index_assert( (int) $stats['own_fias_keys'] > 0 && (int) $stats['city_fias_keys'] > 0, 'index stats report separated own and city FIAS buckets.' );
$export = $index->export();
dpd_location_index_assert( ! property_exists( DpdLocationIndex::class, 'location_meta' ), 'DPD location index no longer stores per-location metadata.' );
dpd_location_index_assert( ! array_key_exists( 'locations', $export ), 'DPD location index export no longer serializes per-location metadata.' );
$loaded = new DpdLocationIndex( $locations );
$loaded->load( $export );
dpd_location_index_assert( 10 === $loaded->match_kladr( '5400000100000' ), 'export/load preserves KLADR index' );
dpd_location_index_assert( array( 10 ) === $loaded->match_own_fias( '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ) && array( 14 ) === $loaded->match_city_fias( '8dea00e3-9aab-4d8e-887c-ef2aaa546456' ), 'export/load preserves separated FIAS indexes.' );

echo "DPD location index smoke OK\n";

<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMapperV2Service;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Runner;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2NameNormalizer;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexRegionMappingV2Repository;

function yd_location_mapping_v2_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function current_time( string $type ): string { return '2026-06-26 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['yd_location_mapping_v2_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['yd_location_mapping_v2_options'][ $key ] = $value; return true; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $yandex_delivery_geo_v2 = array();
		public array $yandex_location_mapping_v2 = array();
		public array $yandex_region_mapping_v2 = array();
		public array $wdc_locations = array();
		public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) { $query = preg_replace( '/%[sdf]/', is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query; } return $query; }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

$GLOBALS['yd_location_mapping_v2_options'] = array();
$GLOBALS['wpdb'] = new wpdb();

$repository = new YandexLocationMappingV2Repository( $GLOBALS['wpdb'] );
$schema = $repository->schema();
foreach ( array( 'yandex_geo_id bigint(20) unsigned NOT NULL', 'location_id bigint(20) unsigned NOT NULL', 'status varchar(32) NOT NULL', 'confidence decimal(5,2) NULL', 'distance_km decimal(10,3) NULL', 'region_match tinyint(1)', 'locality_match tinyint(1)', 'coordinate_match tinyint(1)', 'matched_by_json longtext NULL', 'raw_json longtext NULL', 'is_primary tinyint(1)', 'UNIQUE KEY geo_location (yandex_geo_id, location_id)' ) as $needle ) {
	yd_location_mapping_v2_assert( str_contains( $schema, $needle ), 'Mapping v2 schema must contain: ' . $needle );
}

$upsert = $repository->upsert( array( 'yandex_geo_id' => 1, 'location_id' => 10, 'status' => 'manual', 'confidence' => 99.999, 'distance_km' => 1.23456, 'region_match' => 1, 'locality_match' => 1, 'coordinate_match' => 1, 'matched_by_json' => '["manual"]', 'raw_json' => '{}', 'is_primary' => 1 ) );
yd_location_mapping_v2_assert( 1 === $upsert['saved'], 'Repository must save mapping row.' );
$found_by_geo = $repository->find_by_geo( 1 );
yd_location_mapping_v2_assert( 1 === count( $found_by_geo ) && 10 === (int) $found_by_geo[0]['location_id'] && 100.0 === (float) $found_by_geo[0]['confidence'] && 1.235 === (float) $found_by_geo[0]['distance_km'], 'Repository must normalize and find by geo.' );
yd_location_mapping_v2_assert( 1 === count( $repository->find_by_location( 10 ) ), 'Repository must find by location.' );
$repository->truncate();
$normalizer = new YandexLocationMappingV2NameNormalizer( array( 'city' => array( 'г' => array( 'display' => 'город', 'position' => 'before' ) ), 'place' => array( 'рп' => array( 'display' => 'рабочий поселок', 'position' => 'before' ), 'пгт' => array( 'display' => 'поселок городского типа', 'position' => 'before' ) ) ) );
$terms_moscow = $normalizer->search_terms_for_locality( 'Москва г' );
$terms_kazan = $normalizer->search_terms_for_locality( 'Казань г' );
$terms_doni = $normalizer->search_terms_for_locality( 'деревня Дони' );
$terms_boris = $normalizer->search_terms_for_locality( 'Борисоглебский рп' );
$terms_vorovskogo = $normalizer->search_terms_for_locality( 'посёлок городского типа имени Воровского' );
$terms_taganrog = $normalizer->search_terms_for_locality( 'Таганрог г' );
$terms_yanino = $normalizer->search_terms_for_locality( 'городской посёлок Янино-1' );
$terms_novinki = $normalizer->search_terms_for_locality( 'сельский посёлок Новинки' );
$terms_udelnaya = $normalizer->search_terms_for_locality( 'дачный посёлок Удельная' );
$terms_vyselki = $normalizer->search_terms_for_locality( 'станица Выселки' );
$terms_farm = $normalizer->search_terms_for_locality( 'хутор Ленинский' );
$terms_rakhya = $normalizer->search_terms_for_locality( 'Рахья гп' );
$terms_tarasikha = $normalizer->search_terms_for_locality( 'Тарасиха п/ст' );
$terms_shentala = $normalizer->search_terms_for_locality( 'Шентала ж/д_ст' );
$terms_brackets = $normalizer->search_terms_for_locality( 'деревня Новосельцы (Козинское сельское поселение)' );
yd_location_mapping_v2_assert( in_array( 'Москва г', $terms_moscow, true ) && in_array( 'Москва', $terms_moscow, true ), 'Search terms must keep raw Moscow term and add bare Moscow.' );
yd_location_mapping_v2_assert( in_array( 'Казань', $terms_kazan, true ), 'Search terms must add bare Kazan.' );
yd_location_mapping_v2_assert( in_array( 'Дони', $terms_doni, true ), 'Search terms must add bare village name.' );
yd_location_mapping_v2_assert( in_array( 'Борисоглебский', $terms_boris, true ) && in_array( 'рабочий поселок Борисоглебский', $terms_boris, true ), 'Search terms must expand work settlement type.' );
yd_location_mapping_v2_assert( in_array( 'имени Воровского', $terms_vorovskogo, true ) && in_array( 'пгт имени Воровского', $terms_vorovskogo, true ) && in_array( 'поселок городского типа имени Воровского', $terms_vorovskogo, true ), 'Search terms must expand urban settlement type.' );
yd_location_mapping_v2_assert( in_array( 'Таганрог г', $terms_taganrog, true ) && in_array( 'Таганрог', $terms_taganrog, true ) && in_array( 'г Таганрог', $terms_taganrog, true ) && in_array( 'город Таганрог', $terms_taganrog, true ), 'Search terms must add city prefix variants.' );
yd_location_mapping_v2_assert( count( $terms_taganrog ) === count( array_unique( $terms_taganrog ) ), 'Search terms must not contain duplicates.' );
yd_location_mapping_v2_assert( in_array( 'Янино-1', $terms_yanino, true ), 'Search terms must strip urban long prefix.' );
yd_location_mapping_v2_assert( in_array( 'Новинки', $terms_novinki, true ), 'Search terms must strip rural long prefix.' );
yd_location_mapping_v2_assert( in_array( 'Удельная', $terms_udelnaya, true ), 'Search terms must strip dacha settlement prefix.' );
yd_location_mapping_v2_assert( in_array( 'Выселки', $terms_vyselki, true ), 'Search terms must strip stanitsa prefix.' );
yd_location_mapping_v2_assert( in_array( 'Ленинский', $terms_farm, true ), 'Search terms must strip farm prefix.' );
yd_location_mapping_v2_assert( in_array( 'Рахья', $terms_rakhya, true ), 'Search terms must strip gp suffix.' );
yd_location_mapping_v2_assert( in_array( 'Тарасиха', $terms_tarasikha, true ), 'Search terms must strip platform station suffix.' );
yd_location_mapping_v2_assert( in_array( 'Шентала', $terms_shentala, true ), 'Search terms must strip railway station suffix.' );
yd_location_mapping_v2_assert( in_array( 'деревня Новосельцы (Козинское сельское поселение)', $terms_brackets, true ) && in_array( 'Новосельцы', $terms_brackets, true ), 'Search terms must keep raw bracketed locality and add base without brackets.' );

$geo = static function ( int $geo_id, string $region, string $locality, float $lat, float $lon, float $radius = 5.0 ): array {
	return array( 'yandex_geo_id' => $geo_id, 'region' => $region, 'locality' => $locality, 'centroid_lat' => $lat, 'centroid_lon' => $lon, 'coverage_radius_safe_km' => $radius, 'active' => 1 );
};
$location = static function ( int $id, string $region, string $city, string $settlement, float $lat, float $lon, string $place = '', string $city_type = '', string $settlement_type = '', string $place_type = '', string $district = '' ): array {
	return array( 'id' => $id, 'region_name' => $region, 'district_name' => $district, 'city_name' => $city, 'city_type' => $city_type, 'settlement_name' => $settlement, 'settlement_type' => $settlement_type, 'place_name' => $place, 'place_type' => $place_type, 'display_name' => trim( $city . ' ' . $settlement . ' ' . $place ), 'latitude' => $lat, 'longitude' => $lon, 'active' => 1 );
};
$GLOBALS['wpdb']->yandex_delivery_geo_v2 = array(
	$geo( 100, 'Новосибирская область', 'Новосибирск', 55.0302, 82.9204 ),
	$geo( 200, 'Новосибирская область', 'Бердск', 54.7582, 83.1072 ),
	$geo( 300, 'Москва', 'Москва г', 55.7558, 37.6173 ),
	$geo( 400, 'Тестовая область', 'Нетгорода', 50.0, 50.0 ),
	$geo( 500, 'Новосибирская область', 'Далёкий', 55.0, 82.0 ),
	$geo( 600, 'Москва и Московская область', 'Москва', 55.7558, 37.6173 ),
	$geo( 700, 'Новосибирская область', 'Краснообск', 54.0000, 82.0000 ),
	$geo( 800, 'Новосибирская область', 'Сортировка', 55.1000, 82.1000 ),
	$geo( 900, 'Тульская область', 'Тула', 54.1930, 37.6173 ),
	$geo( 1000, 'Владимирская область', 'Киржач г', 56.1480, 38.8630, 3.222 ),
	$geo( 1010, 'Воронежская область', 'Эртиль г', 51.8350, 40.8000, 2.139 ),
	$geo( 1020, 'Московская область', 'Быково рп', 55.6200, 38.0800, 2.489 ),
	$geo( 1030, 'Тестовая область', 'Одинаково д', 55.0000, 38.0000, 5.0 ),
	$geo( 1040, 'Омская область', 'Омск', 54.9893, 73.3682, 5.0 ),
	$geo( 1050, 'Тверская область', 'Красный Холм г', 58.0600, 37.1200, 5.0 ),
	$geo( 1060, 'Тестовая область', 'Павловка с', 52.1000, 39.1000, 5.0 ),
	$geo( 1070, 'Москва', 'район Внуково', 55.6110, 37.2960, 5.0 ),
	$geo( 1080, 'Тестовая область', 'городской округ Далеко', 10.0000, 10.0000, 5.0 ),
	$geo( 1090, 'Регион без выбора', 'Любой', 55.0000, 38.0000, 5.0 ),
);
$GLOBALS['wpdb']->wdc_locations = array(
	$location( 10, 'Новосибирская область', 'Новосибирск', '', 55.0302, 82.9204 ),
	$location( 20, 'Новосибирская область', 'Бердск', '', 54.7582, 83.1072 ),
	$location( 21, 'Новосибирская область', '', 'Бердск', 54.7600, 83.1100 ),
	$location( 30, 'Москва', 'г Москва', '', 55.7558, 37.6173 ),
	$location( 50, 'Новосибирская область', 'Далёкий', '', 60.0, 90.0 ),
	$location( 70, 'Новосибирская область', 'Новосибирск', 'Краснообск', 54.0000, 82.0000 ),
	$location( 80, 'Другой регион', 'Сортировка', '', 55.1000, 82.1000 ),
	$location( 81, 'Новосибирская область', 'Сортировка', '', 55.1000, 82.1000 ),
	$location( 90, 'Тульская область', 'Тула', 'Тула', 54.1930, 37.6173, 'Тула' ),
	$location( 91, 'Тульская область', 'Тула', 'Никитино', 54.1930, 37.6173, 'Никитино' ),
	$location( 1000, 'Владимирская область', 'Киржач', '', 56.1570, 38.8630, '', 'г' ),
	$location( 1001, 'Владимирская область', '', 'Киржач', 56.3520, 38.8630, 'Киржач', '', 'д', 'д' ),
	$location( 1010, 'Воронежская область', 'Эртиль', '', 51.8420, 40.8000, '', 'г' ),
	$location( 1011, 'Воронежская область', '', 'Эртиль', 52.0330, 40.8000, 'Эртиль', '', 'д', 'д' ),
	$location( 1020, 'Московская область', '', 'Быково', 55.6220, 38.0800, 'Быково', '', 'пгт', 'пгт' ),
	$location( 1021, 'Московская область', '', 'Быково', 55.6410, 38.0800, 'Быково', '', 'с', 'с' ),
	$location( 1022, 'Московская область', '', 'Быково', 55.9250, 38.0800, 'Быково', '', 'п', 'п' ),
	$location( 1023, 'Московская область', '', 'Быково', 55.9600, 38.0800, 'Быково', '', 'д', 'д' ),
	$location( 1030, 'Тестовая область', '', 'Одинаково', 55.0090, 38.0000, 'Одинаково', '', 'д', 'д' ),
	$location( 1031, 'Тестовая область', '', 'Одинаково', 55.0270, 38.0000, 'Одинаково', '', 'д', 'д' ),
	$location( 1040, 'Омская область', 'Омск', '', 54.9893, 73.3682, '', 'г' ),
	$location( 1041, 'Омская область', 'Омский', '', 54.9893, 73.3682 ),
	$location( 1050, 'Тверская область', 'Красный Холм', '', 58.0600, 37.1200, 'Слобода', 'г', '', 'д' ),
	$location( 1060, 'Тестовая область', '', 'Павловка', 52.1000, 39.1000, 'Павловка', '', 'с', 'с', 'Район' ),
	$location( 1061, 'Тестовая область', '', 'Павловка', 52.1000, 39.1000, 'Павловка', '', 'с', 'с', 'Район' ),
	$location( 1070, 'Москва', 'Москва', 'Внуково', 55.6110, 37.2960, 'Внуково' ),
);
$GLOBALS['wpdb']->yandex_region_mapping_v2 = array();
$region_repository = new YandexRegionMappingV2Repository( $GLOBALS['wpdb'] );
foreach ( array(
	'Новосибирская область' => array( 'Новосибирская область' ),
	'Москва' => array( 'Москва' ),
	'Москва и Московская область' => array( 'Москва', 'Московская область' ),
	'Тестовая область' => array( 'Тестовая область' ),
	'Тульская область' => array( 'Тульская область' ),
	'Владимирская область' => array( 'Владимирская область' ),
	'Воронежская область' => array( 'Воронежская область' ),
	'Московская область' => array( 'Московская область' ),
	'Омская область' => array( 'Омская область' ),
	'Тверская область' => array( 'Тверская область' ),
	'Регион без выбора' => array( '' ),
) as $yandex_region => $wdc_regions ) {
	$region_repository->save_mapping( $yandex_region, $wdc_regions );
}

$mapper = new YandexLocationMapperV2Service( $repository, $GLOBALS['wpdb'], null, $region_repository );
$result = $mapper->build_all( 20, 0 );
yd_location_mapping_v2_assert( 19 === $result['processed_geo_ids'] && 12 === $result['mapped'] && 2 === $result['needs_review'] && 5 === $result['no_match'] && true === $result['done'], 'Mapper batch must classify mapped, needs_review, and no_match geo ids.' );
$geo100 = $repository->find_by_geo( 100 );
yd_location_mapping_v2_assert( 1 === count( $geo100 ) && 'mapped' === $geo100[0]['status'] && 100.0 === (float) $geo100[0]['confidence'] && 1 === (int) $geo100[0]['is_primary'], 'Single candidate must be mapped with full confidence.' );
$matched_by100 = json_decode( (string) $geo100[0]['matched_by_json'], true );
$raw100 = json_decode( (string) $geo100[0]['raw_json'], true );
yd_location_mapping_v2_assert( in_array( 'locality', $matched_by100, true ) && in_array( 'region', $matched_by100, true ) && in_array( 'coordinates', $matched_by100, true ), 'matched_by_json must include locality, region, and coordinates.' );
yd_location_mapping_v2_assert( array_key_exists( 'distance', $raw100 ) && array_key_exists( 'radius', $raw100 ) && array_key_exists( 'threshold', $raw100 ) && true === $raw100['region_matched'] && 'city_name' === $raw100['locality_source'] && 'Новосибирск' === $raw100['locality_raw'] && 'новосибирск' === $raw100['effective_locality'] && 1 === (int) $raw100['candidate_count'], 'raw_json must contain distance, radius, threshold, region_matched, locality_source, and candidate_count.' );
$geo200 = $repository->find_by_geo( 200 );
yd_location_mapping_v2_assert( 2 === count( $geo200 ) && 'needs_review' === $geo200[0]['status'] && 1 === (int) $geo200[0]['is_primary'], 'Multiple candidates must be saved as needs_review variants.' );
$geo300 = $repository->find_by_geo( 300 );
yd_location_mapping_v2_assert( 1 === count( $geo300 ) && 'mapped' === $geo300[0]['status'] && 120.0 === (float) $geo300[0]['confidence'], 'Geo must match inside WDC regions selected by region mapping.' );
$geo400 = $repository->find_by_geo( 400 );
$geo500 = $repository->find_by_geo( 500 );
yd_location_mapping_v2_assert( 'no_match' === $geo400[0]['status'] && 0 === (int) $geo400[0]['location_id'] && 'no_match' === $geo500[0]['status'], 'Missing and far candidates must become no_match.' );
$raw400 = json_decode( (string) $geo400[0]['raw_json'], true );
yd_location_mapping_v2_assert( is_array( $raw400['sql_search_terms'] ?? null ) && array_key_exists( 'candidate_count_before_filters', $raw400 ) && array_key_exists( 'candidate_count_after_filters', $raw400 ), 'no_match raw_json must contain sql_search_terms and candidate counters.' );
$geo600 = $repository->find_by_geo( 600 );
$raw600 = json_decode( (string) $geo600[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo600 ) && 'mapped' === $geo600[0]['status'] && 100.0 === (float) $geo600[0]['confidence'] && true === $raw600['region_matched'] && in_array( 'Москва', $raw600['mapped_regions'], true ) && in_array( 'Московская область', $raw600['mapped_regions'], true ), 'Region mapping must allow one Yandex region to search multiple WDC regions.' );
$geo700 = $repository->find_by_geo( 700 );
$raw700 = json_decode( (string) $geo700[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo700 ) && 'mapped' === $geo700[0]['status'] && 'settlement_name' === $raw700['locality_source'], 'Mapper must match locality through settlement_name.' );
$geo800 = $repository->find_by_geo( 800 );
$raw800 = json_decode( (string) $geo800[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo800 ) && 'mapped' === $geo800[0]['status'] && 81 === (int) $geo800[0]['location_id'] && 100.0 === (float) $geo800[0]['confidence'] && true === $raw800['region_matched'], 'Region mapping must restrict candidates to selected WDC regions before locality matching.' );
$geo900 = $repository->find_by_geo( 900 );
$raw900 = json_decode( (string) $geo900[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo900 ) && 90 === (int) $geo900[0]['location_id'] && 'place_name' === $raw900['locality_source'] && 'Тула' === $raw900['locality_raw'] && 'тула' === $raw900['effective_locality'], 'Effective locality must match the city row and reject lower nested settlements.' );
$geo1000 = $repository->find_by_geo( 1000 );
$raw1000 = json_decode( (string) $geo1000[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1000 ) && 1000 === (int) $geo1000[0]['location_id'] && true === $raw1000['dominance_auto_pick'] && 'distance_gap' === $raw1000['dominance_reason'] && 20 === (int) $raw1000['type_score'] && array() !== $raw1000['rejected_candidates'], 'Kirzhach must auto-map to city by distance dominance.' );
$geo1010 = $repository->find_by_geo( 1010 );
$raw1010 = json_decode( (string) $geo1010[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1010 ) && 1010 === (int) $geo1010[0]['location_id'] && true === $raw1010['dominance_auto_pick'] && 'distance_gap' === $raw1010['dominance_reason'], 'Ertil must auto-map to city by distance dominance.' );
$geo1020 = $repository->find_by_geo( 1020 );
$raw1020 = json_decode( (string) $geo1020[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1020 ) && 1020 === (int) $geo1020[0]['location_id'] && true === $raw1020['dominance_auto_pick'] && 'type_score' === $raw1020['dominance_reason'] && 'urban' === $raw1020['yandex_type'] && 'urban' === $raw1020['wdc_type'], 'Bykovo must auto-map to urban settlement by type dominance.' );
$geo1030 = $repository->find_by_geo( 1030 );
yd_location_mapping_v2_assert( 2 === count( $geo1030 ) && 'needs_review' === $geo1030[0]['status'], 'Equal nearby same-type candidates must stay needs_review.' );
$geo1040 = $repository->find_by_geo( 1040 );
$raw1040 = json_decode( (string) $geo1040[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1040 ) && 'mapped' === $geo1040[0]['status'] && 1040 === (int) $geo1040[0]['location_id'] && 'region_mapping' === $raw1040['candidate_search_mode'] && in_array( 'Омская область', $raw1040['mapped_regions'], true ) && (int) $raw1040['region_after_filters'] > 0, 'Region mapping search must map Omsk inside selected WDC regions.' );
$geo1050 = $repository->find_by_geo( 1050 );
$raw1050 = json_decode( (string) $geo1050[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1050 ) && 'no_match' === $geo1050[0]['status'] && 0 === (int) $geo1050[0]['location_id'] && 0 === (int) $raw1050['candidate_count_after_filters'] && 'no_locality_match' === $raw1050['reason'], 'Parent city name must not match a lower place effective locality.' );
$geo1060 = $repository->find_by_geo( 1060 );
$raw1060 = json_decode( (string) $geo1060[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1060 ) && 'mapped' === $geo1060[0]['status'] && 1060 === (int) $geo1060[0]['location_id'] && isset( $raw1060['deduped_location_ids'] ) && in_array( 1061, $raw1060['deduped_location_ids'], true ), 'Duplicate WDC candidates must be deduped into one mapping row.' );
$geo1070 = $repository->find_by_geo( 1070 );
$raw1070 = json_decode( (string) $geo1070[0]['raw_json'], true );
$matched_by1070 = json_decode( (string) $geo1070[0]['matched_by_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1070 ) && 'mapped' === $geo1070[0]['status'] && in_array( 'territory_coordinates', $matched_by1070, true ) && true === $raw1070['territory_fallback'], 'Territorial-like geo must map by coordinate fallback when close enough.' );
$geo1080 = $repository->find_by_geo( 1080 );
$raw1080 = json_decode( (string) $geo1080[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1080 ) && 'no_match' === $geo1080[0]['status'] && false === $raw1080['territory_fallback'] && 'no_locality_match' === $raw1080['reason'], 'Territorial-like geo without a nearby WDC location must stay no_match.' );
$geo1090 = $repository->find_by_geo( 1090 );
$raw1090 = json_decode( (string) $geo1090[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1090 ) && 'no_match' === $geo1090[0]['status'] && 'region_not_mapped' === $raw1090['reason'] && 'region_mapping' === $raw1090['candidate_search_mode'] && array() === $raw1090['mapped_regions'], 'Unselected region mapping must skip search and become region_not_mapped.' );
$recent_no_match = $repository->find_recent_no_match( 20 );
yd_location_mapping_v2_assert( count( $recent_no_match ) >= 5 && isset( $recent_no_match[0]['sql_search_terms'] ), 'Repository must return recent no_match diagnostics with sql_search_terms.' );
$stats = $repository->statistics();
yd_location_mapping_v2_assert( 21 === $stats['total'] && 12 === $stats['mapped'] && 4 === $stats['needs_review'] && 5 === $stats['no_match'] && 1 === $stats['no_match_region_not_mapped'] && 4 === $stats['no_match_no_locality_match'] && 1 === $stats['territory_fallback'] && null !== $stats['avg_confidence'] && null !== $stats['avg_distance'], 'Repository statistics must count statuses and averages.' );

$runner_repository = new YandexLocationMappingV2Repository( $GLOBALS['wpdb'] );
$runner = new YandexLocationMappingV2Runner( new YandexLocationMapperV2Service( $runner_repository, $GLOBALS['wpdb'], null, $region_repository ), $runner_repository );
$state = $runner->start();
yd_location_mapping_v2_assert( 'mapping' === $state['status'] && 0 === count( $GLOBALS['wpdb']->yandex_location_mapping_v2 ), 'Runner start must truncate and switch to mapping.' );
$state = $runner->run_step();
yd_location_mapping_v2_assert( 'mapping' === $state['status'] && 10 === $state['processed'] && 7 === $state['mapped'] && 1 === $state['needs_review'] && 2 === $state['no_match'], 'Runner first step must process one batch.' );
$state = $runner->run_step();
yd_location_mapping_v2_assert( 'done' === $state['status'] && 19 === $state['processed'] && 12 === $state['mapped'] && 2 === $state['needs_review'] && 5 === $state['no_match'] && 1 === $state['region_not_mapped'] && 4 === $state['no_locality_match'] && 1 === $state['territory_fallback'] && null !== $state['avg_confidence'] && null !== $state['avg_distance'], 'Runner step must update mapping counters and averages.' );
$runner->start();
$paused = $runner->pause();
$after_pause = $runner->run_step();
yd_location_mapping_v2_assert( 'paused' === $paused['status'] && $after_pause['offset'] === $paused['offset'], 'Runner pause must stop mapping loop.' );
$reset = $runner->reset();
yd_location_mapping_v2_assert( 'idle' === $reset['status'] && 0 === $reset['processed'], 'Runner reset must clear state.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/yandex-delivery-pickup-v2-runner.js' );
$mapper_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMapperV2Service.php' );
$normalizer_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMappingV2NameNormalizer.php' );
yd_location_mapping_v2_assert( str_contains( $admin_source, 'Маппинг geoId → населённые пункты' ) && str_contains( $admin_source, 'Построить сопоставление' ), 'Admin v2 tab must contain location mapping v2 UI.' );
yd_location_mapping_v2_assert( str_contains( $admin_source, 'wdc_yandex_location_mapping_v2_start' ) && str_contains( $admin_source, 'wdc_yandex_location_mapping_v2_step' ), 'Admin must register location mapping v2 AJAX actions.' );
yd_location_mapping_v2_assert( str_contains( $js_source, 'data-wdc-yandex-location-mapping-v2' ) && str_contains( $js_source, 'wdc_yandex_location_mapping_v2_step' ) && str_contains( $js_source, "runningStatus: 'mapping'" ), 'JS must contain independent location mapping v2 loop.' );
yd_location_mapping_v2_assert( str_contains( $plugin_source, 'YandexLocationMappingV2Repository::class' ) && str_contains( $plugin_source, 'YandexLocationMapperV2Service::class' ) && str_contains( $plugin_source, 'YandexLocationMappingV2Runner::class' ) && str_contains( $plugin_source, 'YandexRegionMappingV2Repository::class' ), 'Plugin DI must register location mapping v2 services.' );
yd_location_mapping_v2_assert( ! str_contains( $mapper_source, 'locationDetect' ) && ! str_contains( $mapper_source, 'YandexDeliveryApiClient' ) && ! str_contains( $mapper_source, '/api/' ), 'Location mapping v2 mapper must stay offline and not call Yandex API.' );
yd_location_mapping_v2_assert( str_contains( $mapper_source, 'find_wdc_regions_for_yandex' ) && str_contains( $mapper_source, 'fetch_locations_by_regions' ) && str_contains( $mapper_source, 'region_name IN' ) && str_contains( $mapper_source, 'candidate_search_mode' ) && str_contains( $mapper_source, 'region_mapping' ) && ! str_contains( $mapper_source, 'region_name LIKE' ) && ! str_contains( $mapper_source, 'normalize_region' ) && ! str_contains( $mapper_source, 'regions_compatible' ) && ! str_contains( $mapper_source, 'fetch_exact_location_candidates' ) && ! str_contains( $mapper_source, 'fetch_location_candidates' ) && ! str_contains( $mapper_source, 'exact_first' ) && ! str_contains( $mapper_source, 'broad_fallback' ) && str_contains( $mapper_source, 'effective_location_locality' ) && ! str_contains( $mapper_source, 'location_locality_variants' ) && ! str_contains( $mapper_source, 'matching_location_locality' ) && str_contains( $mapper_source, 'dedupe_candidates' ) && str_contains( $mapper_source, 'territory_coordinate_fallback' ) && str_contains( $mapper_source, 'detect_locality_type' ) && str_contains( $mapper_source, 'type_match_score' ) && str_contains( $mapper_source, 'dominance_auto_pick' ) && str_contains( $mapper_source, 'rejected_candidates' ) && str_contains( $mapper_source, 'locality_source' ) && str_contains( $mapper_source, 'locality_raw' ) && str_contains( $mapper_source, 'effective_locality' ), 'Mapper source must use region mapping, dedupe, territorial fallback, and must not use old region heuristics.' );
yd_location_mapping_v2_assert( str_contains( $normalizer_source, 'is_territorial_like' ) && str_contains( $normalizer_source, 'городской поселок' ) && str_contains( $normalizer_source, 'железнодорожная станция' ) && str_contains( $normalizer_source, 'without_parentheses' ), 'Normalizer source must contain new locality type and territorial helpers.' );
yd_location_mapping_v2_assert( str_contains( $admin_source, 'Последние no_match' ) && str_contains( $admin_source, 'sql_search_terms' ), 'Admin UI must render recent no_match diagnostics.' );
yd_location_mapping_v2_assert( str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMappingV2Repository.php' ), 'find_recent_no_match' ), 'Repository must expose find_recent_no_match.' );
yd_location_mapping_v2_assert( str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0038_create_yandex_location_mapping_v2.php' ), 'YandexLocationMappingV2Repository' ), 'Migration 0038 must create mapping v2 schema via repository.' );

echo "Yandex Delivery location mapping v2 smoke OK\n";

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
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationManualOverrideV2Repository;
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
		public array $yandex_location_manual_overrides_v2 = array();
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
$terms_vyselki2 = $normalizer->search_terms_for_locality( 'станица Выселки' );
$terms_pochinok_city = $normalizer->search_terms_for_locality( 'Починок г' );
$terms_vorovsky = $normalizer->search_terms_for_locality( 'посёлок городского типа имени Воровского' );
$terms_gromovo = $normalizer->search_terms_for_locality( 'посёлок при железнодорожной станции Громово' );
$terms_sovkhoz = $normalizer->search_terms_for_locality( 'посёлок Совхоза имени Ленина' );
$terms_factory = $normalizer->search_terms_for_locality( 'посёлок Фабрики имени 1 Мая' );
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
yd_location_mapping_v2_assert( in_array( 'Выселки', $terms_vyselki2, true ) && in_array( 'станица Выселки', $terms_vyselki2, true ) && in_array( 'ст-ца Выселки', $terms_vyselki2, true ), 'Search terms must expand stanitsa aliases.' );
yd_location_mapping_v2_assert( in_array( 'Починок', $terms_pochinok_city, true ) && in_array( 'г Починок', $terms_pochinok_city, true ) && in_array( 'город Починок', $terms_pochinok_city, true ), 'Search terms must expand city aliases for Pochinok.' );
yd_location_mapping_v2_assert( in_array( 'имени Воровского', $terms_vorovsky, true ) && in_array( 'им. Воровского', $terms_vorovsky, true ) && in_array( 'Воровского', $terms_vorovsky, true ) && in_array( 'пгт Воровского', $terms_vorovsky, true ), 'Search terms must expand named urban settlements.' );
yd_location_mapping_v2_assert( in_array( 'Громово', $terms_gromovo, true ) && in_array( 'станция Громово', $terms_gromovo, true ) && in_array( 'ж/д станция Громово', $terms_gromovo, true ) && in_array( 'поселок станции Громово', $terms_gromovo, true ), 'Search terms must expand railway station settlement aliases.' );
yd_location_mapping_v2_assert( in_array( 'Совхоза имени Ленина', $terms_sovkhoz, true ) && in_array( 'имени Ленина', $terms_sovkhoz, true ) && in_array( 'Ленина', $terms_sovkhoz, true ), 'Search terms must add sovkhoz name variants.' );
yd_location_mapping_v2_assert( in_array( 'Фабрики имени 1 Мая', $terms_factory, true ) && in_array( 'имени 1 Мая', $terms_factory, true ) && in_array( '1 Мая', $terms_factory, true ), 'Search terms must add factory name variants.' );
foreach ( array( 'станица Выселки' => 'Выселки', 'посёлок Починок' => 'Починок', 'Починок г' => 'Починок', 'слобода Петровка' => 'Петровка', 'слобода Алексеево-Тузловка' => 'Алексеево-Тузловка', 'Качалино ст' => 'Качалино', 'Игоревская ст' => 'Игоревская', 'Дубровка гп' => 'Дубровка', 'Выгорное 1-е с' => 'Выгорное 1-е' ) as $input => $base ) {
	$terms = $normalizer->search_terms_for_locality( $input );
	yd_location_mapping_v2_assert( in_array( $base, $terms, true ), 'Search terms must include base for ' . $input );
}
$address_terms_lviv = $normalizer->extract_locality_candidates_from_full_address( 'деревня Львово Крутовская улица 29 стр7' );
$address_terms_zverevo = $normalizer->extract_locality_candidates_from_full_address( 'СНТ Зверево Зверево 66' );
$address_terms_sloboda = $normalizer->extract_locality_candidates_from_full_address( 'Слобода д Центральная ул 22' );
yd_location_mapping_v2_assert( in_array( 'Львово', $address_terms_lviv, true ) && in_array( 'Зверево', $address_terms_zverevo, true ), 'Full address extraction must include precise locality candidates.' );
yd_location_mapping_v2_assert( in_array( 'Слобода', $address_terms_sloboda, true ) && ! in_array( 'Центральная', $address_terms_sloboda, true ), 'Suffix-type full address must extract Sloboda, not street name.' );

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
	$geo( 1100, 'Курганская область', 'Куртамыш г', 54.9000, 64.4300, 3.0 ),
	$geo( 1110, 'Тестовая область', 'Семенково п', 52.2000, 39.2000, 3.0 ),
	$geo( 1120, 'Липецкая область', 'Волово рп', 52.0300, 37.8800, 3.0 ),
	$geo( 1130, 'Тестовая область', 'Дубовка', 52.3000, 39.3000, 3.0 ),
	$geo( 1140, 'Тестовая область', 'Камышеваха с', 52.4000, 39.4000, 3.0 ),
	$geo( 1150, 'Москва и Московская область', 'Заречье', 55.6800, 37.3900, 3.0 ),
	$geo( 1160, 'Краснодарский край', 'станица Выселки', 45.5800, 39.6600, 3.0 ),
	$geo( 1170, 'Смоленская область', 'Починок г', 54.4063, 32.4390, 3.0 ),
	$geo( 1180, 'Московская область', 'посёлок городского типа имени Воровского', 55.7200, 38.3200, 3.0 ),
	$geo( 1190, 'Ленинградская область', 'посёлок при железнодорожной станции Громово', 60.7000, 30.1200, 3.0 ),
	array_merge( $geo( 1200, 'Московская область', 'СНТ Ромашка', 55.5000, 37.5000, 3.0 ), array( 'first_full_address' => 'СНТ Ромашка 12' ) ),
	array_merge( $geo( 1210, 'Московская область', 'КП Малая Медведица', 55.5100, 37.5100, 3.0 ), array( 'first_full_address' => 'КП Малая Медведица участок 1' ) ),
	$geo( 1220, 'Московская область', 'Брянск г', 55.5200, 37.5200, 3.0 ),
	array_merge( $geo( 1230, 'Московская область', 'СНТ Дальний', 55.0000, 37.0000, 20.0 ), array( 'first_full_address' => 'СНТ Дальний участок 1' ) ),
	$geo( 1240, 'Тестовая область', 'слобода Петровка', 52.5000, 39.5000, 3.0 ),
	array_merge( $geo( 1250, 'Москва', 'Москва', 55.3300, 37.1300, 3.0 ), array( 'first_full_address' => 'деревня Львово Крутовская улица 29 стр7' ) ),
	array_merge( $geo( 1260, 'Москва', 'Москва г', 55.3400, 37.1400, 3.0 ), array( 'sample_points_json' => '[{"full_address":"СНТ Зверево Зверево 66"}]' ) ),
	$geo( 1270, 'Московская область', 'садоводческое некоммерческое товарищество Строитель-2', 55.3500, 37.1500, 3.0 ),
	$geo( 1280, 'Тестовая область', 'Пирогово д', 52.6000, 39.6000, 3.0 ),
	$geo( 1290, 'Тестовая область', 'село Покровское', 52.7000, 39.7000, 3.0 ),
	$geo( 1300, 'Тестовая область', 'Качалино ст', 52.8000, 39.8000, 3.0 ),
	array_merge( $geo( 1310, 'Московская область', 'Москва г', 55.5300, 37.5300, 3.0 ), array( 'points_count' => 10 ) ),
	$geo( 1320, 'Тестовая область', 'Макарово', 52.9000, 39.9000, 3.0 ),
	array_merge( $geo( 1330, 'Ямало-Ненецкий автономный округ', 'Новый Уренгой г', 65.9290233, 78.1903587, 2.976 ), array( 'first_full_address' => 'Новый Уренгой г Мира пр-кт 26, к. 1' ) ),
	array_merge( $geo( 1340, 'Москва и Московская область', 'район Щербинка', 55.5000, 37.5600, 3.0 ), array( 'first_full_address' => 'Москва г Логинова ул 5/к2' ) ),
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
	$location( 1100, 'Курганская область', 'Куртамыш', '', 54.9000, 64.4300, '', 'г' ),
	$location( 1101, 'Курганская область', '', 'Куртамыш', 55.0000, 64.4300, 'Куртамыш', '', 'д', 'д' ),
	$location( 1110, 'Тестовая область', '', 'Семенково', 52.2000, 39.2000, 'Семенково', '', 'п', 'п' ),
	$location( 1111, 'Тестовая область', '', 'Семенково', 52.2010, 39.2000, 'Семенково', '', 'д', 'д' ),
	$location( 1120, 'Липецкая область', '', 'Волово', 52.0300, 37.8800, 'Волово', '', 'рп', 'рп' ),
	$location( 1121, 'Липецкая область', '', 'Волово', 52.0310, 37.8800, 'Волово', '', 'с', 'с' ),
	$location( 1130, 'Тестовая область', '', 'Дубовка', 52.3000, 39.3000, 'Дубовка', '', 'п', 'п' ),
	$location( 1131, 'Тестовая область', '', 'Дубовка', 52.3010, 39.3000, 'Дубовка (квартал 4)', '', 'п', 'п' ),
	$location( 1132, 'Тестовая область', '', 'Дубовка', 52.3020, 39.3000, 'Дубовка (квартал 5/15)', '', 'п', 'п' ),
	$location( 1140, 'Тестовая область', '', 'Камышеваха', 52.4000, 39.4000, 'Камышеваха', '', 'с', 'с' ),
	$location( 1141, 'Тестовая область', '', 'Камышеваха', 52.4000, 39.4000, 'Камышеваха', '', 'д', 'д' ),
	$location( 1150, 'Москва', '', 'Заречье', 55.6800, 37.3900, 'Заречье', '', 'д', 'д' ),
	$location( 1151, 'Московская область', '', 'Заречье', 55.6810, 37.3910, 'Заречье', '', 'д', 'д' ),
	$location( 1160, 'Краснодарский край', '', '', 45.5800, 39.6600, 'Выселки', '', '', 'ст-ца' ),
	$location( 1170, 'Смоленская область', '', '', 54.4063, 32.4390, 'Починок', '', '', 'г.' ),
	$location( 1180, 'Московская область', '', '', 55.7200, 38.3200, 'им. Воровского', '', '', 'пгт' ),
	$location( 1190, 'Ленинградская область', '', '', 60.7000, 30.1200, 'Громово', '', '', 'ж/д ст.' ),
	$location( 1200, 'Московская область', 'Москва', 'Соседний', 55.5000, 37.5000, 'Соседний' ),
	$location( 1210, 'Московская область', 'Москва', 'Соседний КП', 55.5100, 37.5100, 'Соседний КП' ),
	$location( 1220, 'Московская область', 'Москва', 'Случайный', 55.5200, 37.5200, 'Случайный' ),
	$location( 1230, 'Московская область', 'Москва', 'Дальняя точка', 55.0000, 37.3200, 'Дальняя точка' ),
	$location( 1240, 'Тестовая область', '', '', 52.5000, 39.5000, 'Петровка', '', '', 'слобода' ),
	$location( 1250, 'Москва', '', '', 55.3300, 37.1300, 'Львово', '', '', 'д' ),
	$location( 1260, 'Москва', '', '', 55.3400, 37.1400, 'Зверево', '', '', 'снт' ),
	$location( 1270, 'Московская область', 'Москва', 'Соседний Строитель', 55.3500, 37.1500, 'Соседний Строитель' ),
	$location( 1280, 'Тестовая область', '', 'Пирогово', 52.6004, 39.6000, 'Пирогово', '', 'д', 'д' ),
	$location( 1281, 'Тестовая область', '', 'Пирогово', 52.6030, 39.6000, 'Пирогово', '', 'п', 'п' ),
	$location( 1290, 'Тестовая область', '', 'Покровское', 52.7020, 39.7000, 'Покровское', '', 'с', 'с' ),
	$location( 1291, 'Тестовая область', '', 'Покровское', 52.7003, 39.7000, 'Покровское', '', 'п', 'п' ),
	$location( 1300, 'Тестовая область', '', 'Станционный', 52.8005, 39.8000, 'Станционный', '', '', 'ст' ),
	$location( 1310, 'Московская область', 'Москва рядом', 'Случайный город', 55.5300, 37.5300, 'Случайный город' ),
	$location( 1320, 'Тестовая область', '', 'Макарово', 52.9000, 39.9000, 'Макарово' ),
	$location( 1321, 'Тестовая область', '', 'Макарово', 52.9550, 39.9000, 'Макарово' ),
	$location( 1330, 'Ямало-Ненецкий', 'Новый Уренгой', '', 65.9290, 78.1904, '', 'г' ),
	$location( 1340, 'Москва', 'Москва', '', 55.7558, 37.6173, '', 'г' ),
	$location( 1341, 'Москва', 'Щербинка', '', 55.5001, 37.5600, '', 'г' ),
	$location( 1342, 'Московская область', '', 'Щербинка', 55.5010, 37.5600, 'Щербинка', '', 'д', 'д' ),
);
$GLOBALS['wpdb']->yandex_region_mapping_v2 = array();
$region_repository = new YandexRegionMappingV2Repository( $GLOBALS['wpdb'] );
$manual_override_repository = new YandexLocationManualOverrideV2Repository( $GLOBALS['wpdb'], $normalizer );
$manual_schema = $manual_override_repository->schema();
foreach ( array( 'wp_wdc_yandex_location_manual_overrides_v2', 'yandex_geo_id bigint(20) unsigned NOT NULL', 'yandex_region_norm varchar(255) NOT NULL', 'yandex_locality_norm varchar(255) NOT NULL', 'location_id bigint(20) unsigned NOT NULL', 'KEY yandex_identity (yandex_region_norm, yandex_locality_norm)', 'KEY status (status)' ) as $needle ) {
	yd_location_mapping_v2_assert( str_contains( $manual_schema, $needle ), 'Manual override schema must contain: ' . $needle );
}
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
	'Краснодарский край' => array( 'Краснодарский край' ),
	'Смоленская область' => array( 'Смоленская область' ),
	'Ленинградская область' => array( 'Ленинградская область' ),
	'Курганская область' => array( 'Курганская область' ),
	'Липецкая область' => array( 'Липецкая область' ),
	'Регион без выбора' => array( '' ),
	'Ямало-Ненецкий автономный округ' => array( 'Ямало-Ненецкий' ),
) as $yandex_region => $wdc_regions ) {
	$region_repository->save_mapping( $yandex_region, $wdc_regions );
}

$mapper = new YandexLocationMapperV2Service( $repository, $GLOBALS['wpdb'], null, $region_repository );
$result = $mapper->build_all( 50, 0 );
yd_location_mapping_v2_assert( 44 === $result['processed_geo_ids'] && 33 === $result['mapped'] && 4 === $result['needs_review'] && 7 === $result['no_match'] && true === $result['done'], 'Mapper batch must classify mapped, needs_review, and no_match geo ids.' );
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
yd_location_mapping_v2_assert( ! array_key_exists( 'sql_search_terms', $raw400 ) && array_key_exists( 'candidate_count_before_filters', $raw400 ) && array_key_exists( 'candidate_count_after_filters', $raw400 ), 'no_match raw_json must keep compact candidate counters without sql_search_terms.' );
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
yd_location_mapping_v2_assert( 1 === count( $geo1000 ) && 1000 === (int) $geo1000[0]['location_id'] && true === $raw1000['dominance_auto_pick'] && 'near_exact_type_dominates' === $raw1000['dominance_reason'] && 20 === (int) $raw1000['type_score'] && ! array_key_exists( 'rejected_candidates', $raw1000 ), 'Kirzhach must auto-map to city by distance dominance.' );
$geo1010 = $repository->find_by_geo( 1010 );
$raw1010 = json_decode( (string) $geo1010[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1010 ) && 1010 === (int) $geo1010[0]['location_id'] && true === $raw1010['dominance_auto_pick'] && 'near_exact_type_dominates' === $raw1010['dominance_reason'], 'Ertil must auto-map to city by distance dominance.' );
$geo1020 = $repository->find_by_geo( 1020 );
$raw1020 = json_decode( (string) $geo1020[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1020 ) && 1020 === (int) $geo1020[0]['location_id'] && true === $raw1020['dominance_auto_pick'] && 'near_exact_type_dominates' === $raw1020['dominance_rule'] && 'urban' === $raw1020['yandex_type'] && 'urban' === $raw1020['wdc_type'], 'Bykovo must auto-map to urban settlement by type dominance.' );
$geo1030 = $repository->find_by_geo( 1030 );
yd_location_mapping_v2_assert( 2 === count( $geo1030 ) && 'needs_review' === $geo1030[0]['status'], 'Equal nearby same-type candidates must stay needs_review.' );
$geo1040 = $repository->find_by_geo( 1040 );
$raw1040 = json_decode( (string) $geo1040[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1040 ) && 'mapped' === $geo1040[0]['status'] && 1040 === (int) $geo1040[0]['location_id'] && 'region_mapping_exact_first' === $raw1040['candidate_search_mode'] && in_array( 'Омская область', $raw1040['mapped_regions'], true ) && (int) $raw1040['region_after_filters'] > 0, 'Region mapping search must map Omsk inside selected WDC regions.' );
$geo1050 = $repository->find_by_geo( 1050 );
$raw1050 = json_decode( (string) $geo1050[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1050 ) && 'no_match' === $geo1050[0]['status'] && 0 === (int) $geo1050[0]['location_id'] && 0 === (int) $raw1050['candidate_count_after_filters'] && 'no_locality_match' === $raw1050['reason'], 'Parent city name must not match a lower place effective locality.' );
$geo1060 = $repository->find_by_geo( 1060 );
$raw1060 = json_decode( (string) $geo1060[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1060 ) && 'mapped' === $geo1060[0]['status'] && 1060 === (int) $geo1060[0]['location_id'] && ! array_key_exists( 'deduped_location_ids', $raw1060 ), 'Duplicate WDC candidates must be deduped into one mapping row.' );
$geo1070 = $repository->find_by_geo( 1070 );
$raw1070 = json_decode( (string) $geo1070[0]['raw_json'], true );
$matched_by1070 = json_decode( (string) $geo1070[0]['matched_by_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1070 ) && 'mapped' === $geo1070[0]['status'] && in_array( 'coordinates', $matched_by1070, true ) && true === (bool) ( $raw1070['address_locality_used'] ?? false ) && empty( $raw1070['territory_fallback'] ), 'Admin-like geo must prefer specific locality term over parent city address fallback.' );
$geo1080 = $repository->find_by_geo( 1080 );
$raw1080 = json_decode( (string) $geo1080[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1080 ) && 'no_match' === $geo1080[0]['status'] && false === $raw1080['territory_fallback'] && 'no_locality_match' === $raw1080['reason'], 'Territorial-like geo without a nearby WDC location must stay no_match.' );
$geo1090 = $repository->find_by_geo( 1090 );
$raw1090 = json_decode( (string) $geo1090[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1090 ) && 'no_match' === $geo1090[0]['status'] && 'region_not_mapped' === $raw1090['reason'] && 'region_mapping_exact_first' === $raw1090['candidate_search_mode'] && array() === $raw1090['mapped_regions'], 'Unselected region mapping must skip search and become region_not_mapped.' );

$geo1100 = $repository->find_by_geo( 1100 );
$raw1100 = json_decode( (string) $geo1100[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1100 ) && 'mapped' === $geo1100[0]['status'] && 1100 === (int) $geo1100[0]['location_id'] && true === $raw1100['dominance_auto_pick'], 'Kurtamysh city must auto-map over hamlet.' );
$geo1110 = $repository->find_by_geo( 1110 );
$raw1110 = json_decode( (string) $geo1110[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1110 ) && 'mapped' === $geo1110[0]['status'] && 1110 === (int) $geo1110[0]['location_id'] && 'near_exact_type_dominates' === $raw1110['dominance_rule'], 'Semenkovo settlement must auto-map over hamlet by type priority.' );
$geo1120 = $repository->find_by_geo( 1120 );
$raw1120 = json_decode( (string) $geo1120[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1120 ) && 'mapped' === $geo1120[0]['status'] && 1120 === (int) $geo1120[0]['location_id'] && 'near_exact_type_dominates' === $raw1120['dominance_rule'], 'Volovo urban settlement must auto-map over village by type priority.' );
$geo1130 = $repository->find_by_geo( 1130 );
$raw1130 = json_decode( (string) $geo1130[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1130 ) && 'mapped' === $geo1130[0]['status'] && 1130 === (int) $geo1130[0]['location_id'] && 'base_place' === $raw1130['dominance_rule'], 'Dubovka base place must auto-map over quarter variants.' );
$geo1140 = $repository->find_by_geo( 1140 );
$raw1140 = json_decode( (string) $geo1140[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1140 ) && 'mapped' === $geo1140[0]['status'] && 1140 === (int) $geo1140[0]['location_id'] && ! array_key_exists( 'deduped_location_ids', $raw1140 ), 'Kamyshivakha duplicate FIAS-like rows must map to smaller location id.' );
$geo1150 = $repository->find_by_geo( 1150 );
$raw1150 = json_decode( (string) $geo1150[0]['raw_json'], true );
yd_location_mapping_v2_assert( 2 === count( $geo1150 ) && 'needs_review' === $geo1150[0]['status'] && false === $raw1150['dominance_auto_pick'] && 'ambiguous' === $raw1150['dominance_reason'], 'Zarechye across Moscow and Moscow region must stay needs_review.' );


$geo1160 = $repository->find_by_geo( 1160 );
$raw1160 = json_decode( (string) $geo1160[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1160 ) && 'mapped' === $geo1160[0]['status'] && 'region_mapping_exact_first' === $raw1160['candidate_search_mode'] && 1 === (int) $raw1160['exact_candidates'], 'Stanitsa Vyselki must map through exact-first name variants.' );
$geo1170 = $repository->find_by_geo( 1170 );
$raw1170 = json_decode( (string) $geo1170[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1170 ) && 'mapped' === $geo1170[0]['status'] && 'city' === $raw1170['wdc_type'], 'Pochinok city alias must map through exact-first name variants.' );
$geo1180 = $repository->find_by_geo( 1180 );
$raw1180 = json_decode( (string) $geo1180[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1180 ) && 'mapped' === $geo1180[0]['status'] && 'urban' === $raw1180['wdc_type'], 'Named urban settlement must map through exact-first name variants.' );
$geo1190 = $repository->find_by_geo( 1190 );
$raw1190 = json_decode( (string) $geo1190[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1190 ) && 'mapped' === $geo1190[0]['status'] && 'station' === $raw1190['wdc_type'], 'Railway station settlement must map through exact-first name variants.' );
$geo1200 = $repository->find_by_geo( 1200 );
$raw1200 = json_decode( (string) $geo1200[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1200 ) && 'mapped' === $geo1200[0]['status'] && true === $raw1200['territory_fallback'] && 'territory_like_coordinate_match' === $raw1200['territory_fallback_reason'], 'SNT territory-like geo must map through territory coordinate fallback.' );
$geo1210 = $repository->find_by_geo( 1210 );
$raw1210 = json_decode( (string) $geo1210[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1210 ) && 'mapped' === $geo1210[0]['status'] && true === $raw1210['territory_fallback'], 'Cottage settlement territory-like geo must map through territory coordinate fallback.' );
$geo1220 = $repository->find_by_geo( 1220 );
$raw1220 = json_decode( (string) $geo1220[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1220 ) && 'no_match' === $geo1220[0]['status'] && false === $raw1220['territory_fallback'], 'Ordinary city must not use territory coordinate fallback.' );
$geo1230 = $repository->find_by_geo( 1230 );
$raw1230 = json_decode( (string) $geo1230[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1230 ) && 'mapped' === $geo1230[0]['status'] && true === $raw1230['territory_fallback'] && isset( $raw1230['territory_bbox']['lat_delta'], $raw1230['territory_bbox']['lon_delta'] ) && $raw1230['territory_bbox']['lat_delta'] > 0.2 && $raw1230['territory_bbox']['lon_delta'] > 0.3, 'Territory fallback must use dynamic bbox from 25 km radius.' );
$geo1240 = $repository->find_by_geo( 1240 );
$raw1240 = json_decode( (string) $geo1240[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1240 ) && 'mapped' === $geo1240[0]['status'] && 'Петровка' === $raw1240['locality_raw'], 'Sloboda Petrovka must map through base locality.' );
$geo1250 = $repository->find_by_geo( 1250 );
$raw1250 = json_decode( (string) $geo1250[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1250 ) && 'mapped' === $geo1250[0]['status'] && true === $raw1250['address_locality_used'] && 'деревня Львово' === (string) ( $raw1250['address_locality_term'] ?? '' ) && ! array_key_exists( 'address_locality_terms', $raw1250 ), 'Address-derived village Lvovo must map when Yandex locality is broad Moscow.' );
$geo1260 = $repository->find_by_geo( 1260 );
$raw1260 = json_decode( (string) $geo1260[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1260 ) && 'mapped' === $geo1260[0]['status'] && true === $raw1260['address_locality_used'] && str_contains( (string) ( $raw1260['address_locality_term'] ?? '' ), 'Зверево' ) && ! array_key_exists( 'address_locality_terms', $raw1260 ), 'Sample address SNT Zverevo must provide address locality terms.' );
$geo1270 = $repository->find_by_geo( 1270 );
$raw1270 = json_decode( (string) $geo1270[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1270 ) && 'mapped' === $geo1270[0]['status'] && true === $raw1270['territory_fallback'], 'Gardening non-commercial partnership must use territory fallback.' );
$geo1280 = $repository->find_by_geo( 1280 );
$raw1280 = json_decode( (string) $geo1280[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1280 ) && 'mapped' === $geo1280[0]['status'] && true === $raw1280['dominance_auto_pick'] && 'near_exact_type_dominates' === $raw1280['dominance_rule'], 'Pirogovo village must auto-map by close type score priority.' );
$geo1290 = $repository->find_by_geo( 1290 );
$raw1290 = json_decode( (string) $geo1290[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1290 ) && 'mapped' === $geo1290[0]['status'] && true === $raw1290['dominance_auto_pick'] && 'near_exact_type_dominates' === $raw1290['dominance_rule'], 'Pokrovskoe village must beat closer wrong type by type-aware dominance.' );
$geo1300 = $repository->find_by_geo( 1300 );
$raw1300 = json_decode( (string) $geo1300[0]['raw_json'], true );
$matchedBy1300 = json_decode( (string) $geo1300[0]['matched_by_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1300 ) && 'mapped' === $geo1300[0]['status'] && true === $raw1300['coordinate_fallback_strict'] && in_array( 'coordinates_strict', $matchedBy1300, true ) && 1 === (int) $geo1300[0]['coordinate_match'], 'Station-like no_match must use strict coordinate fallback.' );
$geo1320 = $repository->find_by_geo( 1320 );
$raw1320 = json_decode( (string) $geo1320[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1320 ) && 'mapped' === $geo1320[0]['status'] && true === $raw1320['dominance_auto_pick'] && 'same_type_nearest_dominates' === $raw1320['dominance_rule'], 'Makarovo same-type candidates must auto-map by nearest distance dominance.' );
$geo1310 = $repository->find_by_geo( 1310 );
$raw1310 = json_decode( (string) $geo1310[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1310 ) && 'no_match' === $geo1310[0]['status'] && empty( $raw1310['coordinate_fallback_strict'] ), 'Large city locality must not auto-map through coordinate-only fallback.' );

$geo1330 = $repository->find_by_geo( 1330 );
$raw1330 = json_decode( (string) $geo1330[0]['raw_json'], true );
$matchedBy1330 = json_decode( (string) $geo1330[0]['matched_by_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo1330 ) && 'mapped' === $geo1330[0]['status'] && 1330 === (int) $geo1330[0]['location_id'] && in_array( 'locality', $matchedBy1330, true ) && in_array( 'coordinates', $matchedBy1330, true ) && empty( $raw1330['coordinate_fallback_strict'] ) && 'no_locality_match' !== (string) ( $raw1330['reason'] ?? '' ), 'Novy Urengoy city suffix must map through normal exact path.' );
$geo1340 = $repository->find_by_geo( 1340 );
$ids1340 = array_map( static fn( array $row ): int => (int) $row['location_id'], $geo1340 );
$raw1340 = json_decode( (string) $geo1340[0]['raw_json'], true );
yd_location_mapping_v2_assert( array() !== $geo1340 && ! in_array( 1340, $ids1340, true ) && in_array( 1341, $ids1340, true ) && true === (bool) ( $raw1340['address_locality_used'] ?? false ), 'Shcherbinka address fallback must not include parent Moscow candidate when specific Shcherbinka terms exist.' );
$recent_no_match = $repository->find_recent_no_match( 20 );
yd_location_mapping_v2_assert( count( $recent_no_match ) >= 5 && ! array_key_exists( 'sql_search_terms', $recent_no_match[0] ) && array_key_exists( 'centroid_lat', $recent_no_match[0] ) && array_key_exists( 'centroid_lon', $recent_no_match[0] ), 'Repository must return recent no_match diagnostics with sql_search_terms and Yandex coordinates.' );
$review_items_before_override = $repository->find_recent_review_items( 50 );
$review_with_candidate_coordinates = null;
foreach ( $review_items_before_override as $review_item ) {
	if ( (int) ( $review_item['location_id'] ?? 0 ) > 0 ) {
		$review_with_candidate_coordinates = $review_item;
		break;
	}
}
yd_location_mapping_v2_assert( null !== $review_with_candidate_coordinates && array_key_exists( 'centroid_lat', $review_with_candidate_coordinates ) && array_key_exists( 'centroid_lon', $review_with_candidate_coordinates ) && array_key_exists( 'candidate_latitude', $review_with_candidate_coordinates ) && array_key_exists( 'candidate_longitude', $review_with_candidate_coordinates ), 'Review queue rows must expose Yandex and WDC candidate coordinates.' );
$no_match_override_item = null;
foreach ( $recent_no_match as $recent_no_match_item ) {
	if ( '' !== trim( (string) ( $recent_no_match_item['region'] ?? '' ) ) && '' !== trim( (string) ( $recent_no_match_item['locality'] ?? '' ) ) ) {
		$no_match_override_item = $recent_no_match_item;
		break;
	}
}
yd_location_mapping_v2_assert( null !== $no_match_override_item, 'Smoke fixture must include a no_match row with region and locality for manual override.' );
$no_match_override_geo_id = (int) ( $no_match_override_item['yandex_geo_id'] ?? 0 );
$no_match_override_report = $manual_override_repository->upsert_active_override( $no_match_override_geo_id, (string) ( $no_match_override_item['region'] ?? '' ), (string) ( $no_match_override_item['locality'] ?? '' ), 10 );
yd_location_mapping_v2_assert( 1 === (int) ( $no_match_override_report['saved'] ?? 0 ), 'Manual override for no_match queue fixture must be saved.' );
$recent_no_match_after_override = $repository->find_recent_no_match( 20 );
yd_location_mapping_v2_assert( $no_match_override_geo_id > 0 && ! in_array( $no_match_override_geo_id, array_map( static fn( array $row ): int => (int) ( $row['yandex_geo_id'] ?? 0 ), $recent_no_match_after_override ), true ), 'Recent no_match queue must hide rows with active manual override.' );
$needs_review_counts = array();
$needs_review_first = array();
foreach ( $review_items_before_override as $review_item ) {
	if ( 'needs_review' !== (string) ( $review_item['status'] ?? '' ) || (int) ( $review_item['location_id'] ?? 0 ) <= 0 ) {
		continue;
	}
	$geo_id = (int) ( $review_item['yandex_geo_id'] ?? 0 );
	$needs_review_counts[ $geo_id ] = ( $needs_review_counts[ $geo_id ] ?? 0 ) + 1;
	$needs_review_first[ $geo_id ] = $needs_review_first[ $geo_id ] ?? $review_item;
}
$needs_review_override_item = null;
foreach ( $needs_review_counts as $geo_id => $count ) {
	if ( $count > 1 ) {
		$needs_review_override_item = $needs_review_first[ $geo_id ];
		break;
	}
}
yd_location_mapping_v2_assert( null !== $needs_review_override_item, 'Smoke fixture must include a multi-candidate needs_review row.' );
$needs_review_override_geo_id = (int) ( $needs_review_override_item['yandex_geo_id'] ?? 0 );
$manual_override_repository->upsert_active_override( $needs_review_override_geo_id, (string) ( $needs_review_override_item['region'] ?? '' ), (string) ( $needs_review_override_item['locality'] ?? '' ), (int) ( $needs_review_override_item['location_id'] ?? 0 ) );
$review_items_after_override = $repository->find_recent_review_items( 50 );
yd_location_mapping_v2_assert( ! in_array( $needs_review_override_geo_id, array_map( static fn( array $row ): int => (int) ( $row['yandex_geo_id'] ?? 0 ), $review_items_after_override ), true ), 'Review queue must hide all needs_review variants for a geo_id after manual override is saved.' );
$active_queue_overrides = $manual_override_repository->list_active( 50 );
$active_queue_override_ids = array_map( static fn( array $row ): int => (int) ( $row['yandex_geo_id'] ?? 0 ), $active_queue_overrides );
yd_location_mapping_v2_assert( in_array( $no_match_override_geo_id, $active_queue_override_ids, true ) && in_array( $needs_review_override_geo_id, $active_queue_override_ids, true ), 'Active manual overrides must remain visible in active override list after queues hide them.' );
$stats = $repository->statistics();
yd_location_mapping_v2_assert( 48 === $stats['total'] && 33 === $stats['mapped'] && 8 === $stats['needs_review'] && 7 === $stats['no_match'] && 1 === $stats['no_match_region_not_mapped'] && 6 === $stats['no_match_no_locality_match'] && 4 === $stats['territory_fallback'] && null !== $stats['avg_confidence'] && null !== $stats['avg_distance'] && isset( $stats['mapped_by_dominance']['near_exact_type_dominates'] ) && isset( $stats['mapped_by_dominance']['same_type_nearest_dominates'] ), 'Repository statistics must count statuses and averages.' );

$manual_mapper = new YandexLocationMapperV2Service( $repository, $GLOBALS['wpdb'], null, $region_repository, $manual_override_repository );
$GLOBALS['wpdb']->yandex_location_manual_overrides_v2 = array();
$manual_override_repository->upsert_active_override( 9000, 'Новосибирская область', 'Ручной город', 10, 'geo identity' );
$manual_override_cache = $manual_override_repository->load_active_overrides_cache();
$manual_override_identity_key = $manual_override_repository->normalize_region( 'Новосибирская область' ) . '|' . $manual_override_repository->normalize_locality( 'Ручной город' );
yd_location_mapping_v2_assert( isset( $manual_override_cache['by_geo_id'][9000] ) && 1 === count( $manual_override_cache['by_geo_id'][9000] ) && isset( $manual_override_cache['by_identity'][ $manual_override_identity_key ] ) && 1 === count( $manual_override_cache['by_identity'][ $manual_override_identity_key ] ) && ! isset( $manual_override_cache['ambiguous_identity_keys'][ $manual_override_identity_key ] ), 'Manual override repository must preload active overrides into geo_id and identity cache indexes.' );
$manual_rows = $manual_mapper->map_geo_row( $geo( 9000, 'Новосибирская область', 'Ручной город', 55.0302, 82.9204 ) );
$manual_raw = json_decode( (string) $manual_rows[0]['raw_json'], true );
$manual_matched = json_decode( (string) $manual_rows[0]['matched_by_json'], true );
yd_location_mapping_v2_assert( 1 === count( $manual_rows ) && 'mapped' === $manual_rows[0]['status'] && 10 === (int) $manual_rows[0]['location_id'] && true === $manual_raw['manual_override'] && 'geo_identity' === $manual_raw['manual_override_match'] && 'exact_geo_id' === $manual_raw['manual_override_match_mode'] && in_array( 'manual_override', $manual_matched, true ), 'Manual override must map exact geo identity before normal search.' );

$first_shifted = $manual_override_repository->upsert_active_override( 9010, 'Новосибирская область', 'Сменный город', 10, 'old shifted identity' );
$second_shifted = $manual_override_repository->upsert_active_override( 9011, 'Новосибирская область', 'Сменный город', 21, 'new shifted identity' );
$active_shifted = $manual_override_repository->find_active_for_identity( 'Новосибирская область', 'Сменный город' );
$old_shifted_rows = array_values( array_filter( $GLOBALS['wpdb']->yandex_location_manual_overrides_v2, static fn( array $row ): bool => (int) ( $row['id'] ?? 0 ) === (int) $first_shifted['id'] ) );
yd_location_mapping_v2_assert( (int) $first_shifted['id'] > 0 && (int) $second_shifted['id'] > 0 && 1 === count( $active_shifted ) && 21 === (int) $active_shifted[0]['location_id'] && 'inactive' === (string) ( $old_shifted_rows[0]['status'] ?? '' ), 'Saving a new override for the same region/locality identity must deactivate the old geo_id override.' );
$manual_mapper = new YandexLocationMapperV2Service( $repository, $GLOBALS['wpdb'], null, $region_repository, $manual_override_repository );
$manual_rows = $manual_mapper->map_geo_row( $geo( 9012, 'Новосибирская область', 'Сменный город', 54.7600, 83.1100 ) );
$manual_raw = json_decode( (string) $manual_rows[0]['raw_json'], true );
yd_location_mapping_v2_assert( 'mapped' === $manual_rows[0]['status'] && 21 === (int) $manual_rows[0]['location_id'] && 'manual_override_identity_ambiguous' !== (string) ( $manual_raw['reason'] ?? '' ) && true === $manual_raw['manual_override_geo_id_changed'], 'Mapper must apply the newest active logical override without ambiguity after geo_id changes.' );

$manual_override_repository->upsert_active_override( 9001, 'Новосибирская область', 'Логический город', 20, 'logical identity' );
$manual_mapper = new YandexLocationMapperV2Service( $repository, $GLOBALS['wpdb'], null, $region_repository, $manual_override_repository );
$manual_rows = $manual_mapper->map_geo_row( $geo( 9002, 'Новосибирская область', 'Логический город', 54.7582, 83.1072 ) );
$manual_raw = json_decode( (string) $manual_rows[0]['raw_json'], true );
yd_location_mapping_v2_assert( 'mapped' === $manual_rows[0]['status'] && 20 === (int) $manual_rows[0]['location_id'] && 'region_locality_identity' === $manual_raw['manual_override_match'] && 'logical_identity' === $manual_raw['manual_override_match_mode'] && true === $manual_raw['manual_override_geo_id_changed'] && 9001 === (int) $manual_raw['previous_yandex_geo_id'], 'Manual override must survive geo_id changes when region/locality identity is the same.' );

$manual_override_repository->upsert_active_override( 9003, 'Новосибирская область', 'Старый город', 10, 'identity mismatch' );
$manual_mapper = new YandexLocationMapperV2Service( $repository, $GLOBALS['wpdb'], null, $region_repository, $manual_override_repository );
$manual_rows = $manual_mapper->map_geo_row( $geo( 9003, 'Новосибирская область', 'Новый город', 55.0302, 82.9204 ) );
$manual_raw = json_decode( (string) $manual_rows[0]['raw_json'], true );
yd_location_mapping_v2_assert( 'mapped' !== $manual_rows[0]['status'] && true === $manual_raw['manual_override_identity_mismatch'] && 'Старый город' === $manual_raw['manual_override_expected_locality'], 'Manual override must not apply when same geo_id has different locality identity.' );

$double_region_norm = $manual_override_repository->normalize_region( 'Новосибирская область' );
$double_locality_norm = $manual_override_repository->normalize_locality( 'Двойной город' );
$GLOBALS['wpdb']->yandex_location_manual_overrides_v2[] = array( 'id' => 9904, 'yandex_geo_id' => 9004, 'yandex_region' => 'Новосибирская область', 'yandex_region_norm' => $double_region_norm, 'yandex_locality' => 'Двойной город', 'yandex_locality_norm' => $double_locality_norm, 'location_id' => 10, 'status' => 'active', 'updated_at' => '2026-06-26 12:00:00' );
$GLOBALS['wpdb']->yandex_location_manual_overrides_v2[] = array( 'id' => 9905, 'yandex_geo_id' => 9005, 'yandex_region' => 'Новосибирская область', 'yandex_region_norm' => $double_region_norm, 'yandex_locality' => 'Двойной город', 'yandex_locality_norm' => $double_locality_norm, 'location_id' => 20, 'status' => 'active', 'updated_at' => '2026-06-26 12:00:00' );
$manual_mapper = new YandexLocationMapperV2Service( $repository, $GLOBALS['wpdb'], null, $region_repository, $manual_override_repository );
$manual_rows = $manual_mapper->map_geo_row( $geo( 9006, 'Новосибирская область', 'Двойной город', 55.0302, 82.9204 ) );
$manual_raw = json_decode( (string) $manual_rows[0]['raw_json'], true );
yd_location_mapping_v2_assert( 'needs_review' === $manual_rows[0]['status'] && 'manual_override_identity_ambiguous' === $manual_raw['reason'], 'Ambiguous logical manual override identity must not auto-apply.' );

$manual_override_repository->upsert_active_override( 9007, 'Новосибирская область', 'Битый город', 999999, 'missing location' );
$manual_mapper = new YandexLocationMapperV2Service( $repository, $GLOBALS['wpdb'], null, $region_repository, $manual_override_repository );
$manual_rows = $manual_mapper->map_geo_row( $geo( 9007, 'Новосибирская область', 'Битый город', 55.0302, 82.9204 ) );
$manual_raw = json_decode( (string) $manual_rows[0]['raw_json'], true );
yd_location_mapping_v2_assert( 'no_match' === $manual_rows[0]['status'] && 'manual_override_location_missing' === $manual_raw['reason'], 'Manual override with missing WDC location must not apply.' );
$runner_repository = new YandexLocationMappingV2Repository( $GLOBALS['wpdb'] );
$runner = new YandexLocationMappingV2Runner( new YandexLocationMapperV2Service( $runner_repository, $GLOBALS['wpdb'], null, $region_repository ), $runner_repository );
$state = $runner->start();
yd_location_mapping_v2_assert( 'mapping' === $state['status'] && 0 === count( $GLOBALS['wpdb']->yandex_location_mapping_v2 ), 'Runner start must truncate and switch to mapping.' );
$state = $runner->run_step();
yd_location_mapping_v2_assert( 'done' === $state['status'] && 44 === $state['processed'] && 33 === $state['mapped'] && 4 === $state['needs_review'] && 7 === $state['no_match'], 'Runner first step must process the mapping batch.' );
$state = $runner->run_step();
yd_location_mapping_v2_assert( 'done' === $state['status'] && 44 === $state['processed'] && 33 === $state['mapped'] && 4 === $state['needs_review'] && 7 === $state['no_match'], 'Runner second step must keep completed mapping state.' );
$state = $runner->run_step();
yd_location_mapping_v2_assert( 'done' === $state['status'] && 44 === $state['processed'] && 33 === $state['mapped'] && 4 === $state['needs_review'] && 7 === $state['no_match'], 'Runner third step must keep completed mapping state.' );
$state = $runner->run_step();
yd_location_mapping_v2_assert( 'done' === $state['status'] && 44 === $state['processed'] && 33 === $state['mapped'] && 4 === $state['needs_review'] && 7 === $state['no_match'] && 1 === $state['region_not_mapped'] && 6 === $state['no_locality_match'] && 4 === $state['territory_fallback'] && null !== $state['avg_confidence'] && null !== $state['avg_distance'], 'Runner step must update mapping counters and averages.' );
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
yd_location_mapping_v2_assert( str_contains( $plugin_source, 'YandexLocationMappingV2Repository::class' ) && str_contains( $plugin_source, 'YandexLocationMapperV2Service::class' ) && str_contains( $plugin_source, 'YandexLocationMappingV2Runner::class' ) && str_contains( $plugin_source, 'YandexLocationManualOverrideV2Repository::class' ) && str_contains( $plugin_source, 'YandexRegionMappingV2Repository::class' ), 'Plugin DI must register location mapping v2 services.' );
yd_location_mapping_v2_assert( ! str_contains( $mapper_source, 'locationDetect' ) && ! str_contains( $mapper_source, 'YandexDeliveryApiClient' ) && ! str_contains( $mapper_source, '/api/' ), 'Location mapping v2 mapper must stay offline and not call Yandex API.' );
yd_location_mapping_v2_assert( str_contains( $mapper_source, 'manual_override_decision' ) && str_contains( $mapper_source, 'manual_override_identity_mismatch' ) && str_contains( $mapper_source, 'manual_override_applied' ) && str_contains( $mapper_source, 'load_active_overrides_cache' ) && str_contains( $mapper_source, 'manual_override_cache' ) && ! str_contains( $mapper_source, 'find_active_for_geo_identity' ) && ! str_contains( $mapper_source, 'find_active_for_identity' ) && ! str_contains( $mapper_source, 'find_active_for_geo_id' ) && str_contains( $mapper_source, 'is_territory_like_geo' ) && str_contains( $mapper_source, 'region_mapping_exact_first' ) && str_contains( $mapper_source, 'territory_fallback_reason' ) && str_contains( $mapper_source, 'territory_bbox' ) && str_contains( $mapper_source, 'address_locality_terms' ) && str_contains( $mapper_source, 'coordinate_fallback_strict' ) && str_contains( $mapper_source, 'ambiguous_reason' ) && str_contains( $mapper_source, 'find_wdc_regions_for_yandex' ) && str_contains( $mapper_source, 'fetch_locations_by_regions' ) && str_contains( $mapper_source, 'region_name IN' ) && str_contains( $mapper_source, 'candidate_search_mode' ) && str_contains( $mapper_source, 'region_mapping' ) && ! str_contains( $mapper_source, 'region_name LIKE' ) && ! str_contains( $mapper_source, 'regions_compatible' ) && ! str_contains( $mapper_source, 'fetch_exact_location_candidates' ) && ! str_contains( $mapper_source, 'fetch_location_candidates' ) && ! str_contains( $mapper_source, 'broad_fallback' ) && str_contains( $mapper_source, 'effective_location_locality' ) && ! str_contains( $mapper_source, 'location_locality_variants' ) && ! str_contains( $mapper_source, 'matching_location_locality' ) && str_contains( $mapper_source, 'choose_dominant_candidate' ) && str_contains( $mapper_source, 'dominance_rule' ) && str_contains( $mapper_source, 'dominance_auto_pick' ) && str_contains( $mapper_source, 'dedupe_candidates' ) && str_contains( $mapper_source, 'territory_coordinate_fallback' ) && str_contains( $mapper_source, 'detect_locality_type' ) && str_contains( $mapper_source, 'type_match_score' ) && str_contains( $mapper_source, 'dominance_auto_pick' ) && str_contains( $mapper_source, 'rejected_candidates' ) && str_contains( $mapper_source, 'locality_source' ) && str_contains( $mapper_source, 'locality_raw' ) && str_contains( $mapper_source, 'effective_locality' ), 'Mapper source must use region mapping, manual overrides, dedupe, territorial fallback, and must not use old region heuristics.' );
yd_location_mapping_v2_assert( str_contains( (string) file_get_contents( __FILE__ ), 'станица Выселки' ) && str_contains( $normalizer_source, 'поселок при железнодорожной станции' ) && str_contains( $normalizer_source, 'is_territorial_like' ) && str_contains( $normalizer_source, 'городской поселок' ) && str_contains( $normalizer_source, 'железнодорожная станция' ) && str_contains( $normalizer_source, 'without_parentheses' ), 'Normalizer source must contain new locality type and territorial helpers.' );
yd_location_mapping_v2_assert( str_contains( $admin_source, 'Ручные override маппинга Яндекс v2' ) && str_contains( $admin_source, 'save_yandex_location_manual_override_v2' ) && str_contains( $admin_source, 'deactivate_yandex_location_manual_override_v2' ) && str_contains( $admin_source, 'centroid_lat' ) && str_contains( $admin_source, 'centroid_lon' ) && str_contains( $admin_source, 'candidate_latitude' ) && str_contains( $admin_source, 'candidate_longitude' ) && str_contains( $admin_source, 'yandex_location_mapping_v2_coordinates' ) && ! str_contains( $admin_source, 'name="note"' ), 'Admin UI must render manual override controls with coordinates and without note field.' );
yd_location_mapping_v2_assert( str_contains( $admin_source, 'Последние no_match' ) && str_contains( $admin_source, 'updated_at' ) && ! str_contains( $admin_source, '<th>sql_search_terms</th>' ), 'Admin UI must render recent no_match diagnostics.' );
yd_location_mapping_v2_assert( str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMappingV2Repository.php' ), 'find_recent_no_match' ) && str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMappingV2Repository.php' ), 'find_recent_review_items' ), 'Repository must expose recent no_match and review diagnostics.' );
yd_location_mapping_v2_assert( str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationManualOverrideV2Repository.php' ), 'find_active_for_geo_identity' ) && str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationManualOverrideV2Repository.php' ), 'load_active_overrides_cache' ) && str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationManualOverrideV2Repository.php' ), 'ambiguous_identity_keys' ) && str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationManualOverrideV2Repository.php' ), 'deactivate_active_identity' ) && ! str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationManualOverrideV2Repository.php' ), 'deactivate_active_identity_for_geo' ) && str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0040_create_yandex_location_manual_overrides_v2.php' ), 'YandexLocationManualOverrideV2Repository' ), 'Manual override repository and migration must exist.' );
yd_location_mapping_v2_assert( str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0038_create_yandex_location_mapping_v2.php' ), 'YandexLocationMappingV2Repository' ), 'Migration 0038 must create mapping v2 schema via repository.' );

echo "Yandex Delivery location mapping v2 smoke OK\n";

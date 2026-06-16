<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $delivery_codes = array();
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
	}
}

function current_time( string $type ): string {
	static $tick = 0;
	++$tick;

	return '2026-06-16 12:10:' . str_pad( (string) $tick, 2, '0', STR_PAD_LEFT );
}

function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_import_options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value, bool $autoload = true ): bool { $GLOBALS['wdc_dpd_import_options'][ $name ] = $value; return true; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags | JSON_UNESCAPED_UNICODE ); }
function wp_salt( string $scheme = '' ): string { return 'wdc-test-salt-' . $scheme; }

require_once __DIR__ . '/../../src/Domain/Status/DeliveryStatus.php';
require_once __DIR__ . '/../../src/Shipments/Cdek/CdekStatusMappingService.php';
require_once __DIR__ . '/../../src/Carriers/Cdek/CdekSettings.php';
require_once __DIR__ . '/../../src/Infrastructure/Settings/SettingsRepository.php';
require_once __DIR__ . '/../../src/Infrastructure/Security/EncryptionService.php';
require_once __DIR__ . '/../../src/Locations/ValueObjects/Location.php';
require_once __DIR__ . '/../../src/Locations/Storage/LocationRepository.php';
require_once __DIR__ . '/../../src/Locations/Storage/LocationDeliveryCodeRepository.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/DpdSettings.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyImportReport.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyCsvParser.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdLocationIndex.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyMatcher.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyImportStateService.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyImportService.php';

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyCsvParser;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyImportService;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyImportStateService;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyMatcher;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdLocationIndex;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;

function dpd_import_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wdc_dpd_import_options'] = array();
$GLOBALS['wpdb']->locations = array(
	array(
		'id' => 1,
		'fias_id' => '8DEA00E3-9AAB-4D8E-887C-EF2AAA546456',
		'city_fias_id' => '',
		'gar_id' => '1',
		'gar_object_id' => 1,
		'kladr_id' => '5400000100000',
		'country_code' => 'RU',
		'region_code' => '54',
		'region_name' => 'Новосибирская',
		'district_name' => '',
		'city_name' => 'Новосибирск',
		'city_type' => 'г',
		'settlement_name' => 'Новосибирск',
		'place_name' => 'Новосибирск',
		'place_type' => 'г',
		'display_name' => 'Новосибирск',
		'active' => 1,
	),
	array(
		'id' => 2,
		'fias_id' => '11111111-2222-3333-4444-555555555555',
		'city_fias_id' => '',
		'gar_id' => '2',
		'gar_object_id' => 2,
		'kladr_id' => '5400000200000',
		'country_code' => 'RU',
		'region_code' => '54',
		'region_name' => 'Новосибирская',
		'district_name' => '',
		'city_name' => 'Бердск',
		'city_type' => 'г',
		'settlement_name' => 'Бердск',
		'place_name' => 'Бердск',
		'place_type' => 'г',
		'display_name' => 'Бердск',
		'active' => 1,
	),
	array(
		'id' => 3,
		'fias_id' => '22222222-2222-3333-4444-555555555555',
		'gar_id' => '3',
		'gar_object_id' => 3,
		'kladr_id' => '5400000300000',
		'country_code' => 'RU',
		'region_code' => '54',
		'region_name' => 'Новосибирская',
		'district_name' => '',
		'city_name' => 'Конфликт',
		'city_type' => 'г',
		'settlement_name' => 'Конфликт',
		'place_name' => 'Конфликт',
		'place_type' => 'г',
		'display_name' => 'Конфликт',
		'active' => 1,
	),
	array(
		'id' => 4,
		'fias_id' => '33333333-2222-3333-4444-555555555555',
		'gar_id' => '4',
		'gar_object_id' => 4,
		'kladr_id' => '5400000400000',
		'country_code' => 'RU',
		'region_code' => '54',
		'region_name' => 'Новосибирская',
		'district_name' => 'Один',
		'city_name' => 'Дубль',
		'city_type' => 'с',
		'settlement_name' => 'Дубль',
		'place_name' => 'Дубль',
		'place_type' => 'с',
		'display_name' => 'Дубль',
		'active' => 1,
	),
	array(
		'id' => 5,
		'fias_id' => '44444444-2222-3333-4444-555555555555',
		'gar_id' => '5',
		'gar_object_id' => 5,
		'kladr_id' => '5400000500000',
		'country_code' => 'RU',
		'region_code' => '54',
		'region_name' => 'Новосибирская',
		'district_name' => 'Один',
		'city_name' => 'Дубль',
		'city_type' => 'с',
		'settlement_name' => 'Дубль',
		'place_name' => 'Дубль',
		'place_type' => 'с',
		'display_name' => 'Дубль',
		'active' => 1,
	),
);

$csv = implode(
	"\n",
	array(
		'ID НП;Код страны;Регион;Район;Основной город;Населённый пункт;Тип НП;Индекс НП;ФИАС;Код КЛАДР',
		'49455627;RU;Новосибирская;;Новосибирск;Новосибирск;г;630023;8DEA00E3-9AAB-4D8E-887C-EF2AAA546456;RU54000001000',
		'49455627;RU;Новосибирская;;Новосибирск;Новосибирск;г;630024;8DEA00E3-9AAB-4D8E-887C-EF2AAA546456;RU54000001000',
		'70000001;RU;Новосибирская;;Бердск;Бердск;г;633010;;RU54000002000',
		'80000001;RU;Новосибирская;;Конфликт;Конфликт;г;633020;22222222-2222-3333-4444-555555555555;RU54000003000',
		'80000002;RU;Новосибирская;;Конфликт;Конфликт;г;633021;22222222-2222-3333-4444-555555555555;RU54000003000',
		'90000001;RU;Новосибирская;Один;Дубль;Дубль;с;633030;;',
		'10000001;KZ;Алматы;;Алматы;Алматы;г;050000;;',
		';RU;Новосибирская;;Пусто;Пусто;г;633040;;',
	)
);
$path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-' );
file_put_contents( $path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );

$settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$repository = new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] );
$location_repository = new LocationRepository( $GLOBALS['wpdb'] );
$index = new DpdLocationIndex( $location_repository );
$state = new DpdGeographyImportStateService();
$importer = new DpdGeographyImportService(
	new DpdGeographyCsvParser(),
	new DpdGeographyMatcher( $index ),
	$repository,
	$index,
	$state,
	$settings
);
$report = $importer->import_file( $path, 'manual', 'GeographyNewDPD_2026_06_16.csv' );
@unlink( $path );

dpd_import_assert( 8 === (int) $report['total_rows'], 'parser counts data rows after header' );
dpd_import_assert( 7 === (int) $report['ru_rows'], 'import processes RU rows only' );
dpd_import_assert( 1 === (int) $report['skipped_non_ru'], 'import skips non-RU rows' );
dpd_import_assert( 1 === (int) $report['skipped_invalid'], 'import skips rows without DPD city ID' );
dpd_import_assert( 3 === (int) $report['matched_by_fias'], 'FIAS exact matches are counted before conflict rollback' );
dpd_import_assert( 1 === (int) $report['matched_by_kladr'], 'KLADR normalized match is saved' );
dpd_import_assert( 3 === (int) $report['saved_mappings'], 'non-conflicting rows are saved and conflicted job-local writes are rolled back' );
dpd_import_assert( 1 === (int) $report['unchanged_mappings'], 'duplicate same DPD city ID is idempotent' );
dpd_import_assert( 1 === (int) $report['conflicts'], 'different DPD city IDs for one location are treated as conflict' );
dpd_import_assert( 1 === (int) $report['ambiguous'], 'ambiguous name match is not saved' );
dpd_import_assert( '49455627' === $repository->get_dpd_city_id( 1 ), 'FIAS match writes dpd_city_id' );
dpd_import_assert( '70000001' === $repository->get_dpd_city_id( 2 ), 'KLADR normalized match writes dpd_city_id' );
dpd_import_assert( null === $repository->get_dpd_city_id( 3 ), 'conflicted mapping is not saved' );
dpd_import_assert( null === $repository->get_dpd_city_id( 4 ) && null === $repository->get_dpd_city_id( 5 ), 'ambiguous name mapping is not saved' );
dpd_import_assert( array() !== $settings->last_geography_import_report(), 'last import report is stored in settings' );
dpd_import_assert( 'finished' === $state->current()['phase'], 'step import finishes job state' );
dpd_import_assert( ! file_exists( $path ), 'import temp file is deleted on finish' );

$plugin_source = file_get_contents( __DIR__ . '/../../src/Core/Plugin.php' );
dpd_import_assert( is_string( $plugin_source ) && ! str_contains( $plugin_source, 'DpdShipmentAdapter' ), 'DPD shipment adapter is not registered by geography import' );
dpd_import_assert( is_string( $plugin_source ) && ! str_contains( $plugin_source, 'DpdCarrier' ), 'DPD runtime carrier is not registered by geography import' );

echo "DPD geography import smoke OK\n";

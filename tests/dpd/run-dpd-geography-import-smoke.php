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
		/** @var array<string,array<int,array<string,mixed>>> */
		public array $dpd_geography_stage_tables = array();
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
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyStageRepository.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyFtpClient.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyImportService.php';

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyCsvParser;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyImportService;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyImportStateService;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyFtpClient;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyMatcher;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyStageRepository;
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
$stage = new DpdGeographyStageRepository( $GLOBALS['wpdb'] );
$importer = new DpdGeographyImportService(
	new DpdGeographyCsvParser(),
	new DpdGeographyMatcher( $index ),
	$index,
	$state,
	$stage,
	$settings
);
$header = ( new DpdGeographyCsvParser() )->inspect_header( $path );
dpd_import_assert( ! array_key_exists( 'total_rows', $header ), 'inspect_header does not perform a full-row count' );
dpd_import_assert( (int) $header['data_offset'] > 0, 'inspect_header reads only header and returns data offset' );

$job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$internal = $state->current();
$stage_table = (string) $internal['stage_table'];
$import_path = (string) $internal['file_path'];
$upload_index_path = (string) $internal['index_path'];
dpd_import_assert( 'ready' === (string) $job['phase'], 'start creates ready import job' );
dpd_import_assert( '' !== $stage_table && isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ] ), 'start creates staging table' );
dpd_import_assert( ! array_key_exists( 'stage_table', $job ) && ! array_key_exists( 'file_path', $job ), 'public state hides internal paths and stage table' );
dpd_import_assert( ! array_key_exists( 'delete_file_on_finish', $job ), 'public state hides delete_file_on_finish flag' );
dpd_import_assert( true === (bool) $internal['delete_file_on_finish'], 'manual upload marks imported temp file for deletion' );
dpd_import_assert( (int) $internal['file_size'] > 0, 'start stores file_size for progress' );
dpd_import_assert( 0 === (int) $internal['total_rows'], 'start does not pre-count total CSV rows' );
dpd_import_assert( (float) $job['percent_complete'] > 0, 'start progress is calculated from byte_offset and file_size' );
dpd_import_assert( ! array_key_exists( 'seen_mappings', $internal ) && ! array_key_exists( 'saved_by_job', $internal ) && ! array_key_exists( 'blocked_locations', $internal ), 'state does not contain large in-memory mapping arrays' );

$job = $importer->step( (string) $job['job_id'], 1 );
dpd_import_assert( array() === $GLOBALS['wpdb']->delivery_codes, 'step import does not write directly to working delivery codes table' );
dpd_import_assert( isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ][1] ), 'candidate is saved in staging table' );
dpd_import_assert( 'candidate' === $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ][1]['status'], 'staged candidate has candidate status' );

$job = $importer->step( (string) $job['job_id'], 4 );
dpd_import_assert( isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ][3] ), 'conflicted location exists in staging table' );
dpd_import_assert( 'conflict' === $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ][3]['status'], 'different DPD city IDs mark staged row as conflict' );
dpd_import_assert( null === $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ][3]['dpd_city_id'], 'conflict clears staged dpd_city_id' );
dpd_import_assert( array() === $GLOBALS['wpdb']->delivery_codes, 'working delivery codes table remains unchanged before finalization' );

while ( in_array( (string) ( $job['phase'] ?? '' ), array( 'ready', 'importing' ), true ) ) {
	$job = $importer->step( (string) $job['job_id'], 10000 );
}
$report = $settings->last_geography_import_report();

dpd_import_assert( 0 === (int) $report['total_rows'], 'import does not pre-count data rows' );
dpd_import_assert( (int) $report['file_size'] > 0, 'report stores source file size' );
dpd_import_assert( 7 === (int) $report['ru_rows'], 'import processes RU rows only' );
dpd_import_assert( 1 === (int) $report['skipped_non_ru'], 'import skips non-RU rows' );
dpd_import_assert( 1 === (int) $report['skipped_invalid'], 'import skips rows without DPD city ID' );
dpd_import_assert( 4 === (int) $report['matched_by_fias'], 'FIAS exact matches are counted before staging conflict filtering' );
dpd_import_assert( 1 === (int) $report['matched_by_kladr'], 'KLADR normalized match is saved' );
dpd_import_assert( 3 === (int) $report['saved_candidates'], 'non-conflicting rows are staged as candidates before finalization' );
dpd_import_assert( 2 === (int) $report['finalized_mappings'], 'only candidates are finalized into working delivery codes table' );
dpd_import_assert( 1 === (int) $report['unchanged_mappings'], 'duplicate same DPD city ID is idempotent' );
dpd_import_assert( 1 === (int) $report['conflicts'], 'different DPD city IDs for one location are treated as conflict' );
dpd_import_assert( 1 === (int) $report['ambiguous'], 'ambiguous name match is not saved' );
dpd_import_assert( '49455627' === $repository->get_dpd_city_id( 1 ), 'FIAS match writes dpd_city_id' );
dpd_import_assert( '70000001' === $repository->get_dpd_city_id( 2 ), 'KLADR normalized match writes dpd_city_id' );
dpd_import_assert( null === $repository->get_dpd_city_id( 3 ), 'conflicted mapping is not saved' );
dpd_import_assert( null === $repository->get_dpd_city_id( 4 ) && null === $repository->get_dpd_city_id( 5 ), 'ambiguous name mapping is not saved' );
dpd_import_assert( array() !== $settings->last_geography_import_report(), 'last import report is stored in settings' );
dpd_import_assert( 'finished' === $state->current()['phase'], 'step import finishes job state' );
dpd_import_assert( ! file_exists( $import_path ), 'import temp file is deleted on finish' );
dpd_import_assert( ! file_exists( $upload_index_path ), 'serialized index file is deleted on finish' );
dpd_import_assert( ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ] ), 'staging table is deleted on finish' );

$phase_before_ftp_warning = (string) $state->current()['phase'];
$ftp_warning = $importer->start_from_ftp( new DpdGeographyFtpClient( $settings ) );
if ( ! extension_loaded( 'ssh2' ) || ! function_exists( 'ssh2_connect' ) ) {
	dpd_import_assert( 'warning' === (string) ( $ftp_warning['status'] ?? '' ), 'missing ssh2 returns FTP warning instead of failed import' );
	dpd_import_assert( str_contains( strtolower( (string) ( $ftp_warning['last_message'] ?? '' ) ), 'manual csv upload' ), 'missing ssh2 warning points to manual CSV upload' );
	dpd_import_assert( $phase_before_ftp_warning === (string) $state->current()['phase'], 'missing ssh2 does not change current import state phase' );
}

$cli_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-cli-' );
file_put_contents( $cli_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$report = $importer->import_file( $cli_path, 'cli', 'GeographyNewDPD_2026_06_16.csv' );
$cli_state = $state->current();
dpd_import_assert( 0 === (int) $report['total_rows'], 'CLI wrapper imports existing file without pre-counting rows' );
dpd_import_assert( false === (bool) $cli_state['delete_file_on_finish'], 'CLI wrapper stores delete_file_on_finish=false' );
dpd_import_assert( file_exists( $cli_path ), 'CLI wrapper keeps existing CSV on finish' );
dpd_import_assert( ! file_exists( (string) $cli_state['index_path'] ), 'CLI wrapper deletes serialized index on finish' );
dpd_import_assert( ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ (string) $cli_state['stage_table'] ] ), 'CLI wrapper deletes staging table on finish' );
@unlink( $cli_path );

$reset_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-reset-' );
file_put_contents( $reset_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$reset_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $reset_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$reset_state = $state->current();
$reset_stage = (string) $reset_state['stage_table'];
$reset_import_path = (string) $reset_state['file_path'];
$reset_index_path = (string) $reset_state['index_path'];
dpd_import_assert( isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $reset_stage ] ), 'reset scenario creates staging table' );
$reset_public = $importer->reset();
dpd_import_assert( 'cancelled' === (string) $reset_public['phase'], 'reset marks import as cancelled' );
dpd_import_assert( ! file_exists( $reset_import_path ), 'reset deletes uploaded import temp file when delete_file_on_finish=true' );
dpd_import_assert( ! file_exists( $reset_index_path ), 'reset deletes serialized index when delete_file_on_finish=true' );
dpd_import_assert( ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $reset_stage ] ), 'reset deletes staging table' );

$existing_reset_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-existing-reset-' );
file_put_contents( $existing_reset_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$starter = new ReflectionMethod( $importer, 'start_from_existing_file' );
$starter->setAccessible( true );
$existing_reset_job = $starter->invoke( $importer, $existing_reset_path, 'cli', 'GeographyNewDPD_2026_06_16.csv', false );
$existing_reset_state = $state->current();
$existing_reset_stage = (string) $existing_reset_state['stage_table'];
$existing_reset_index_path = (string) $existing_reset_state['index_path'];
dpd_import_assert( 'ready' === (string) $existing_reset_job['phase'], 'existing-file reset scenario creates ready job' );
dpd_import_assert( false === (bool) $existing_reset_state['delete_file_on_finish'], 'existing-file reset scenario stores delete_file_on_finish=false' );
$importer->reset();
dpd_import_assert( file_exists( $existing_reset_path ), 'reset keeps existing CSV when delete_file_on_finish=false' );
dpd_import_assert( ! file_exists( $existing_reset_index_path ), 'reset deletes serialized index when delete_file_on_finish=false' );
dpd_import_assert( ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $existing_reset_stage ] ), 'reset deletes staging table when delete_file_on_finish=false' );
@unlink( $existing_reset_path );

$plugin_source = file_get_contents( __DIR__ . '/../../src/Core/Plugin.php' );
dpd_import_assert( is_string( $plugin_source ) && ! str_contains( $plugin_source, 'DpdShipmentAdapter' ), 'DPD shipment adapter is not registered by geography import' );
dpd_import_assert( is_string( $plugin_source ) && ! str_contains( $plugin_source, 'DpdCarrier' ), 'DPD runtime carrier is not registered by geography import' );

echo "DPD geography import smoke OK\n";

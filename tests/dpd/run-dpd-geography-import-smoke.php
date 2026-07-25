<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public string $last_error = '';
		public bool $fail_dpd_stage_finalize_clear = false;
		public bool $fail_dpd_stage_finalize_upsert = false;
		public bool $fail_dpd_stage_finalize_commit = false;
		/** @var array<int,bool> */
		public array $fail_location_update_ids = array();
		/** @var array<int,array<string,mixed>> */
		public array $delivery_codes = array();
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		/** @var array<string,array<int,array<string,mixed>>> */
		public array $dpd_geography_stage_tables = array();

		public function insert( string $table, array $data, array $format = array() ): bool {
			unset( $table, $format );
			$this->last_error = '';
			foreach ( $this->locations as $row ) {
				if (
					array_key_exists( 'gar_object_id', $data )
					&& null !== $data['gar_object_id']
					&& '' !== (string) $data['gar_object_id']
					&& array_key_exists( 'gar_object_id', $row )
					&& null !== $row['gar_object_id']
					&& (string) $row['gar_object_id'] === (string) $data['gar_object_id']
				) {
					$this->last_error = 'Duplicate entry "' . (string) $data['gar_object_id'] . '" for key "ux_gar_object_id"';
					return false;
				}
				if (
					array_key_exists( 'fias_id', $data )
					&& null !== $data['fias_id']
					&& '' !== (string) $data['fias_id']
					&& array_key_exists( 'fias_id', $row )
					&& null !== $row['fias_id']
					&& (string) $row['fias_id'] === (string) $data['fias_id']
				) {
					$this->last_error = 'Duplicate entry "' . (string) $data['fias_id'] . '" for key "ux_fias_id"';
					return false;
				}
			}
			$ids = array_map( static fn( array $row ): int => (int) ( $row['id'] ?? 0 ), $this->locations );
			$this->insert_id = ( $ids ? max( $ids ) : 0 ) + 1;
			$data['id'] = $this->insert_id;
			$this->locations[] = $data;

			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			unset( $table, $format, $where_format );
			$this->last_error = '';
			$id = (int) ( $where['id'] ?? 0 );
			if ( ! empty( $this->fail_location_update_ids[ $id ] ) ) {
				$this->last_error = 'forced location update failure for id=' . $id;
				return false;
			}
			foreach ( $this->locations as $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === $id ) {
					continue;
				}
				if (
					array_key_exists( 'gar_object_id', $data )
					&& null !== $data['gar_object_id']
					&& '' !== (string) $data['gar_object_id']
					&& array_key_exists( 'gar_object_id', $row )
					&& null !== $row['gar_object_id']
					&& (string) $row['gar_object_id'] === (string) $data['gar_object_id']
				) {
					$this->last_error = 'Duplicate entry "' . (string) $data['gar_object_id'] . '" for key "ux_gar_object_id"';
					return false;
				}
				if (
					array_key_exists( 'fias_id', $data )
					&& null !== $data['fias_id']
					&& '' !== (string) $data['fias_id']
					&& array_key_exists( 'fias_id', $row )
					&& null !== $row['fias_id']
					&& (string) $row['fias_id'] === (string) $data['fias_id']
				) {
					$this->last_error = 'Duplicate entry "' . (string) $data['fias_id'] . '" for key "ux_fias_id"';
					return false;
				}
			}
			foreach ( $this->locations as $index => $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === $id ) {
					$this->locations[ $index ] = array_merge( $row, $data, array( 'id' => $id ) );
					return true;
				}
			}

			return false;
		}
	}
}

final class DpdProductionPathWpdb extends wpdb {
	/** @var array<int,array<string,mixed>> */
	public array $candidate_rows = array();
	/** @var array<int,mixed> */
	public array $last_prepare_args = array();
	/** @var array<int,mixed> */
	public array $last_insert_args = array();
	public string $last_sql = '';
	public string $last_query = '';
	public string $last_insert_query = '';

	public function __construct() {
		unset( $this->locations, $this->delivery_codes, $this->dpd_geography_stage_tables );
	}

	public function prepare( string $query, mixed ...$args ): string {
		$this->last_sql = $query;
		$this->last_prepare_args = $args;
		if ( str_starts_with( ltrim( $query ), 'INSERT ' ) ) {
			$this->last_insert_args = $args;
		}
		return $query;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $query, string $output = ARRAY_A ): array {
		unset( $output );
		$this->last_query = $query;
		if ( str_contains( $query, 'SELECT id, gar_object_id' ) ) {
			return array_values(
				array_filter(
					array_map(
						static fn( array $row ): array => array( 'id' => (int) ( $row['id'] ?? 0 ), 'gar_object_id' => (int) ( $row['gar_object_id'] ?? 0 ) ),
						$this->candidate_rows
					),
					static fn( array $row ): bool => (int) $row['gar_object_id'] > 0
				)
			);
		}
		$args = $this->last_prepare_args;
		$country = strtoupper( (string) array_shift( $args ) );
		$tokens = array_map(
			static fn( mixed $arg ): string => trim( str_replace( array( '%', '\\' ), '', (string) $arg ) ),
			$args
		);
		$rows = array();
		foreach ( $this->candidate_rows as $row ) {
			if ( $country !== strtoupper( (string) ( $row['country_code'] ?? '' ) ) ) {
				continue;
			}
			$search = mb_strtolower( strtr( (string) ( $row['searchable_text'] ?? '' ), array( 'Ё' => 'Е', 'ё' => 'е' ) ), 'UTF-8' );
			foreach ( $tokens as $token ) {
				if ( '' !== $token && ! str_contains( $search, $token ) ) {
					continue 2;
				}
			}
			$rows[] = $row;
		}

		return $rows;
	}

	public function query( string $query ): int|false {
		$this->last_query = $query;
		if ( str_starts_with( ltrim( $query ), 'INSERT ' ) ) {
			$this->last_insert_query = $query;
		}
		return 1;
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
require_once __DIR__ . '/../../src/Shipments/Dpd/DpdStatusMapping.php';
require_once __DIR__ . '/../../src/Shipments/YandexDelivery/YandexStatusMapping.php';
require_once __DIR__ . '/../../src/Carriers/Cdek/CdekSettings.php';
require_once __DIR__ . '/../../src/Infrastructure/Settings/SettingsRepository.php';
require_once __DIR__ . '/../../src/Infrastructure/Security/EncryptionService.php';
require_once __DIR__ . '/../../src/Locations/ValueObjects/Location.php';
require_once __DIR__ . '/../../src/Locations/Storage/LocationRepository.php';
require_once __DIR__ . '/../../src/Locations/Storage/LocationDeliveryCodeRepository.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/DpdSettings.php';
require_once __DIR__ . '/../../src/Carriers/YandexDelivery/YandexDeliverySettings.php';
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
use WallsShop\WDC\Locations\ValueObjects\Location;

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
	array(
		'id' => 161634,
		'fias_id' => '',
		'gar_id' => '0',
		'gar_object_id' => 0,
		'kladr_id' => '',
		'country_code' => 'BY',
		'region_code' => '',
		'region_name' => 'Минская',
		'district_name' => '',
		'city_name' => 'Минск2',
		'city_type' => 'г',
		'settlement_name' => 'Минск',
		'settlement_type' => 'г',
		'place_name' => 'Минск',
		'place_type' => 'г',
		'display_name' => 'BY, Минская, Минск2, Минск, 119049',
		'postal_code' => '119049',
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
		'196058326;BY;Минская;Минский;Минск2;Минск;г;119049;;BY60011001000',
		'10000001;KZ;Алматы;;Алматы;Алматы;г;050000;;KZ75000000000',
		'10000003;AM;Ереван;;Ереван;Ереван;г;0010;;AM00000000000',
		'10000004;KG;Бишкек;;Бишкек;Бишкек;г;720000;;KG00000000000',
		'196058326;BY;Минская;Минский;Минск2;Минск;г;220000;;BY60011001000',
		'196058326;BY;  Минская  ;Минский;Минск2;Минск;г.;220002;;BY60011001000',
		'196058327;BY;Минская;Другой;Минск2;Минск;г;220001;;BY60011001000',
		'196058328;BY;Берёзовская;Берёзовский;Берёзовка;Берёзовка;с;225210;;BY60011002000',
		'196058328;BY;Березовская;Березовский;Березовка;Березовка;с;225211;;BY60011002000',
		'10000005;UZ;Tashkent;;Tashkent;Tashkent;g;100000;;',
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
	$location_repository,
	$repository,
	$settings
);
$parser = new DpdGeographyCsvParser();

foreach ( array( 'LF' => "\n", 'CRLF' => "\r\n", 'CR' => "\r" ) as $ending_name => $line_ending ) {
	$ending_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-ending-' );
	file_put_contents(
		$ending_path,
		mb_convert_encoding(
			implode(
				$line_ending,
				array(
					'ID НП;Код страны;Регион;Район;Основной город;Населённый пункт;Тип НП;Индекс НП;ФИАС;Код КЛАДР',
					'111;RU;Новосибирская;;Новосибирск;Новосибирск;г;630023;8DEA00E3-9AAB-4D8E-887C-EF2AAA546456;RU54000001000',
					'222;RU;Новосибирская;;Бердск;Бердск;г;633010;;RU54000002000',
				)
			),
			'Windows-1251',
			'UTF-8'
		)
	);
	$ending_header = $parser->inspect_header( $ending_path );
	dpd_import_assert( 'dpd_city_id' === ( $ending_header['columns'][0] ?? '' ), "Windows-1251 {$ending_name} header without BOM is detected correctly" );
	$first_step = $parser->read_step( $ending_path, (int) $ending_header['data_offset'], $ending_header['columns'], 1 );
	$second_step = $parser->read_step( $ending_path, (int) $first_step['new_byte_offset'], $ending_header['columns'], 1 );
	dpd_import_assert( '111' === (string) ( $first_step['rows'][0]['dpd_city_id'] ?? '' ), "read_step reads first {$ending_name} data row" );
	dpd_import_assert( '222' === (string) ( $second_step['rows'][0]['dpd_city_id'] ?? '' ), "read_step resumes from byte_offset for {$ending_name} data row" );
	@unlink( $ending_path );
}

$oversized_header_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-oversized-header-' );
file_put_contents( $oversized_header_path, str_repeat( 'A', 270000 ) );
$oversized_header_failed = false;
try {
	$parser->inspect_header( $oversized_header_path );
} catch ( RuntimeException $exception ) {
	$oversized_header_failed = str_contains( $exception->getMessage(), 'row length' );
}
@unlink( $oversized_header_path );
dpd_import_assert( $oversized_header_failed, 'oversized CSV header without line ending fails safely without memory exhaustion' );

$oversized_row_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-oversized-row-' );
file_put_contents( $oversized_row_path, mb_convert_encoding( "ID НП;Код страны;Регион\n", 'Windows-1251', 'UTF-8' ) . str_repeat( '1', 270000 ) . "\n" );
$oversized_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $oversized_row_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$oversized_step = $importer->step( (string) $oversized_job['job_id'], 1 );
dpd_import_assert( 'failed' === (string) $oversized_step['phase'], 'read_step parser exception becomes failed import state' );
dpd_import_assert( str_contains( (string) $oversized_step['last_message'], 'DPD geography CSV parse failed' ), 'read_step parser exception is reported as diagnostic message' );
$oversized_report = $settings->last_geography_import_report();
dpd_import_assert( 'failed' === (string) ( $oversized_report['phase'] ?? '' ) && 'error' === (string) ( $oversized_report['status'] ?? '' ), 'parser failure is saved as terminal failed/error report.' );
dpd_import_assert( (string) ( $oversized_report['last_message'] ?? '' ) === (string) ( $oversized_step['last_message'] ?? '' ), 'parser failure report message matches terminal state.' );
$importer->reset();

$header = $parser->inspect_header( $path );
dpd_import_assert( ! array_key_exists( 'total_rows', $header ), 'inspect_header does not perform a full-row count' );
dpd_import_assert( (int) $header['data_offset'] > 0, 'inspect_header reads only header and returns data offset' );
dpd_import_assert( 'dpd_city_id' === ( $header['columns'][0] ?? '' ) && 'country_code' === ( $header['columns'][1] ?? '' ), 'Windows-1251 header without BOM is detected correctly' );

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
dpd_import_assert( 9 === (int) ( $report['foreign_rows'] ?? 0 ) && 6 === (int) ( $report['foreign_by_rows'] ?? 0 ) && 1 === (int) $report['skipped_non_ru'], 'import counts supported AM/BY/KZ/KG foreign rows and skips unsupported UZ' );
dpd_import_assert( 5 === (int) ( $report['foreign_locations_inserted'] ?? 0 ) && 4 === (int) ( $report['foreign_locations_updated'] ?? 0 ), 'foreign DPD import inserts distinct locations and updates repeated or legacy same-place rows; inserted=' . (string) ( $report['foreign_locations_inserted'] ?? '' ) . ' updated=' . (string) ( $report['foreign_locations_updated'] ?? '' ) );
dpd_import_assert( 0 === (int) ( $report['foreign_save_failed'] ?? 0 ) && 0 === (int) ( $report['errors_total'] ?? 0 ), 'foreign DPD imports do not hit unique GAR/FIAS SQL failures' );
dpd_import_assert( 1 === (int) $report['skipped_invalid'], 'import skips rows without DPD city ID' );
dpd_import_assert( 4 === (int) $report['matched_by_fias'], 'FIAS exact matches are counted before staging conflict filtering' );
dpd_import_assert( 1 === (int) $report['matched_by_kladr'], 'KLADR normalized match is saved' );
dpd_import_assert( 9 === (int) $report['saved_candidates'], 'non-conflicting RU and foreign rows are staged as candidates before finalization' );
dpd_import_assert( 8 === (int) $report['finalized_mappings'] && 8 === (int) ( $report['finalized_changes'] ?? 0 ), 'RU candidates and foreign imports are finalized into working delivery codes table with candidate and change counters' );
dpd_import_assert( 4 === (int) $report['unchanged_mappings'], 'duplicate same DPD city ID is idempotent for RU and foreign rows' );
dpd_import_assert( 1 === (int) $report['conflicts'], 'different DPD city IDs for one location are treated as conflict' );
dpd_import_assert( 1 === (int) $report['ambiguous'], 'ambiguous name match is not saved' );
dpd_import_assert( '49455627' === $repository->get_dpd_city_id( 1 ), 'FIAS match writes dpd_city_id' );
dpd_import_assert( '70000001' === $repository->get_dpd_city_id( 2 ), 'KLADR normalized match writes dpd_city_id' );
dpd_import_assert( null === $repository->get_dpd_city_id( 3 ), 'conflicted mapping is not saved' );
dpd_import_assert( null === $repository->get_dpd_city_id( 4 ) && null === $repository->get_dpd_city_id( 5 ), 'ambiguous name mapping is not saved' );
$foreign_locations = array_values( array_filter( $GLOBALS['wpdb']->locations, static fn( array $row ): bool => in_array( (string) ( $row['country_code'] ?? '' ), array( 'AM', 'BY', 'KZ', 'KG' ), true ) ) );
dpd_import_assert( 6 === count( $foreign_locations ), 'AM/BY/KZ/KG foreign locations are created in canonical locations table and same names in different districts stay separate.' );
foreach ( $foreign_locations as $foreign_row ) {
	dpd_import_assert( null === ( $foreign_row['gar_object_id'] ?? null ) && null === ( $foreign_row['fias_id'] ?? null ), 'foreign locations persist missing GAR/FIAS as SQL NULL-compatible values.' );
	dpd_import_assert( '' === (string) ( $foreign_row['gar_id'] ?? '' ) && '' === (string) ( $foreign_row['kladr_id'] ?? '' ), 'foreign locations do not persist fake GAR/KLADR values.' );
	dpd_import_assert( '' === (string) ( $foreign_row['postal_code'] ?? '' ), 'foreign locations do not persist DPD postal_code as canonical postcode.' );
}
$minsk_rows = array_values( array_filter( $foreign_locations, static fn( array $row ): bool => 'BY' === (string) ( $row['country_code'] ?? '' ) && 'Минск' === (string) ( $row['place_name'] ?? '' ) ) );
dpd_import_assert( 2 === count( $minsk_rows ), 'same foreign place name in different districts creates two canonical locations.' );
$minsk_main = null;
foreach ( $minsk_rows as $row ) {
	if ( 'Минский' === (string) ( $row['district_name'] ?? '' ) ) {
		$minsk_main = $row;
		break;
	}
}
dpd_import_assert( is_array( $minsk_main ), 'production BY Minsk fixture is imported.' );
dpd_import_assert( 161634 === (int) ( $minsk_main['id'] ?? 0 ), 'production malformed BY Minsk row is updated instead of duplicated.' );
dpd_import_assert( 'Минская' === (string) ( $minsk_main['region_name'] ?? '' ) && 'обл.' === (string) ( $minsk_main['region_type'] ?? '' ), 'production BY Minsk region and region_type are mapped.' );
dpd_import_assert( 'Минский' === (string) ( $minsk_main['district_name'] ?? '' ) && 'р-н' === (string) ( $minsk_main['district_type'] ?? '' ), 'production BY Minsk district and district_type are mapped.' );
dpd_import_assert( 'Минск' === (string) ( $minsk_main['city_name'] ?? '' ) && 'г' === (string) ( $minsk_main['city_type'] ?? '' ), 'production BY Minsk uses settlement as city instead of main_city=Минск2.' );
dpd_import_assert( 'Минск' === (string) ( $minsk_main['settlement_name'] ?? '' ) && 'г' === (string) ( $minsk_main['settlement_type'] ?? '' ), 'production BY Minsk settlement fields are canonical.' );
dpd_import_assert( 'Минск' === (string) ( $minsk_main['place_name'] ?? '' ) && 'г' === (string) ( $minsk_main['place_type'] ?? '' ), 'production BY Minsk place fields are canonical.' );
dpd_import_assert( false === str_contains( (string) ( $minsk_main['display_name'] ?? '' ), 'Минск2' ) && false === str_contains( (string) ( $minsk_main['display_name'] ?? '' ), '119049' ), 'production BY Minsk display_name excludes DPD main_city and postal_code.' );
foreach ( array( '10000001', '196058326', '10000003', '10000004', '196058327' ) as $foreign_dpd_id ) {
	$foreign_location_id = $repository->find_location_id_by_dpd_city_id( $foreign_dpd_id );
	dpd_import_assert( null !== $foreign_location_id && $foreign_dpd_id === $repository->get_dpd_city_id( $foreign_location_id ), 'foreign DPD mapping survives finalization for ' . $foreign_dpd_id );
}
$berezovka_rows = array_values( array_filter( $foreign_locations, static fn( array $row ): bool => 'BY' === (string) ( $row['country_code'] ?? '' ) && in_array( (string) ( $row['place_name'] ?? '' ), array( 'Берёзовка', 'Березовка' ), true ) ) );
dpd_import_assert( 1 === count( $berezovka_rows ), 'Ё/Е foreign identity variants do not create duplicate locations.' );
dpd_import_assert( null !== $repository->find_location_id_by_dpd_city_id( '196058328' ), 'Ё/Е foreign identity mapping survives finalization.' );
dpd_import_assert( null === $repository->find_location_id_by_dpd_city_id( '10000005' ), 'unsupported UZ foreign row does not create a DPD mapping.' );
dpd_import_assert( array() !== $settings->last_geography_import_report(), 'last import report is stored in settings' );
$success_state = $state->current();
$success_report = $settings->last_geography_import_report();
dpd_import_assert( 'finished' === $success_state['phase'] && 'success' === (string) ( $success_state['status'] ?? '' ), 'step import finishes job state with success status' );
dpd_import_assert( 'finished' === (string) ( $success_report['phase'] ?? '' ) && 'success' === (string) ( $success_report['status'] ?? '' ), 'success last report is saved from terminal state' );
dpd_import_assert( (string) ( $success_state['finished_at'] ?? '' ) === (string) ( $success_report['finished_at'] ?? '' ) && (string) ( $success_state['last_message'] ?? '' ) === (string) ( $success_report['last_message'] ?? '' ), 'success report terminal timestamps and message match state' );
dpd_import_assert( empty( $success_report['stale_cleanup_skipped'] ) && 0 === (int) ( $success_report['stale_cleared'] ?? -1 ), 'clean import records stale cleanup as enabled with no stale rows in the empty working table.' );
$working_after_success = $GLOBALS['wpdb']->delivery_codes;
dpd_import_assert( ! file_exists( $import_path ), 'import temp file is deleted on finish' );
dpd_import_assert( ! file_exists( $upload_index_path ), 'serialized index file is deleted on finish' );
dpd_import_assert( ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ] ), 'staging table is deleted on finish' );

$invalid_upload = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'name' => 'broken.csv' ) );
$invalid_upload_report = $settings->last_geography_import_report();
dpd_import_assert( 'failed' === (string) ( $invalid_upload['phase'] ?? '' ) && 'error' === (string) ( $invalid_upload['status'] ?? '' ), 'invalid manual upload returns failed/error state.' );
dpd_import_assert( 'manual' === (string) ( $invalid_upload_report['source'] ?? '' ) && 0 === (int) ( $invalid_upload_report['ru_rows'] ?? -1 ) && 0 === (int) ( $invalid_upload_report['finalized_mappings'] ?? -1 ), 'invalid upload failed report starts from zero counters instead of previous success counters.' );
dpd_import_assert( 'failed' === (string) ( $invalid_upload_report['phase'] ?? '' ) && 'error' === (string) ( $invalid_upload_report['status'] ?? '' ), 'invalid upload replaces previous success report with terminal failed/error report.' );

$warning_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-warning-' );
file_put_contents( $warning_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$GLOBALS['wpdb']->delivery_codes = array(
	array( 'location_id' => 161634, 'dpd_city_id' => '196058326', 'updated_at' => '2026-06-16 00:00:00' ),
	array( 'location_id' => 777, 'dpd_city_id' => '77777777', 'updated_at' => '2026-06-16 00:00:00' ),
);
$GLOBALS['wpdb']->fail_location_update_ids[161634] = true;
$warning_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $warning_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
while ( in_array( (string) ( $warning_job['phase'] ?? '' ), array( 'ready', 'importing' ), true ) ) {
	$warning_job = $importer->step( (string) $warning_job['job_id'], 10000 );
}
unset( $GLOBALS['wpdb']->fail_location_update_ids[161634] );
$warning_report = $settings->last_geography_import_report();
dpd_import_assert( 'finished' === (string) ( $warning_job['phase'] ?? '' ) && 'warning' === (string) ( $warning_job['status'] ?? '' ), 'row-level foreign save errors finish import as warning.' );
dpd_import_assert( ! empty( $warning_report['stale_cleanup_skipped'] ) && 0 === (int) ( $warning_report['stale_cleared'] ?? -1 ), 'warning import skips stale cleanup and reports zero stale clears.' );
dpd_import_assert( '196058326' === $repository->get_dpd_city_id( 161634 ) && '77777777' === $repository->get_dpd_city_id( 777 ), 'warning import preserves prior working mappings missing from staging.' );
dpd_import_assert( '49455627' === $repository->get_dpd_city_id( 1 ), 'warning import still applies successful candidate mappings.' );
dpd_import_assert( (int) ( $warning_report['errors_total'] ?? 0 ) > 0 && (int) ( $warning_report['foreign_save_failed'] ?? 0 ) > 0, 'warning report includes row-level error counters.' );
dpd_import_assert( (string) ( $warning_report['phase'] ?? '' ) === (string) ( $warning_job['phase'] ?? '' ) && (string) ( $warning_report['status'] ?? '' ) === (string) ( $warning_job['status'] ?? '' ), 'warning report matches terminal state.' );

$conflict_stage = $stage->table_name_for_job( 'conflict-preservation' );
$stage->create( $conflict_stage );
$GLOBALS['wpdb']->delivery_codes = array(
	array( 'location_id' => 3, 'dpd_city_id' => '80000001', 'updated_at' => '2026-06-16 00:00:00' ),
	array( 'location_id' => 999, 'dpd_city_id' => '99999999', 'updated_at' => '2026-06-16 00:00:00' ),
);
dpd_import_assert( 'inserted' === $stage->upsert_candidate( $conflict_stage, 3, '80000001', 'fias' ), 'conflict preservation setup inserts first candidate.' );
dpd_import_assert( 'conflict' === $stage->upsert_candidate( $conflict_stage, 3, '80000002', 'fias' ), 'conflict preservation setup creates conflict row.' );
$conflict_finalize = $stage->finalize_into_delivery_codes( $conflict_stage );
dpd_import_assert( 0 === (int) $conflict_finalize['mappings'] && 1 === (int) $conflict_finalize['changes'] && 1 === (int) ( $conflict_finalize['stale_cleared'] ?? 0 ) && empty( $conflict_finalize['stale_cleanup_skipped'] ), 'conflict rows are excluded from finalized mappings and changes while clean stale rows are cleared.' );
dpd_import_assert( '80000001' === $repository->get_dpd_city_id( 3 ), 'conflict stage row preserves existing working mapping.' );
dpd_import_assert( null === $repository->get_dpd_city_id( 999 ), 'stale mapping is cleared only when location_id is absent from stage.' );
$stage->drop( $conflict_stage );

$failure_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-finalize-failure-' );
file_put_contents( $failure_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$failure_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $failure_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$failure_state = $state->current();
$failure_stage = (string) $failure_state['stage_table'];
$GLOBALS['wpdb']->fail_dpd_stage_finalize_clear = true;
while ( in_array( (string) ( $failure_job['phase'] ?? '' ), array( 'ready', 'importing' ), true ) ) {
	$failure_job = $importer->step( (string) $failure_job['job_id'], 10000 );
}
$GLOBALS['wpdb']->fail_dpd_stage_finalize_clear = false;
dpd_import_assert( 'failed' === (string) $state->current()['phase'], 'finalization SQL failure marks import job failed' );
dpd_import_assert( str_contains( (string) $state->current()['last_message'], 'DPD geography finalization failed' ), 'finalization SQL failure reports diagnostic message' );
dpd_import_assert( isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $failure_stage ] ), 'failed finalization keeps staging table for reset/retry diagnostics' );
$failure_report = $settings->last_geography_import_report();
dpd_import_assert( 'error' === (string) ( $state->current()['status'] ?? '' ) && 'error' === (string) ( $failure_report['status'] ?? '' ), 'clear failure state and report use error status.' );
$importer->reset();
dpd_import_assert( ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $failure_stage ] ), 'reset clears failed finalization staging table' );

$GLOBALS['wpdb']->delivery_codes = $success_snapshot = array(
	array( 'location_id' => 1, 'dpd_city_id' => '49455627', 'updated_at' => '2026-06-16 01:00:00' ),
	array( 'location_id' => 777, 'dpd_city_id' => '77777777', 'updated_at' => '2026-06-16 01:00:00' ),
);
$upsert_failure_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-upsert-failure-' );
file_put_contents( $upsert_failure_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$upsert_failure_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $upsert_failure_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$upsert_failure_stage = (string) $state->current()['stage_table'];
$GLOBALS['wpdb']->fail_dpd_stage_finalize_upsert = true;
while ( in_array( (string) ( $upsert_failure_job['phase'] ?? '' ), array( 'ready', 'importing' ), true ) ) {
	$upsert_failure_job = $importer->step( (string) $upsert_failure_job['job_id'], 10000 );
}
$GLOBALS['wpdb']->fail_dpd_stage_finalize_upsert = false;
dpd_import_assert( 'failed' === (string) $state->current()['phase'] && 'error' === (string) ( $state->current()['status'] ?? '' ), 'upsert failure after clear marks import failed/error.' );
dpd_import_assert( $success_snapshot === $GLOBALS['wpdb']->delivery_codes, 'upsert failure rolls back clear and preserves working delivery_codes snapshot.' );
dpd_import_assert( isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $upsert_failure_stage ] ), 'upsert failure keeps staging table for diagnostics.' );
dpd_import_assert( 'failed' === (string) ( $settings->last_geography_import_report()['phase'] ?? '' ) && 'error' === (string) ( $settings->last_geography_import_report()['status'] ?? '' ), 'upsert failure report is terminal failed/error.' );
$importer->reset();

$commit_stage = $stage->table_name_for_job( 'commit-failure' );
$stage->create( $commit_stage );
$GLOBALS['wpdb']->delivery_codes = $commit_snapshot = array( array( 'location_id' => 1, 'dpd_city_id' => '49455627', 'updated_at' => '2026-06-16 02:00:00' ) );
$stage->upsert_candidate( $commit_stage, 2, '70000001', 'kladr' );
$GLOBALS['wpdb']->fail_dpd_stage_finalize_commit = true;
$commit_failed = false;
try {
	$stage->finalize_into_delivery_codes( $commit_stage );
} catch ( RuntimeException $exception ) {
	$commit_failed = str_contains( $exception->getMessage(), 'commit failed' );
}
$GLOBALS['wpdb']->fail_dpd_stage_finalize_commit = false;
dpd_import_assert( $commit_failed && $commit_snapshot === $GLOBALS['wpdb']->delivery_codes, 'commit failure rolls back delivery_codes snapshot.' );
$stage->drop( $commit_stage );

$missing_stage_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-missing-stage-' );
file_put_contents( $missing_stage_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$missing_stage_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $missing_stage_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$missing_stage_internal = $state->current();
unset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ (string) $missing_stage_internal['stage_table'] ] );
$missing_stage_failed = $importer->step( (string) $missing_stage_job['job_id'], 1 );
$missing_stage_report = $settings->last_geography_import_report();
dpd_import_assert( 'failed' === (string) ( $missing_stage_failed['phase'] ?? '' ) && 'error' === (string) ( $missing_stage_failed['status'] ?? '' ), 'missing staging table fails active import job.' );
dpd_import_assert( 'failed' === (string) ( $missing_stage_report['phase'] ?? '' ) && 'error' === (string) ( $missing_stage_report['status'] ?? '' ) && (string) ( $missing_stage_report['last_message'] ?? '' ) === (string) ( $missing_stage_failed['last_message'] ?? '' ), 'missing stage failure is saved as current terminal report.' );
$importer->reset();

$warning_state = $state->finish( 'Synthetic warning terminal state.', 'warning' );
$report_from_state = new ReflectionMethod( $importer, 'report_from_state' );
$report_from_state->setAccessible( true );
$warning_report = $report_from_state->invoke( $importer, $warning_state );
dpd_import_assert( 'finished' === (string) ( $warning_state['phase'] ?? '' ) && 'warning' === (string) ( $warning_state['status'] ?? '' ), 'warning terminal state uses finished/warning lifecycle status.' );
dpd_import_assert( 'finished' === (string) ( $warning_report['phase'] ?? '' ) && 'warning' === (string) ( $warning_report['status'] ?? '' ) && (string) ( $warning_report['last_message'] ?? '' ) === (string) ( $warning_state['last_message'] ?? '' ), 'warning report is built from the terminal warning state.' );

$phase_before_ftp_warning = (string) $state->current()['phase'];
$ftp_warning = $importer->start_from_ftp( new DpdGeographyFtpClient( $settings ) );
if ( ! extension_loaded( 'ssh2' ) || ! function_exists( 'ssh2_connect' ) ) {
	dpd_import_assert( 'warning' === (string) ( $ftp_warning['status'] ?? '' ), 'missing ssh2 returns FTP warning instead of failed import' );
	dpd_import_assert( str_contains( strtolower( (string) ( $ftp_warning['last_message'] ?? '' ) ), 'manual csv upload' ), 'missing ssh2 warning points to manual CSV upload' );
	dpd_import_assert( $phase_before_ftp_warning === (string) $state->current()['phase'], 'missing ssh2 does not change current import state phase' );
	dpd_import_assert( array() === (array) ( $state->current()['errors'] ?? array() ), 'missing ssh2 warning does not pollute import errors' );
}

$cli_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-cli-' );
file_put_contents( $cli_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$GLOBALS['wpdb']->delivery_codes = $working_after_success;
$delivery_codes_before_repeat = $GLOBALS['wpdb']->delivery_codes;
$report = $importer->import_file( $cli_path, 'cli', 'GeographyNewDPD_2026_06_16.csv' );
$cli_state = $state->current();
dpd_import_assert( 0 === (int) $report['total_rows'], 'CLI wrapper imports existing file without pre-counting rows' );
dpd_import_assert( 6 === count( array_values( array_filter( $GLOBALS['wpdb']->locations, static fn( array $row ): bool => in_array( (string) ( $row['country_code'] ?? '' ), array( 'AM', 'BY', 'KZ', 'KG' ), true ) ) ) ), 'repeat import reuses foreign locations without creating duplicates' );
dpd_import_assert( 8 === (int) $report['finalized_mappings'] && 0 === (int) ( $report['finalized_changes'] ?? -1 ), 'repeat import reports candidate mappings separately from idempotent finalized changes' );
dpd_import_assert( $delivery_codes_before_repeat === $GLOBALS['wpdb']->delivery_codes, 'repeat import does not update unchanged working mappings or updated_at values' );
dpd_import_assert( 'finished' === (string) ( $settings->last_geography_import_report()['phase'] ?? '' ) && 'success' === (string) ( $settings->last_geography_import_report()['status'] ?? '' ), 'repeat import report remains terminal success.' );
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

$production_db = new DpdProductionPathWpdb();
$production_db->candidate_rows = array(
	array(
		'id' => 9001,
		'gar_object_id' => null,
		'fias_id' => null,
		'gar_id' => '',
		'kladr_id' => '',
		'country_code' => 'BY',
		'region_name' => 'Минская',
		'district_name' => 'Ленинский',
		'city_name' => '',
		'settlement_name' => 'им. Ленина',
		'settlement_type' => 'п',
		'place_name' => 'им. Ленина',
		'place_type' => 'п',
		'display_name' => 'BY, Минская, Ленинский, п им. Ленина',
		'searchable_text' => 'by минская ленинский п им. ленина',
		'active' => 1,
	),
	array(
		'id' => 9002,
		'gar_object_id' => null,
		'fias_id' => null,
		'gar_id' => '',
		'kladr_id' => '',
		'country_code' => 'BY',
		'region_name' => 'Берёзовская',
		'district_name' => 'Берёзовский',
		'settlement_name' => 'Берёзовка',
		'settlement_type' => 'с',
		'place_name' => 'Берёзовка',
		'place_type' => 'с',
		'display_name' => 'BY, Берёзовская, Берёзовский, с Берёзовка',
		'searchable_text' => 'by берёзовская берёзовский с берёзовка',
		'active' => 1,
	),
	array(
		'id' => 9003,
		'gar_object_id' => null,
		'fias_id' => null,
		'gar_id' => '',
		'kladr_id' => '',
		'country_code' => 'BY',
		'region_name' => 'Минская',
		'district_name' => 'Минский',
		'settlement_name' => 'Новая   Жизнь',
		'settlement_type' => 'г',
		'place_name' => 'Новая   Жизнь',
		'place_type' => 'г',
		'display_name' => 'BY, Минская, Минский, г Новая Жизнь',
		'searchable_text' => 'by минская минский г новая   жизнь',
		'active' => 1,
	),
);
$production_repository = new LocationRepository( $production_db );
$punctuation_location = $production_repository->find_foreign_by_place_identity( 'BY', 'им Ленина', 'Минская', 'Ленинский', 'п.' );
dpd_import_assert( $punctuation_location instanceof Location && 9001 === (int) $punctuation_location->id, 'production-path foreign identity finds im. Lenina by punctuation-free request.' );
dpd_import_assert( ! str_contains( $production_db->last_sql, 'LIMIT' ), 'production foreign identity prefilter does not hide exact candidates behind arbitrary LIMIT.' );
dpd_import_assert( in_array( '%им%', $production_db->last_prepare_args, true ) && in_array( '%ленина%', $production_db->last_prepare_args, true ) && ! in_array( '%им ленина%', $production_db->last_prepare_args, true ), 'production prefilter uses token LIKE patterns instead of one contiguous normalized phrase.' );
$yo_location = $production_repository->find_foreign_by_place_identity( 'BY', 'Березовка', 'Березовская', 'Березовский', 'с' );
dpd_import_assert( $yo_location instanceof Location && 9002 === (int) $yo_location->id, 'production-path foreign identity applies Ё/Е normalization.' );
$space_location = $production_repository->find_foreign_by_place_identity( 'BY', 'Новая Жизнь', 'Минская', 'Минский', 'г.' );
dpd_import_assert( $space_location instanceof Location && 9003 === (int) $space_location->id, 'production-path foreign identity matches whitespace and type punctuation variants.' );

$bulk_db = new DpdProductionPathWpdb();
$bulk_repository = new LocationRepository( $bulk_db );
$bulk_repository->bulk_upsert_locations(
	array(
		Location::from_array( array( 'country_code' => 'BY', 'region_name' => 'Минская', 'district_name' => 'Минский', 'place_name' => 'Минск', 'place_type' => 'г', 'settlement_name' => 'Минск', 'settlement_type' => 'г', 'display_name' => 'BY, Минская, Минский, г Минск', 'gar_object_id' => 0, 'fias_id' => '', 'gar_id' => '', 'active' => true ) ),
		Location::from_array( array( 'country_code' => 'KZ', 'region_name' => 'Алматы', 'place_name' => 'Алматы', 'place_type' => 'г', 'settlement_name' => 'Алматы', 'settlement_type' => 'г', 'display_name' => 'KZ, г Алматы', 'gar_object_id' => 0, 'fias_id' => '', 'gar_id' => '', 'active' => true ) ),
		Location::from_array( array( 'country_code' => 'RU', 'region_name' => 'Новосибирская', 'place_name' => 'Тест', 'place_type' => 'г', 'settlement_name' => 'Тест', 'settlement_type' => 'г', 'display_name' => 'Тест', 'gar_object_id' => 12345, 'fias_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'gar_id' => '12345', 'active' => true ) ),
	)
);
dpd_import_assert( substr_count( $bulk_db->last_insert_query, 'NULL' ) >= 4, 'bulk location upsert emits SQL NULL literals for missing foreign GAR/FIAS.' );
dpd_import_assert( str_contains( $bulk_db->last_insert_query, '(NULL, NULL' ), 'bulk location upsert does not bind missing GAR/FIAS through %d/%s placeholders.' );
dpd_import_assert( in_array( 12345, $bulk_db->last_insert_args, true ) && in_array( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $bulk_db->last_insert_args, true ), 'bulk location upsert still binds real positive GAR and non-empty FIAS values.' );

$plugin_source = file_get_contents( __DIR__ . '/../../src/Core/Plugin.php' );
dpd_import_assert( is_string( $plugin_source ) && str_contains( $plugin_source, 'DpdShipmentAdapter' ), 'DPD dry-run shipment adapter is registered outside geography import.' );
dpd_import_assert( is_string( $plugin_source ) && ! str_contains( $plugin_source, 'DpdCarrier' ), 'DPD runtime carrier is not registered by geography import' );

$migration_0043_source = file_get_contents( __DIR__ . '/../../database/migrations/0043_allow_external_locations_without_gar_fias.php' );
dpd_import_assert( is_string( $migration_0043_source ) && str_contains( $migration_0043_source, 'gar_object_id BIGINT(20) UNSIGNED NULL' ) && str_contains( $migration_0043_source, 'fias_id CHAR(36) NULL' ), 'migration 0043 keeps GAR/FIAS nullable for external locations.' );
dpd_import_assert( is_string( $migration_0043_source ) && str_contains( $migration_0043_source, 'WHERE gar_object_id = 0' ) && str_contains( $migration_0043_source, "TRIM(fias_id) = ''" ) && str_contains( $migration_0043_source, "gar_id = ''" ), 'migration 0043 normalizes placeholder GAR/FIAS values idempotently.' );

echo "DPD geography import smoke OK\n";

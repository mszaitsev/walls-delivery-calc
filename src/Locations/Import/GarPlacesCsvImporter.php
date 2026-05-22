<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Import;

use RuntimeException;
use SplFileObject;
use WallsShop\WDC\Locations\Services\LocationAliasGenerator;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\Storage\RegionRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;
use WallsShop\WDC\Locations\ValueObjects\Region;

defined( 'ABSPATH' ) || exit;

final class GarPlacesCsvImporter {
	private const CSV_STAGE_BATCH_SIZE = 1000;
	private const LOCATION_UPSERT_BATCH_SIZE = 500;
	private const ALIAS_BATCH_SIZE = 2000;

	private \wpdb $wpdb;
	private GarImportResult $result;

	/** @var array<int,string> */
	private array $stage_columns = array(
		'region_code',
		'region_name',
		'region_type',
		'region_fias_id',
		'region_kladr_id',
		'district_name',
		'district_type',
		'district_fias_id',
		'district_kladr_id',
		'district_gar_object_id',
		'district_level',
		'city_name',
		'city_type',
		'city_fias_id',
		'city_kladr_id',
		'place_name',
		'place_type',
		'place_level',
		'display_name',
		'fias_id',
		'gar_object_id',
		'kladr_id',
		'okato',
		'oktmo',
		'postal_code',
	);

	/** @var array<int,string> */
	private array $required_columns = array(
		'region_code',
		'region_name',
		'place_name',
		'fias_id',
		'gar_object_id',
	);

	public function __construct(
		private LocationRepository $locations,
		private RegionRepository $regions,
		private LocationAliasGenerator $alias_generator,
		?\wpdb $db = null
	) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
		$this->result = new GarImportResult();
	}

	public function import_from_file( string $path ): GarImportResult {
		$this->result = new GarImportResult( started_at: current_time( 'mysql' ) );

		try {
			$this->clear_stage();
			$this->result->stage_rows = $this->load_stage( $path );
			$processed = $this->process_stage();
			$processed->success = true;
			$this->clear_stage();
			return $processed->finish( true );
		} catch ( RuntimeException $exception ) {
			$this->result->errors[] = $exception->getMessage();
			return $this->result->finish( false );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function create_job( string $path, string $job_id = '' ): array {
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'GAR CSV file is not readable.' );
		}

		return array(
			'job_id'               => '' !== $job_id ? $job_id : md5( $path . microtime( true ) ),
			'source_path'          => $path,
			'phase'                => 'staging',
			'rows_total_estimated' => max( 1, (int) floor( filesize( $path ) / 400 ) ),
			'rows_read'            => 0,
			'stage_rows'           => 0,
			'processed_rows'       => 0,
			'locations_imported'   => 0,
			'aliases_imported'     => 0,
			'skipped_rows'         => 0,
			'errors'               => array(),
			'started_at'           => current_time( 'mysql' ),
			'updated_at'           => current_time( 'mysql' ),
			'byte_offset'          => 0,
			'stage_offset'         => 0,
			'header_map'           => array(),
			'regions_imported'     => 0,
		);
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public function step_job( array $job ): array {
		try {
			if ( 'staging' === (string) ( $job['phase'] ?? '' ) ) {
				return $this->step_staging_job( $job );
			}

			if ( 'processing' === (string) ( $job['phase'] ?? '' ) ) {
				return $this->step_processing_job( $job );
			}
		} catch ( RuntimeException $exception ) {
			$job['phase'] = 'failed';
			$job['errors'][] = $exception->getMessage();
		}

		$job['updated_at'] = current_time( 'mysql' );
		return $job;
	}

	public function load_stage( string $path ): int {
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'GAR CSV file is not readable.' );
		}

		$file = new SplFileObject( $path, 'rb' );
		$file->setFlags( SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY );
		$file->setCsvControl( ';', '"', '\\' );

		$header = null;
		foreach ( $file as $row ) {
			if ( ! is_array( $row ) || array( null ) === $row ) {
				continue;
			}

			$header = $this->header_map( $row );
			break;
		}

		if ( null === $header || array() === $header ) {
			throw new RuntimeException( 'GAR CSV header row is missing.' );
		}

		foreach ( $this->required_columns as $required ) {
			if ( ! array_key_exists( $required, $header ) ) {
				throw new RuntimeException( sprintf( 'GAR CSV missing required column: %s', $required ) );
			}
		}

		$batch = array();
		$loaded = 0;
		while ( ! $file->eof() ) {
			$row = $file->fgetcsv();
			if ( ! is_array( $row ) || array( null ) === $row ) {
				continue;
			}

			++$this->result->rows_read;
			$mapped = $this->map_csv_row( $row, $header );
			if ( null === $mapped ) {
				++$this->result->skipped_rows;
				continue;
			}

			$batch[] = $mapped;
			if ( count( $batch ) >= self::CSV_STAGE_BATCH_SIZE ) {
				$loaded += $this->insert_stage_batch( $batch );
				$batch = array();
			}
		}

		if ( array() !== $batch ) {
			$loaded += $this->insert_stage_batch( $batch );
		}

		return $loaded;
	}

	public function process_stage(): GarImportResult {
		$regions = $this->fetch_stage_regions();
		$this->result->regions_imported = $this->regions->bulk_upsert( $regions );

		$offset = 0;
		do {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare( "SELECT * FROM {$this->stage_table()} ORDER BY gar_object_id ASC LIMIT %d OFFSET %d", self::LOCATION_UPSERT_BATCH_SIZE, $offset ),
				ARRAY_A
			);
			$rows = is_array( $rows ) ? $rows : array();
			$locations = array();

			foreach ( $rows as $row ) {
				$location = $this->stage_row_to_location( $row );
				if ( array() !== $location->validate() ) {
					++$this->result->skipped_rows;
					continue;
				}

				$locations[] = $location;
			}

			if ( array() !== $locations ) {
				$this->begin_transaction();
				try {
					$upsert = $this->locations->bulk_upsert_locations( $locations );
					$this->result->locations_imported += (int) $upsert['count'];
					$location_aliases = array();
					foreach ( $locations as $location ) {
						$id = (int) ( $upsert['ids'][ $location->gar_object_id ] ?? 0 );
						if ( $id > 0 ) {
							$location_aliases[ $id ] = $this->alias_generator->generate( $location );
						}
					}
					$this->result->aliases_imported += $this->locations->bulk_save_aliases( $location_aliases, 'gar_import' );
					$this->commit_transaction();
				} catch ( RuntimeException $exception ) {
					$this->rollback_transaction();
					throw $exception;
				}
			}

			$offset += self::LOCATION_UPSERT_BATCH_SIZE;
		} while ( count( $rows ) === self::LOCATION_UPSERT_BATCH_SIZE );

		return $this->result;
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function step_staging_job( array $job ): array {
		$path = (string) ( $job['source_path'] ?? '' );
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'GAR CSV file is not readable.' );
		}

		if ( 0 === (int) ( $job['byte_offset'] ?? 0 ) ) {
			$this->clear_stage();
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'GAR CSV file cannot be opened.' );
		}

		$header = is_array( $job['header_map'] ?? null ) ? $job['header_map'] : array();
		if ( array() === $header ) {
			$row = fgetcsv( $handle, 0, ';', '"', '\\' );
			if ( ! is_array( $row ) || array( null ) === $row ) {
				fclose( $handle );
				throw new RuntimeException( 'GAR CSV header row is missing.' );
			}
			$header = $this->header_map( $row );
			foreach ( $this->required_columns as $required ) {
				if ( ! array_key_exists( $required, $header ) ) {
					fclose( $handle );
					throw new RuntimeException( sprintf( 'GAR CSV missing required column: %s', $required ) );
				}
			}
			$job['header_map'] = $header;
			$job['byte_offset'] = ftell( $handle );
		} else {
			fseek( $handle, (int) $job['byte_offset'] );
		}

		$batch = array();
		$read = 0;
		while ( $read < self::CSV_STAGE_BATCH_SIZE && false !== ( $row = fgetcsv( $handle, 0, ';', '"', '\\' ) ) ) {
			if ( ! is_array( $row ) || array( null ) === $row ) {
				continue;
			}
			++$read;
			++$job['rows_read'];
			$mapped = $this->map_csv_row( $row, $header );
			if ( null === $mapped ) {
				++$job['skipped_rows'];
				continue;
			}
			$batch[] = $mapped;
		}

		if ( array() !== $batch ) {
			$job['stage_rows'] += $this->insert_stage_batch( $batch );
		}

		$job['byte_offset'] = ftell( $handle );
		$eof = feof( $handle );
		fclose( $handle );

		if ( $eof ) {
			$job['phase'] = 'processing';
			$job['stage_offset'] = 0;
			$job['regions_imported'] = $this->regions->bulk_upsert( $this->fetch_stage_regions() );
		}

		$job['updated_at'] = current_time( 'mysql' );
		return $job;
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function step_processing_job( array $job ): array {
		$result = $this->process_stage_chunk( (int) ( $job['stage_offset'] ?? 0 ), self::LOCATION_UPSERT_BATCH_SIZE );
		$job['processed_rows'] += $result['processed'];
		$job['locations_imported'] += $result['locations'];
		$job['aliases_imported'] += $result['aliases'];
		$job['skipped_rows'] += $result['skipped'];
		$job['stage_offset'] = (int) $job['stage_offset'] + self::LOCATION_UPSERT_BATCH_SIZE;
		if ( $result['processed'] < self::LOCATION_UPSERT_BATCH_SIZE ) {
			$this->clear_stage();
			$job['phase'] = 'finished';
		}
		$job['updated_at'] = current_time( 'mysql' );

		return $job;
	}

	/**
	 * @return array{processed:int, locations:int, aliases:int, skipped:int}
	 */
	private function process_stage_chunk( int $offset, int $limit ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$this->stage_table()} ORDER BY gar_object_id ASC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		$locations = array();
		$skipped = 0;
		foreach ( $rows as $row ) {
			$location = $this->stage_row_to_location( $row );
			if ( array() !== $location->validate() ) {
				++$skipped;
				continue;
			}
			$locations[] = $location;
		}

		if ( array() === $locations ) {
			return array( 'processed' => count( $rows ), 'locations' => 0, 'aliases' => 0, 'skipped' => $skipped );
		}

		$this->begin_transaction();
		try {
			$upsert = $this->locations->bulk_upsert_locations( $locations );
			$location_aliases = array();
			foreach ( $locations as $location ) {
				$id = (int) ( $upsert['ids'][ $location->gar_object_id ] ?? 0 );
				if ( $id > 0 ) {
					$location_aliases[ $id ] = $this->alias_generator->generate( $location );
				}
			}
			$aliases = $this->locations->bulk_save_aliases( $location_aliases, 'gar_import' );
			$this->commit_transaction();
		} catch ( \Throwable $exception ) {
			$this->rollback_transaction();
			throw new RuntimeException( $exception->getMessage(), 0, $exception );
		}

		return array( 'processed' => count( $rows ), 'locations' => (int) $upsert['count'], 'aliases' => $aliases, 'skipped' => $skipped );
	}

	public function clear_stage(): void {
		if ( ! $this->table_exists( $this->stage_table() ) ) {
			throw new RuntimeException( 'GAR staging table does not exist. Run plugin migrations first.' );
		}

		$this->wpdb->query( "TRUNCATE TABLE {$this->stage_table()}" );
	}

	/**
	 * @param array<int,mixed> $row
	 * @return array<string,int>
	 */
	private function header_map( array $row ): array {
		$map = array();
		foreach ( $row as $index => $column ) {
			$name = $this->normalize_header( (string) $column );
			if ( '' !== $name && ! isset( $map[ $name ] ) ) {
				$map[ $name ] = (int) $index;
			}
		}

		return $map;
	}

	/**
	 * @param array<int,mixed> $row
	 * @param array<string,int> $header
	 * @return array<string,mixed>|null
	 */
	private function map_csv_row( array $row, array $header ): ?array {
		$mapped = array();
		foreach ( $this->stage_columns as $column ) {
			$mapped[ $column ] = $this->value( $row, $header, $column );
		}

		foreach ( $this->required_columns as $required ) {
			if ( '' === $mapped[ $required ] ) {
				return null;
			}
		}

		$mapped['gar_object_id'] = (int) $mapped['gar_object_id'];
		$mapped['district_gar_object_id'] = '' === $mapped['district_gar_object_id'] ? null : (int) $mapped['district_gar_object_id'];
		$mapped['district_level'] = '' === $mapped['district_level'] ? null : (int) $mapped['district_level'];
		$mapped['place_level'] = '' === $mapped['place_level'] ? null : (int) $mapped['place_level'];

		return $mapped;
	}

	/**
	 * @param array<int,mixed> $row
	 * @param array<string,int> $header
	 */
	private function value( array $row, array $header, string $column ): string {
		if ( ! array_key_exists( $column, $header ) ) {
			return '';
		}

		return $this->clean( $row[ $header[ $column ] ] ?? '' );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function insert_stage_batch( array $rows ): int {
		if ( property_exists( $this->wpdb, 'stage' ) ) {
			foreach ( $rows as $row ) {
				$this->wpdb->insert( $this->stage_table(), $row, $this->stage_formats( $row ) );
			}

			return count( $rows );
		}

		$this->bulk_insert_stage_rows( $rows );

		return count( $rows );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function bulk_insert_stage_rows( array $rows ): void {
		if ( array() === $rows ) {
			return;
		}

		$columns = array_keys( $rows[0] );
		$formats = $this->stage_formats( $rows[0] );
		$row_placeholder = '(' . implode( ', ', $formats ) . ')';
		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES %s',
			$this->stage_table(),
			implode( ', ', $columns ),
			implode( ', ', array_fill( 0, count( $rows ), $row_placeholder ) )
		);
		$args = array();
		foreach ( $rows as $row ) {
			foreach ( $columns as $column ) {
				$args[] = $row[ $column ] ?? null;
			}
		}

		$this->wpdb->query( $this->wpdb->prepare( $sql, ...$args ) );
	}

	/**
	 * @return array<int,Region>
	 */
	private function fetch_stage_regions(): array {
		$rows = $this->wpdb->get_results(
			"SELECT region_code, MAX(region_name) AS region_name, MAX(region_type) AS region_type, MAX(region_fias_id) AS region_fias_id, MAX(region_kladr_id) AS region_kladr_id
			FROM {$this->stage_table()}
			WHERE region_code IS NOT NULL AND region_code != '' AND region_name IS NOT NULL AND region_name != ''
			GROUP BY region_code
			ORDER BY region_code ASC",
			ARRAY_A
		);
		$regions = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$regions[] = Region::from_array( $row );
		}

		return $regions;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function stage_row_to_location( array $row ): Location {
		$display = trim( (string) ( $row['display_name'] ?? '' ) );

		return Location::from_array(
			array(
				'gar_object_id'          => $row['gar_object_id'] ?? 0,
				'fias_id'                => $row['fias_id'] ?? '',
				'kladr_id'               => $row['kladr_id'] ?? '',
				'country_code'           => 'RU',
				'region_name'            => $row['region_name'] ?? '',
				'region_type'            => $row['region_type'] ?? '',
				'region_code'            => $row['region_code'] ?? '',
				'district_name'          => $row['district_name'] ?? '',
				'district_type'          => $row['district_type'] ?? '',
				'district_fias_id'       => $row['district_fias_id'] ?? '',
				'district_kladr_id'      => $row['district_kladr_id'] ?? '',
				'district_gar_object_id' => $row['district_gar_object_id'] ?? 0,
				'district_level'         => $row['district_level'] ?? null,
				'city_name'              => $row['city_name'] ?? '',
				'city_type'              => $row['city_type'] ?? '',
				'city_fias_id'           => $row['city_fias_id'] ?? '',
				'city_kladr_id'          => $row['city_kladr_id'] ?? '',
				'place_name'             => $row['place_name'] ?? '',
				'place_type'             => $row['place_type'] ?? '',
				'place_level'            => $row['place_level'] ?? 0,
				'display_name'           => $display,
				'okato'                  => $row['okato'] ?? '',
				'oktmo'                  => $row['oktmo'] ?? '',
				'postal_code'            => $row['postal_code'] ?? '',
			)
		);
	}

	private function clean( mixed $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		return preg_replace( '/^\xEF\xBB\xBF/', '', $value ) ?? $value;
	}

	private function normalize_header( string $value ): string {
		return strtolower( $this->clean( $value ) );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<int,string>
	 */
	private function stage_formats( array $row ): array {
		$integer_columns = array(
			'gar_object_id'          => true,
			'district_gar_object_id' => true,
			'district_level'         => true,
			'place_level'            => true,
		);

		$formats = array();
		foreach ( array_keys( $row ) as $column ) {
			$formats[] = isset( $integer_columns[ $column ] ) ? '%d' : '%s';
		}

		return $formats;
	}

	private function table_exists( string $table ): bool {
		$prepared = $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table );
		$result = $this->wpdb->get_var( $prepared );
		return ! in_array( $result, array( null, '', 0, '0' ), true );
	}

	private function stage_table(): string {
		return $this->wpdb->prefix . 'wdc_gar_places_stage';
	}

	private function begin_transaction(): void {
		$this->wpdb->query( 'START TRANSACTION' );
	}

	private function commit_transaction(): void {
		$this->wpdb->query( 'COMMIT' );
	}

	private function rollback_transaction(): void {
		$this->wpdb->query( 'ROLLBACK' );
	}
}

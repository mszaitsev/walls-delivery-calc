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
	private const BATCH_SIZE = 1000;

	private \wpdb $wpdb;
	private GarImportResult $result;

	/** @var array<int,string> */
	private array $columns = array(
		'region_code',
		'region_name',
		'region_type',
		'region_fias_id',
		'region_kladr_id',
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

	public function load_stage( string $path ): int {
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'GAR CSV file is not readable.' );
		}

		$file = new SplFileObject( $path, 'rb' );
		$file->setFlags( SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY );
		$file->setCsvControl( ';', '"', '\\' );

		$batch = array();
		$loaded = 0;
		foreach ( $file as $row ) {
			if ( ! is_array( $row ) || array( null ) === $row ) {
				continue;
			}

			if ( 0 === $this->result->rows_read && isset( $row[0] ) && 'region_code' === $this->clean( $row[0] ) ) {
				continue;
			}

			++$this->result->rows_read;
			$mapped = $this->map_csv_row( $row );
			if ( null === $mapped ) {
				++$this->result->skipped_rows;
				continue;
			}

			$batch[] = $mapped;
			if ( count( $batch ) >= self::BATCH_SIZE ) {
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
				$this->wpdb->prepare( "SELECT * FROM {$this->stage_table()} ORDER BY gar_object_id ASC LIMIT %d OFFSET %d", self::BATCH_SIZE, $offset ),
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

			foreach ( $locations as $location ) {
				$id = $this->locations->save( $location );
				$aliases = $this->alias_generator->generate( $location );
				$this->locations->save_aliases( $id, $aliases, 'gar_import' );
				++$this->result->locations_imported;
				$this->result->aliases_imported += count( $aliases );
			}

			$offset += self::BATCH_SIZE;
		} while ( count( $rows ) === self::BATCH_SIZE );

		return $this->result;
	}

	public function clear_stage(): void {
		$this->wpdb->query( "TRUNCATE TABLE {$this->stage_table()}" );
	}

	/**
	 * @param array<int,mixed> $row
	 * @return array<string,mixed>|null
	 */
	private function map_csv_row( array $row ): ?array {
		$mapped = array();
		foreach ( $this->columns as $index => $column ) {
			$mapped[ $column ] = $this->clean( $row[ $index ] ?? '' );
		}

		if (
			'' === $mapped['gar_object_id']
			|| '' === $mapped['fias_id']
			|| '' === $mapped['place_name']
			|| '' === $mapped['region_code']
		) {
			return null;
		}

		$mapped['gar_object_id'] = (int) $mapped['gar_object_id'];
		$mapped['place_level'] = '' === $mapped['place_level'] ? null : (int) $mapped['place_level'];

		return $mapped;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function insert_stage_batch( array $rows ): int {
		foreach ( $rows as $row ) {
			$this->wpdb->insert( $this->stage_table(), $row, $this->stage_formats() );
		}

		return count( $rows );
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
		if ( '' === $display ) {
			$parts = array_filter(
				array(
					trim( (string) ( $row['place_type'] ?? '' ) . ' ' . (string) ( $row['place_name'] ?? '' ) ),
					(string) ( $row['region_name'] ?? '' ),
				)
			);
			$display = implode( ', ', $parts );
		}

		return Location::from_array(
			array(
				'gar_object_id' => $row['gar_object_id'] ?? 0,
				'fias_id'       => $row['fias_id'] ?? '',
				'kladr_id'      => $row['kladr_id'] ?? '',
				'country_code'  => 'RU',
				'region_name'   => $row['region_name'] ?? '',
				'region_type'   => $row['region_type'] ?? '',
				'region_code'   => $row['region_code'] ?? '',
				'city_name'     => $row['city_name'] ?? '',
				'city_type'     => $row['city_type'] ?? '',
				'city_fias_id'  => $row['city_fias_id'] ?? '',
				'city_kladr_id' => $row['city_kladr_id'] ?? '',
				'place_name'    => $row['place_name'] ?? '',
				'place_type'    => $row['place_type'] ?? '',
				'place_level'   => $row['place_level'] ?? 0,
				'display_name'  => $display,
				'okato'         => $row['okato'] ?? '',
				'oktmo'         => $row['oktmo'] ?? '',
				'postal_code'   => $row['postal_code'] ?? '',
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

	/**
	 * @return array<int,string>
	 */
	private function stage_formats(): array {
		return array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );
	}

	private function stage_table(): string {
		return $this->wpdb->prefix . 'wdc_gar_places_stage';
	}
}

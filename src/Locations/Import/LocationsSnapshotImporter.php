<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Import;

use RuntimeException;
use SplFileObject;
use WallsShop\WDC\Locations\Services\LocationCountryIndexService;

defined( 'ABSPATH' ) || exit;

final class LocationsSnapshotImporter {
	private \wpdb $wpdb;

	/** @var array<int,string> */
	private array $tables = array(
		'wdc_regions',
		'wdc_locations',
		'wdc_location_aliases',
		'wdc_location_carrier_codes',
	);

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	public function import_from_file( string $path ): int {
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'Snapshot file is not readable.' );
		}

		$file = new SplFileObject( $path, 'rb' );
		$first = trim( (string) $file->fgets() );
		$meta = json_decode( $first, true );
		if ( ! is_array( $meta ) || 'meta' !== ( $meta['type'] ?? '' ) ) {
			throw new RuntimeException( 'Snapshot meta row is missing.' );
		}

		foreach ( array_reverse( $this->tables ) as $table ) {
			$this->wpdb->query( 'TRUNCATE TABLE ' . $this->wpdb->prefix . $table );
		}

		$columns = $this->table_columns();
		$imported = 0;
		while ( ! $file->eof() ) {
			$line = trim( (string) $file->fgets() );
			if ( '' === $line ) {
				continue;
			}

			$row = json_decode( $line, true );
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( $this->import_option_row( $row ) ) {
				++$imported;
				continue;
			}
			if ( 'row' !== ( $row['type'] ?? '' ) ) {
				continue;
			}

			$table = (string) ( $row['table'] ?? '' );
			if ( ! in_array( $table, $this->tables, true ) || ! is_array( $row['data'] ?? null ) ) {
				continue;
			}

			$data = $this->normalize_row( $table, $row['data'] );
			$data = array_intersect_key( $data, array_flip( $columns[ $table ] ?? array() ) );
			if ( array() === $data ) {
				continue;
			}

			$this->wpdb->insert( $this->wpdb->prefix . $table, $data, array_fill( 0, count( $data ), '%s' ) );
			++$imported;
		}

		LocationCountryIndexService::mark_option_stale();
		return $imported;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function create_job( string $path ): array {
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'Snapshot file is not readable.' );
		}

		return array(
			'job_id'      => md5( $path . microtime( true ) ),
			'path'        => $path,
			'phase'       => 'importing',
			'byte_offset' => 0,
			'rows_read'   => 0,
			'imported'    => 0,
			'skipped'     => 0,
			'started_at'  => current_time( 'mysql' ),
			'updated_at'  => current_time( 'mysql' ),
			'errors'      => array(),
		);
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public function step_job( array $job, int $line_limit = 1000 ): array {
		try {
			$path = (string) ( $job['path'] ?? '' );
			if ( ! is_readable( $path ) ) {
				throw new RuntimeException( 'Snapshot file is not readable.' );
			}

			$handle = fopen( $path, 'rb' );
			if ( false === $handle ) {
				throw new RuntimeException( 'Snapshot file cannot be opened.' );
			}

			if ( 0 === (int) ( $job['byte_offset'] ?? 0 ) ) {
				$first = trim( (string) fgets( $handle ) );
				$meta = json_decode( $first, true );
				if ( ! is_array( $meta ) || 'meta' !== ( $meta['type'] ?? '' ) ) {
					fclose( $handle );
					throw new RuntimeException( 'Snapshot meta row is missing.' );
				}
				foreach ( array_reverse( $this->tables ) as $table ) {
					$this->wpdb->query( 'TRUNCATE TABLE ' . $this->wpdb->prefix . $table );
				}
				$job['byte_offset'] = ftell( $handle );
			} else {
				fseek( $handle, (int) $job['byte_offset'] );
			}

			$columns = $this->table_columns();
			$read = 0;
			while ( $read < $line_limit && false !== ( $line = fgets( $handle ) ) ) {
				++$read;
				++$job['rows_read'];
				$row = json_decode( trim( $line ), true );
				if ( ! is_array( $row ) ) {
					++$job['skipped'];
					continue;
				}
				if ( $this->import_option_row( $row ) ) {
					++$job['imported'];
					continue;
				}
				if ( 'row' !== ( $row['type'] ?? '' ) ) {
					++$job['skipped'];
					continue;
				}
				$table = (string) ( $row['table'] ?? '' );
				if ( ! in_array( $table, $this->tables, true ) || ! is_array( $row['data'] ?? null ) ) {
					++$job['skipped'];
					continue;
				}
				$data = array_intersect_key( $this->normalize_row( $table, $row['data'] ), array_flip( $columns[ $table ] ?? array() ) );
				if ( array() === $data ) {
					++$job['skipped'];
					continue;
				}
				$this->wpdb->insert( $this->wpdb->prefix . $table, $data, array_fill( 0, count( $data ), '%s' ) );
				++$job['imported'];
			}

			$job['byte_offset'] = ftell( $handle );
			$eof = feof( $handle );
			fclose( $handle );
			if ( $eof ) {
				$job['phase'] = 'finished';
				LocationCountryIndexService::mark_option_stale();
			}
		} catch ( RuntimeException $exception ) {
			$job['phase'] = 'failed';
			$job['errors'][] = $exception->getMessage();
		}

		$job['updated_at'] = current_time( 'mysql' );
		return $job;
	}

	/**
	 * @return array<string,array<int,string>>
	 */
	private function table_columns(): array {
		$result = array();
		foreach ( $this->tables as $table ) {
			$rows = $this->wpdb->get_results( 'DESCRIBE ' . $this->wpdb->prefix . $table, ARRAY_A );
			$result[ $table ] = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( isset( $row['Field'] ) ) {
					$result[ $table ][] = (string) $row['Field'];
				}
			}
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function normalize_row( string $table, array $data ): array {
		if ( 'wdc_locations' === $table ) {
			$data['postal_code'] = (string) ( $data['postal_code'] ?? $data['postcode'] ?? '' );
			unset( $data['postcode'] );
		}

		return $data;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function import_option_row( array $row ): bool {
		if ( 'option' !== ( $row['type'] ?? '' ) || 'wdc_location_type_display_rules' !== (string) ( $row['name'] ?? '' ) ) {
			return false;
		}

		update_option( 'wdc_location_type_display_rules', $this->sanitize_type_rules( $row['data'] ?? array() ) );
		return true;
	}

	/**
	 * @param mixed $raw
	 * @return array<string,array<string,array{display:string,position:string}>>
	 */
	private function sanitize_type_rules( mixed $raw ): array {
		$result = array( 'region' => array(), 'city' => array(), 'place' => array() );
		if ( ! is_array( $raw ) ) {
			return $result;
		}

		foreach ( array( 'region', 'city', 'place' ) as $scope ) {
			foreach ( is_array( $raw[ $scope ] ?? null ) ? $raw[ $scope ] : array() as $source => $rule ) {
				$source = sanitize_text_field( wp_unslash( (string) $source ) );
				if ( '' === $source || ! is_array( $rule ) ) {
					continue;
				}
				$position = sanitize_key( wp_unslash( (string) ( $rule['position'] ?? $this->default_type_position( $scope ) ) ) );
				if ( ! in_array( $position, array( 'before', 'after', 'hidden' ), true ) ) {
					$position = $this->default_type_position( $scope );
				}
				$result[ $scope ][ $source ] = array(
					'display'  => sanitize_text_field( wp_unslash( (string) ( $rule['display'] ?? $source ) ) ),
					'position' => $position,
				);
			}
		}

		return $result;
	}

	private function default_type_position( string $scope ): string {
		return 'region' === $scope ? 'after' : 'before';
	}
}

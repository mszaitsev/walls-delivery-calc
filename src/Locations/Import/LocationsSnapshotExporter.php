<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Import;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class LocationsSnapshotExporter {
	private \wpdb $wpdb;

	/** @var array<int,string> */
	private array $tables = array(
		'wdc_regions',
		'wdc_locations',
		'wdc_location_aliases',
		'wdc_location_carrier_codes',
	);

	/** @var array<int,string> */
	private array $options = array(
		'wdc_location_type_display_rules',
	);

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	public function export_to_file( string $path, string $version = '0.15.10', int $page_size = 1000 ): int {
		$handle = fopen( $path, 'wb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Snapshot file cannot be opened for writing.' );
		}

		$rows = 0;
		fwrite(
			$handle,
			$this->encode(
				array(
					'type'       => 'meta',
					'version'    => $version,
					'tables'     => $this->tables,
					'options'    => $this->options,
					'created_at' => current_time( 'mysql' ),
				),
				JSON_UNESCAPED_UNICODE
			) . "\n"
		);
		foreach ( $this->option_rows() as $option_row ) {
			fwrite( $handle, $this->encode( $option_row, JSON_UNESCAPED_UNICODE ) . "\n" );
			++$rows;
		}

		foreach ( $this->tables as $table ) {
			$full_table = $this->wpdb->prefix . $table;
			$offset = 0;
			do {
				$data = $this->wpdb->get_results(
					$this->wpdb->prepare( "SELECT * FROM {$full_table} LIMIT %d OFFSET %d", $page_size, $offset ),
					ARRAY_A
				);
				$data = is_array( $data ) ? $data : array();
				foreach ( $data as $row ) {
					$row = $this->export_row( $table, $row );
					fwrite(
						$handle,
						$this->encode(
							array(
								'type'  => 'row',
								'table' => $table,
								'data'  => $row,
							),
							JSON_UNESCAPED_UNICODE
						) . "\n"
					);
					++$rows;
				}
				$offset += $page_size;
			} while ( count( $data ) === $page_size );
		}

		fclose( $handle );

		return $rows;
	}

	public function stream_download( string $version = '0.15.10' ): void {
		$file = wp_tempnam( 'wdc-locations-snapshot-' );
		if ( ! is_string( $file ) || '' === $file ) {
			throw new RuntimeException( 'Unable to create temporary snapshot file.' );
		}

		$this->export_to_file( $file, $version );
		header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wdc-locations-snapshot-' . gmdate( 'Ymd-His' ) . '.jsonl"' );
		readfile( $file );
		@unlink( $file );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function create_job( string $path, string $version = '0.15.10' ): array {
		return array(
			'job_id'        => md5( $path . microtime( true ) ),
			'path'          => $path,
			'version'       => $version,
			'phase'         => 'exporting',
			'table_index'   => 0,
			'offset'        => 0,
			'rows_exported' => 0,
			'total_rows'    => $this->total_rows() + count( $this->options ),
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
			'errors'        => array(),
		);
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public function step_job( array $job, int $page_size = 1000 ): array {
		try {
			if ( 'exporting' !== (string) ( $job['phase'] ?? '' ) ) {
				return $job;
			}

			$path = (string) ( $job['path'] ?? '' );
			if ( '' === $path ) {
				throw new RuntimeException( 'Snapshot export path is missing.' );
			}

			if ( 0 === (int) ( $job['table_index'] ?? 0 ) && 0 === (int) ( $job['offset'] ?? 0 ) && ( ! file_exists( $path ) || 0 === (int) filesize( $path ) ) ) {
				file_put_contents(
					$path,
					$this->encode(
						array(
							'type'       => 'meta',
							'version'    => (string) ( $job['version'] ?? '0.15.10' ),
							'tables'     => $this->tables,
							'options'    => $this->options,
							'created_at' => current_time( 'mysql' ),
						)
					) . "\n"
				);
				$handle = fopen( $path, 'ab' );
				if ( false === $handle ) {
					throw new RuntimeException( 'Snapshot export file cannot be opened.' );
				}
				foreach ( $this->option_rows() as $option_row ) {
					fwrite( $handle, $this->encode( $option_row ) . "\n" );
					++$job['rows_exported'];
				}
				fclose( $handle );
			}

			$table_index = (int) ( $job['table_index'] ?? 0 );
			if ( ! isset( $this->tables[ $table_index ] ) ) {
				$job['phase'] = 'finished';
				$job['updated_at'] = current_time( 'mysql' );
				return $job;
			}

			$table = $this->tables[ $table_index ];
			$full_table = $this->wpdb->prefix . $table;
			$offset = (int) ( $job['offset'] ?? 0 );
			$data = $this->wpdb->get_results(
				$this->wpdb->prepare( "SELECT * FROM {$full_table} LIMIT %d OFFSET %d", $page_size, $offset ),
				ARRAY_A
			);
			$data = is_array( $data ) ? $data : array();
			$handle = fopen( $path, 'ab' );
			if ( false === $handle ) {
				throw new RuntimeException( 'Snapshot export file cannot be opened.' );
			}
			foreach ( $data as $row ) {
				fwrite( $handle, $this->encode( array( 'type' => 'row', 'table' => $table, 'data' => $this->export_row( $table, $row ) ) ) . "\n" );
				++$job['rows_exported'];
			}
			fclose( $handle );

			if ( count( $data ) < $page_size ) {
				$job['table_index'] = $table_index + 1;
				$job['offset'] = 0;
				if ( ! isset( $this->tables[ (int) $job['table_index'] ] ) ) {
					$job['phase'] = 'finished';
				}
			} else {
				$job['offset'] = $offset + $page_size;
			}
		} catch ( RuntimeException $exception ) {
			$job['phase'] = 'failed';
			$job['errors'][] = $exception->getMessage();
		}

		$job['updated_at'] = current_time( 'mysql' );
		return $job;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function encode( array $data ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) : json_encode( $data, JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
	}

	private function total_rows(): int {
		$total = 0;
		foreach ( $this->tables as $table ) {
			$total += (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->wpdb->prefix . $table );
		}

		return $total;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function export_row( string $table, array $row ): array {
		if ( 'wdc_locations' !== $table ) {
			return $row;
		}

		unset( $row['postcode'] );

		return $row;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function option_rows(): array {
		return array(
			array(
				'type' => 'option',
				'name' => 'wdc_location_type_display_rules',
				'data' => $this->sanitize_type_rules( get_option( 'wdc_location_type_display_rules', array() ) ),
			),
		);
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

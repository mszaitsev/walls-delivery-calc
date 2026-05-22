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

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	public function export_to_file( string $path, string $version = '0.15.0', int $page_size = 1000 ): int {
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
					'created_at' => current_time( 'mysql' ),
				),
				JSON_UNESCAPED_UNICODE
			) . "\n"
		);

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

	public function stream_download( string $version = '0.15.0' ): void {
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
	 * @param array<string,mixed> $data
	 */
	private function encode( array $data ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) : json_encode( $data, JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
	}
}

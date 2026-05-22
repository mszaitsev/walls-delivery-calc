<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Import;

use RuntimeException;
use SplFileObject;

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
			if ( ! is_array( $row ) || 'row' !== ( $row['type'] ?? '' ) ) {
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

		return $imported;
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
}

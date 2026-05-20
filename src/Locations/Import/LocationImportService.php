<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Import;

use RuntimeException;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class LocationImportService {
	public function __construct( private LocationRepository $repository ) {
	}

	/**
	 * @param array<int, array<string,mixed>> $rows
	 */
	public function import_from_array( array $rows ): int {
		$imported = 0;

		foreach ( $rows as $row ) {
			$location = $this->row_to_location( $row );
			if ( array() !== $location->validate() ) {
				continue;
			}

			$this->repository->save( $location );
			++$imported;
		}

		return $imported;
	}

	public function import_from_json_file( string $file ): int {
		if ( ! is_readable( $file ) ) {
			throw new RuntimeException( 'JSON file is not readable.' );
		}

		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'JSON file must contain an array of location rows.' );
		}

		return $this->import_from_array( $data );
	}

	public function import_from_csv( string $file ): int {
		if ( ! is_readable( $file ) ) {
			throw new RuntimeException( 'CSV file is not readable.' );
		}

		$handle = fopen( $file, 'rb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'CSV file cannot be opened.' );
		}

		$rows = array();
		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			if ( isset( $row[0] ) && 'country_code' === $row[0] ) {
				continue;
			}

			$rows[] = array(
				'country_code'    => (string) ( $row[0] ?? '' ),
				'region_name'     => (string) ( $row[1] ?? '' ),
				'city_name'       => (string) ( $row[2] ?? '' ),
				'settlement_name' => (string) ( $row[3] ?? '' ),
				'postcode'        => (string) ( $row[4] ?? '' ),
				'fias_id'         => (string) ( $row[5] ?? '' ),
				'gar_id'          => (string) ( $row[6] ?? '' ),
			);
		}

		fclose( $handle );

		return $this->import_from_array( $rows );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function row_to_location( array $row ): Location {
		$city       = trim( (string) ( $row['city_name'] ?? $row['city'] ?? '' ) );
		$settlement = trim( (string) ( $row['settlement_name'] ?? $row['settlement'] ?? '' ) );
		$region     = trim( (string) ( $row['region_name'] ?? '' ) );
		$name       = '' !== $settlement ? $settlement : $city;
		$display    = trim( (string) ( $row['display_name'] ?? '' ) );

		if ( '' === $display ) {
			$display = '' !== $region ? sprintf( '%s — %s', $name, $region ) : $name;
		}

		return new Location(
			null,
			trim( (string) ( $row['fias_id'] ?? '' ) ),
			trim( (string) ( $row['gar_id'] ?? '' ) ),
			strtoupper( trim( (string) ( $row['country_code'] ?? '' ) ) ),
			$region,
			trim( (string) ( $row['region_code'] ?? '' ) ),
			$city,
			$settlement,
			trim( (string) ( $row['settlement_type'] ?? '' ) ),
			$display,
			trim( (string) ( $row['postcode'] ?? '' ) ),
			isset( $row['latitude'] ) && '' !== (string) $row['latitude'] ? (float) $row['latitude'] : null,
			isset( $row['longitude'] ) && '' !== (string) $row['longitude'] ? (float) $row['longitude'] : null,
			(bool) ( $row['active'] ?? true )
		);
	}
}

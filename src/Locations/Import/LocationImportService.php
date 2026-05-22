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
				'postal_code'     => (string) ( $row[4] ?? '' ),
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

		return Location::from_array(
			array(
				'fias_id'                => $this->fias_id( $row['fias_id'] ?? '', $row['gar_id'] ?? '' ),
				'gar_id'                 => trim( (string) ( $row['gar_id'] ?? '' ) ),
				'country_code'           => strtoupper( trim( (string) ( $row['country_code'] ?? '' ) ) ),
				'region_name'            => $region,
				'region_code'            => trim( (string) ( $row['region_code'] ?? '' ) ),
				'district_name'          => trim( (string) ( $row['district_name'] ?? '' ) ),
				'district_type'          => trim( (string) ( $row['district_type'] ?? '' ) ),
				'district_fias_id'       => trim( (string) ( $row['district_fias_id'] ?? '' ) ),
				'district_kladr_id'      => trim( (string) ( $row['district_kladr_id'] ?? '' ) ),
				'district_gar_object_id' => $row['district_gar_object_id'] ?? 0,
				'district_level'         => $row['district_level'] ?? null,
				'city_name'              => $city,
				'city_type'              => trim( (string) ( $row['city_type'] ?? '' ) ),
				'city_fias_id'           => trim( (string) ( $row['city_fias_id'] ?? '' ) ),
				'city_kladr_id'          => trim( (string) ( $row['city_kladr_id'] ?? '' ) ),
				'settlement_name'        => $settlement,
				'settlement_type'        => trim( (string) ( $row['settlement_type'] ?? '' ) ),
				'display_name'           => $display,
				'latitude'               => $row['latitude'] ?? null,
				'longitude'              => $row['longitude'] ?? null,
				'active'                 => (bool) ( $row['active'] ?? true ),
				'gar_object_id'          => $this->gar_object_id( $row['gar_object_id'] ?? $row['gar_id'] ?? '' ),
				'kladr_id'               => trim( (string) ( $row['kladr_id'] ?? '' ) ),
				'place_name'             => trim( (string) ( $row['place_name'] ?? $settlement ?: $city ) ),
				'place_type'             => trim( (string) ( $row['place_type'] ?? $row['settlement_type'] ?? '' ) ),
				'place_level'            => $row['place_level'] ?? 0,
				'okato'                  => trim( (string) ( $row['okato'] ?? '' ) ),
				'oktmo'                  => trim( (string) ( $row['oktmo'] ?? '' ) ),
				'postal_code'            => trim( (string) ( $row['postal_code'] ?? '' ) ),
			)
		);
	}

	private function gar_object_id( mixed $value ): int {
		$value = trim( (string) $value );
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		return '' !== $value ? (int) sprintf( '%u', crc32( $value ) ) : 0;
	}

	private function fias_id( mixed $value, mixed $fallback ): string {
		$value = trim( (string) $value );
		if ( '' !== $value ) {
			return $value;
		}

		$fallback = trim( (string) $fallback );
		return '' !== $fallback ? 'legacy-' . $fallback : '';
	}
}

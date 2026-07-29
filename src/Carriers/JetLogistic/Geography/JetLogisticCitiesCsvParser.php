<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

defined( 'ABSPATH' ) || exit;

final class JetLogisticCitiesCsvParser {
	public function __construct(
		private JetLogisticCityNameNormalizer $normalizer,
		private ?JetLogisticRegionNameNormalizer $region_normalizer = null
	) {
		$this->region_normalizer ??= new JetLogisticRegionNameNormalizer();
	}

	/** @return array<int,array<string,mixed>> */
	public function parse( string $csv ): array {
		$handle = fopen( 'php://temp', 'r+' );
		if ( false === $handle ) {
			return array();
		}
		fwrite( $handle, $csv );
		rewind( $handle );
		$header = null;
		$rows = array();
		while ( false !== ( $line = fgetcsv( $handle, 0, ';', '"', '\\' ) ) ) {
			if ( 1 === count( $line ) && str_contains( (string) $line[0], ',' ) ) {
				$line = str_getcsv( (string) $line[0], ',' );
			}
			$line = array_map( static fn( mixed $value ): string => trim( (string) $value ), $line );
			if ( null === $header ) {
				$header = $this->header( $line );
				continue;
			}
			$row = $this->row( $header, $line );
			$raw_city = trim( (string) ( $row['city'] ?? '' ) );
			$city = $raw_city;
			$region = trim( (string) ( $row['region'] ?? '' ) );
			$split = $this->split_city_and_region( $city, $region );
			$city = $split['city'];
			$region = $split['region'];
			$country = strtoupper( trim( (string) ( $row['country_code'] ?? $row['country'] ?? '' ) ) );
			if ( '' === $city ) {
				$rows[] = array( 'match_status' => 'invalid', 'raw_source' => $row );
				continue;
			}
			$rows[] = array(
				'source_identity' => $this->source_identity( $city, $region ),
				'legacy_source_identity' => $this->normalizer->identity( $raw_city, '', 'RU' ),
				'source_city' => $city,
				'source_region' => $region,
				'raw_source' => $row,
				'normalized_city' => $this->normalizer->normalize( $city ),
				'normalized_region' => $this->region_normalizer->normalize( $region ),
				'country_code' => $country,
				'match_status' => 'parsed',
			);
		}
		fclose( $handle );

		return $rows;
	}

	/** @return array{city:string,region:string} */
	private function split_city_and_region( string $city, string $region ): array {
		if ( '' === trim( $region ) && 1 === preg_match( '/^\s*(.+?)\s*-\s*\((.+?)\)\s*$/u', $city, $matches ) ) {
			$city = trim( (string) $matches[1] );
			$region = trim( (string) $matches[2] );
		}

		return array( 'city' => trim( $city ), 'region' => trim( $region ) );
	}

	private function source_identity( string $city, string $region ): string {
		return hash( 'sha256', $this->region_normalizer->normalize( $region ) . '|' . $this->normalizer->normalize( $city ) );
	}

	/** @param array<int,string> $line @return array<int,string> */
	private function header( array $line ): array {
		return array_map(
			static function ( string $value ): string {
				$key = mb_strtolower( trim( $value ), 'UTF-8' );
				return match ( $key ) {
					'city', 'город', 'населенный пункт', 'населённый пункт' => 'city',
					'region', 'регион', 'область' => 'region',
					'country', 'страна' => 'country',
					'country_code', 'код страны' => 'country_code',
					default => $key,
				};
			},
			$line
		);
	}

	/** @param array<int,string> $header @param array<int,string> $line @return array<string,string> */
	private function row( array $header, array $line ): array {
		$row = array();
		foreach ( $header as $index => $key ) {
			$row[ $key ] = (string) ( $line[ $index ] ?? '' );
		}

		return $row;
	}
}

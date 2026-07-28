<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

defined( 'ABSPATH' ) || exit;

final class JetLogisticCitiesCsvParser {
	public function __construct( private JetLogisticCityNameNormalizer $normalizer ) {
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
			$city = trim( (string) ( $row['city'] ?? '' ) );
			$region = trim( (string) ( $row['region'] ?? '' ) );
			$country = strtoupper( trim( (string) ( $row['country_code'] ?? $row['country'] ?? '' ) ) );
			if ( '' === $city ) {
				$rows[] = array( 'match_status' => 'invalid', 'raw_source' => $row );
				continue;
			}
			if ( '' === $country ) {
				$country = 'RU';
			}
			$rows[] = array(
				'source_identity' => $this->normalizer->identity( $city, $region, $country ),
				'source_city' => $city,
				'source_region' => $region,
				'raw_source' => $row,
				'normalized_city' => $this->normalizer->normalize( $city ),
				'normalized_region' => $this->normalizer->normalize( $region ),
				'country_code' => $country,
				'match_status' => 'parsed',
			);
		}
		fclose( $handle );

		return $rows;
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

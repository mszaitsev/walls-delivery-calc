<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

use Generator;
use SplFileObject;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyCsvParser {
	private const POSITIONAL_COLUMNS = array(
		0 => 'dpd_city_id',
		1 => 'country_code',
		2 => 'region',
		3 => 'district',
		4 => 'main_city',
		5 => 'settlement',
		6 => 'settlement_type',
		7 => 'postal_code',
		8 => 'fias',
		9 => 'kladr',
	);

	private const HEADER_MAP = array(
		'id нп' => 'dpd_city_id',
		'код страны' => 'country_code',
		'регион' => 'region',
		'район' => 'district',
		'основной город' => 'main_city',
		'населённый пункт' => 'settlement',
		'населенный пункт' => 'settlement',
		'тип нп' => 'settlement_type',
		'индекс нп' => 'postal_code',
		'фиас' => 'fias',
		'код кладр' => 'kladr',
	);

	/**
	 * @return Generator<int,array<string,string>>
	 */
	public function rows( string $path ): Generator {
		$file = new SplFileObject( $path, 'rb' );
		$file->setFlags( SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY );
		$file->setCsvControl( ';', '"', '\\' );

		$columns = self::POSITIONAL_COLUMNS;
		$first = true;
		foreach ( $file as $row ) {
			if ( ! is_array( $row ) || array( null ) === $row ) {
				continue;
			}
			$row = array_map( fn( mixed $value ): string => $this->to_utf8( (string) $value ), $row );
			if ( $first ) {
				$first = false;
				if ( $this->looks_like_header( $row ) ) {
					$columns = $this->columns_from_header( $row );
					continue;
				}
			}

			$mapped = array();
			foreach ( $columns as $index => $key ) {
				$mapped[ $key ] = trim( (string) ( $row[ $index ] ?? '' ) );
			}
			foreach ( self::POSITIONAL_COLUMNS as $key ) {
				$mapped[ $key ] = (string) ( $mapped[ $key ] ?? '' );
			}

			yield $mapped;
		}
	}

	/**
	 * @param array<int,string> $row
	 */
	private function looks_like_header( array $row ): bool {
		$joined = $this->normalize_header( implode( ' ', array_slice( $row, 0, 10 ) ) );
		return str_contains( $joined, 'id нп' ) || str_contains( $joined, 'код страны' );
	}

	/**
	 * @param array<int,string> $row
	 * @return array<int,string>
	 */
	private function columns_from_header( array $row ): array {
		$columns = array();
		foreach ( $row as $index => $label ) {
			$key = self::HEADER_MAP[ $this->normalize_header( $label ) ] ?? '';
			if ( '' !== $key ) {
				$columns[ $index ] = $key;
			}
		}

		return array() !== $columns ? $columns : self::POSITIONAL_COLUMNS;
	}

	private function normalize_header( string $value ): string {
		$value = trim( $value, " \t\n\r\0\x0B\xEF\xBB\xBF" );
		$value = str_replace( 'Ё', 'Е', $value );
		$value = str_replace( 'ё', 'е', $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		return preg_replace( '/\s+/u', ' ', $value ) ?? $value;
	}

	private function to_utf8( string $value ): string {
		if ( function_exists( 'mb_check_encoding' ) && mb_check_encoding( $value, 'UTF-8' ) ) {
			return $value;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return mb_convert_encoding( $value, 'UTF-8', 'Windows-1251' );
		}
		if ( function_exists( 'iconv' ) ) {
			$converted = iconv( 'Windows-1251', 'UTF-8//IGNORE', $value );
			return false !== $converted ? $converted : $value;
		}

		return $value;
	}
}

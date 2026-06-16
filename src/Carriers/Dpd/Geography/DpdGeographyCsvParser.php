<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

use Generator;
defined( 'ABSPATH' ) || exit;

final class DpdGeographyCsvParser {
	private const MAX_CSV_ROW_LENGTH = 262144;

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
		$inspect = $this->inspect_header( $path );
		$offset = (int) $inspect['data_offset'];
		do {
			$step = $this->read_step( $path, $offset, $inspect['columns'], 5000 );
			foreach ( $step['rows'] as $row ) {
				yield $row;
			}
			$offset = (int) $step['new_byte_offset'];
		} while ( ! $step['eof'] );
	}

	/**
	 * @return array{columns:array<int,string>,data_offset:int,has_header:bool}
	 */
	public function inspect_header( string $path ): array {
		$columns = self::POSITIONAL_COLUMNS;
		$data_offset = 0;
		$has_header = false;
		$file = fopen( $path, 'rb' );
		if ( ! is_resource( $file ) ) {
			throw new \RuntimeException( 'Unable to read DPD Geography CSV header. Check file encoding and row length.' );
		}

		$read_any = false;
		while ( false !== ( $row = $this->read_csv_row( $file ) ) ) {
			if ( ! is_array( $row ) || array( null ) === $row ) {
				continue;
			}
			$read_any = true;
			$row = array_map( fn( mixed $value ): string => $this->to_utf8( (string) $value ), $row );
			if ( $this->looks_like_header( $row ) ) {
				$columns = $this->columns_from_header( $row );
				$data_offset = (int) ftell( $file );
				$has_header = true;
			}
			break;
		}
		fclose( $file );
		if ( ! $read_any ) {
			throw new \RuntimeException( 'Unable to read DPD Geography CSV header. Check file encoding and row length.' );
		}

		return array(
			'columns' => $columns,
			'data_offset' => $data_offset,
			'has_header' => $has_header,
		);
	}

	/**
	 * @param array<int,string> $columns
	 * @return array{rows:array<int,array<string,string>>,new_byte_offset:int,eof:bool,rows_read_count:int}
	 */
	public function read_step( string $path, int $byte_offset, array $columns, int $limit = 3000 ): array {
		$limit = max( 1, min( 10000, $limit ) );
		$file = fopen( $path, 'rb' );
		if ( ! is_resource( $file ) ) {
			throw new \RuntimeException( 'Unable to open DPD Geography CSV for reading.' );
		}
		if ( $byte_offset > 0 ) {
			fseek( $file, $byte_offset );
		}

		$rows = array();
		$count = 0;
		while ( $count < $limit && false !== ( $row = $this->read_csv_row( $file ) ) ) {
			if ( ! is_array( $row ) || array( null ) === $row ) {
				continue;
			}
			$row = array_map( fn( mixed $value ): string => $this->to_utf8( (string) $value ), $row );
			$mapped = $this->map_row( $row, $columns );
			if ( array() === $mapped ) {
				continue;
			}
			$rows[] = $mapped;
			++$count;
		}
		$new_offset = (int) ftell( $file );
		$eof = feof( $file );
		fclose( $file );

		return array( 'rows' => $rows, 'new_byte_offset' => $new_offset, 'eof' => $eof, 'rows_read_count' => $count );
	}

	/**
	 * @param resource $file
	 * @return array<int,string|null>|false
	 */
	private function read_csv_row( $file ): array|false {
		$before = (int) ftell( $file );
		$row = fgetcsv( $file, self::MAX_CSV_ROW_LENGTH, ';', '"', '\\' );
		$after = (int) ftell( $file );
		if ( false !== $row && $after - $before >= self::MAX_CSV_ROW_LENGTH && ! feof( $file ) ) {
			throw new \RuntimeException( 'DPD Geography CSV row exceeds the safe length limit. Check file encoding and row length.' );
		}

		return $row;
	}

	/**
	 * @param array<int,string> $row
	 * @param array<int,string> $columns
	 * @return array<string,string>
	 */
	private function map_row( array $row, array $columns ): array {
		if ( array() === $columns ) {
			$columns = self::POSITIONAL_COLUMNS;
		}
		$mapped = array();
		foreach ( $columns as $index => $key ) {
			$mapped[ $key ] = trim( (string) ( $row[ $index ] ?? '' ) );
		}
		foreach ( self::POSITIONAL_COLUMNS as $key ) {
			$mapped[ $key ] = (string) ( $mapped[ $key ] ?? '' );
		}

		return $mapped;
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

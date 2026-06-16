<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

use Generator;
defined( 'ABSPATH' ) || exit;

final class DpdGeographyCsvParser {
	private const MAX_CSV_ROW_LENGTH = 262144;
	private const READ_CHUNK_SIZE = 8192;

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
		$line = $this->read_line( $file );
		if ( false === $line ) {
			return false;
		}

		return str_getcsv( $line, ';', '"', '\\' );
	}

	/**
	 * Reads one physical CSV line with explicit bounded chunking.
	 *
	 * @param resource $file
	 */
	private function read_line( $file ): string|false {
		$buffer = '';
		while ( ! feof( $file ) ) {
			$chunk = fread( $file, self::READ_CHUNK_SIZE );
			if ( false === $chunk ) {
				throw new \RuntimeException( 'Unable to read DPD Geography CSV row.' );
			}
			if ( '' === $chunk ) {
				continue;
			}
			$buffer .= $chunk;
			if ( strlen( $buffer ) > self::MAX_CSV_ROW_LENGTH ) {
				throw new \RuntimeException( 'DPD Geography CSV row exceeds the safe length limit. Check file encoding and row length.' );
			}

			$ending = $this->first_line_ending( $buffer );
			if ( null === $ending ) {
				continue;
			}

			$position = $ending['position'];
			$length = $ending['length'];
			if ( "\r" === $buffer[ $position ] && 1 === $length && $position === strlen( $buffer ) - 1 && ! feof( $file ) ) {
				$next = fread( $file, 1 );
				if ( "\n" === $next ) {
					$length = 2;
				} elseif ( is_string( $next ) && '' !== $next ) {
					fseek( $file, -strlen( $next ), SEEK_CUR );
				}
			}

			$line = substr( $buffer, 0, $position );
			$tail_length = strlen( $buffer ) - $position - ( 2 === $length && isset( $buffer[ $position + 1 ] ) ? 2 : 1 );
			if ( $tail_length > 0 ) {
				fseek( $file, -$tail_length, SEEK_CUR );
			}

			return $line;
		}

		return '' === $buffer ? false : $buffer;
	}

	/**
	 * @return array{position:int,length:int}|null
	 */
	private function first_line_ending( string $buffer ): ?array {
		$lf = strpos( $buffer, "\n" );
		$cr = strpos( $buffer, "\r" );
		if ( false === $lf && false === $cr ) {
			return null;
		}
		if ( false === $cr || ( false !== $lf && $lf < $cr ) ) {
			return array( 'position' => $lf, 'length' => 1 );
		}

		return array(
			'position' => $cr,
			'length' => isset( $buffer[ $cr + 1 ] ) && "\n" === $buffer[ $cr + 1 ] ? 2 : 1,
		);
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

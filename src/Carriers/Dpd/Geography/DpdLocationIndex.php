<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class DpdLocationIndex {
	private const AMBIGUOUS = -1;

	/** @var array<string,int> */
	private array $fias = array();
	/** @var array<string,int> */
	private array $kladr = array();
	/** @var array<string,int> */
	private array $name = array();

	public function __construct(
		private LocationRepository $locations
	) {
	}

	public function build( int $chunk_size = 5000 ): void {
		$this->fias = array();
		$this->kladr = array();
		$this->name = array();
		$offset = 0;
		do {
			$rows = $this->locations->dpd_location_index_rows( $chunk_size, $offset );
			foreach ( $rows as $row ) {
				$this->add_row( $row );
			}
			$count = count( $rows );
			$offset += $count;
		} while ( $count === $chunk_size );
	}

	/**
	 * @return array{fias:array<string,int>,kladr:array<string,int>,name:array<string,int>}
	 */
	public function export(): array {
		return array( 'fias' => $this->fias, 'kladr' => $this->kladr, 'name' => $this->name );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array{fias:array<string,int>,kladr:array<string,int>,name:array<string,int>}
	 */
	public static function validate_export( array $data ): array {
		$validated = array();
		foreach ( array( 'fias', 'kladr', 'name' ) as $bucket ) {
			if ( ! array_key_exists( $bucket, $data ) || ! is_array( $data[ $bucket ] ) ) {
				throw new \InvalidArgumentException( 'DPD location index payload is invalid: missing map ' . $bucket . '.' );
			}
			$validated[ $bucket ] = array();
			foreach ( $data[ $bucket ] as $key => $value ) {
				$key = is_int( $key ) ? (string) $key : $key;
				if ( ! is_string( $key ) || '' === trim( $key ) ) {
					throw new \InvalidArgumentException( 'DPD location index payload is invalid: empty map key.' );
				}
				if ( is_array( $value ) || is_object( $value ) || ! is_numeric( $value ) ) {
					throw new \InvalidArgumentException( 'DPD location index payload is invalid: non-numeric location id.' );
				}
				$id = (int) $value;
				if ( self::AMBIGUOUS !== $id && $id <= 0 ) {
					throw new \InvalidArgumentException( 'DPD location index payload is invalid: non-positive location id.' );
				}
				$validated[ $bucket ][ trim( $key ) ] = $id;
			}
		}

		return $validated;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function load( array $data ): void {
		$validated = self::validate_export( $data );
		$this->fias = $validated['fias'];
		$this->kladr = $validated['kladr'];
		$this->name = $validated['name'];
	}

	public function match_fias( string $fias ): int {
		return $this->lookup( $this->fias, $this->normalize_guid( $fias ) );
	}

	public function match_kladr( string $kladr ): int {
		foreach ( $this->kladr_variants( $kladr ) as $variant ) {
			$location_id = $this->lookup( $this->kladr, $variant );
			if ( 0 !== $location_id ) {
				return $location_id;
			}
		}

		return 0;
	}

	public function match_name( string $region, string $district, string $name, string $type ): int {
		return $this->lookup( $this->name, $this->name_key( $region, $district, $name, $type ) );
	}

	public function is_ambiguous( int $location_id ): bool {
		return self::AMBIGUOUS === $location_id;
	}

	/**
	 * @return array<string,int>
	 */
	public function stats(): array {
		return array(
			'fias_keys' => count( $this->fias ),
			'kladr_keys' => count( $this->kladr ),
			'name_keys' => count( $this->name ),
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function add_row( array $row ): void {
		$location_id = (int) ( $row['id'] ?? 0 );
		if ( $location_id <= 0 ) {
			return;
		}
		foreach ( array( 'fias_id', 'city_fias_id' ) as $column ) {
			$key = $this->normalize_guid( (string) ( $row[ $column ] ?? '' ) );
			if ( '' !== $key ) {
				$this->add_unique( $this->fias, $key, $location_id );
			}
		}
		foreach ( array( 'kladr_id', 'city_kladr_id' ) as $column ) {
			foreach ( $this->kladr_variants( (string) ( $row[ $column ] ?? '' ) ) as $key ) {
				$this->add_unique( $this->kladr, $key, $location_id );
			}
		}
		$name = (string) ( $row['place_name'] ?? $row['settlement_name'] ?? $row['city_name'] ?? '' );
		$type = (string) ( $row['place_type'] ?? $row['settlement_type'] ?? $row['city_type'] ?? '' );
		$key = $this->name_key( (string) ( $row['region_name'] ?? '' ), (string) ( $row['district_name'] ?? '' ), $name, $type );
		if ( '' !== $key ) {
			$this->add_unique( $this->name, $key, $location_id );
		}
	}

	/**
	 * @param array<string,int> $index
	 */
	private function add_unique( array &$index, string $key, int $location_id ): void {
		if ( '' === $key ) {
			return;
		}
		if ( ! isset( $index[ $key ] ) ) {
			$index[ $key ] = $location_id;
			return;
		}
		if ( $index[ $key ] !== $location_id ) {
			$index[ $key ] = self::AMBIGUOUS;
		}
	}

	/**
	 * @param array<string,int> $index
	 */
	private function lookup( array $index, string $key ): int {
		if ( '' === $key || ! array_key_exists( $key, $index ) ) {
			return 0;
		}

		return (int) $index[ $key ];
	}

	/**
	 * @return array<int,string>
	 */
	private function kladr_variants( string $value ): array {
		$digits = preg_replace( '/\D+/', '', strtoupper( preg_replace( '/^RU/i', '', trim( $value ) ) ) ) ?? '';
		if ( '' === $digits ) {
			return array();
		}
		$variants = array( $digits );
		$rtrim = rtrim( $digits, '0' );
		if ( '' !== $rtrim && $rtrim !== $digits ) {
			$variants[] = $rtrim;
		}
		if ( strlen( $digits ) < 13 ) {
			$variants[] = str_pad( $digits, 13, '0' );
		}

		return array_values( array_unique( $variants ) );
	}

	private function name_key( string $region, string $district, string $name, string $type ): string {
		$name = $this->normalize_text( $name );
		if ( '' === $name ) {
			return '';
		}

		return implode( '|', array( $this->normalize_text( $region ), $this->normalize_text( $district ), $name, $this->normalize_type( $type ) ) );
	}

	private function normalize_guid( string $value ): string {
		$normalized = strtolower( preg_replace( '/[^a-f0-9]/i', '', $value ) ?? '' );
		return 32 === strlen( $normalized ) ? $normalized : '';
	}

	private function normalize_text( string $value ): string {
		$value = str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), trim( $value ) );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		return preg_replace( '/\s+/u', ' ', $value ) ?? $value;
	}

	private function normalize_type( string $value ): string {
		$value = $this->normalize_text( $value );
		return match ( $value ) {
			'город' => 'г',
			'поселок', 'посёлок' => 'п',
			'село' => 'с',
			'деревня' => 'д',
			default => $value,
		};
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyMatchContext {
	private const AMBIGUOUS = -1;

	/** @var array<string,array<int,array<string,mixed>>> */
	private array $own_fias = array();
	/** @var array<string,array<int,array<string,mixed>>> */
	private array $city_fias = array();
	/** @var array<string,int> */
	private array $kladr = array();
	/** @var array<string,int> */
	private array $name = array();
	private int $candidate_count = 0;

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	public function add_own_fias_rows( array $rows ): void {
		foreach ( $rows as $row ) {
			$this->add_fias_row( $this->own_fias, (string) ( $row['fias_id'] ?? '' ), $row );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	public function add_city_fias_rows( array $rows ): void {
		foreach ( $rows as $row ) {
			$this->add_fias_row( $this->city_fias, (string) ( $row['city_fias_id'] ?? '' ), $row );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	public function add_kladr_rows( array $rows ): void {
		foreach ( $rows as $row ) {
			$location_id = (int) ( $row['id'] ?? 0 );
			if ( $location_id <= 0 || 'RU' !== strtoupper( (string) ( $row['country_code'] ?? 'RU' ) ) || 1 !== (int) ( $row['active'] ?? 1 ) ) {
				continue;
			}
			foreach ( array( 'kladr_id', 'city_kladr_id' ) as $column ) {
				foreach ( DpdGeographyLookupKeys::kladr_variants( (string) ( $row[ $column ] ?? '' ) ) as $key ) {
					$this->add_unique( $this->kladr, $key, $location_id );
				}
			}
			++$this->candidate_count;
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	public function add_name_rows( array $rows ): void {
		foreach ( $rows as $row ) {
			$location_id = (int) ( $row['id'] ?? 0 );
			if ( $location_id <= 0 || 'RU' !== strtoupper( (string) ( $row['country_code'] ?? 'RU' ) ) || 1 !== (int) ( $row['active'] ?? 1 ) ) {
				continue;
			}
			$key = DpdGeographyLookupKeys::name_key(
				(string) ( $row['region_name'] ?? '' ),
				(string) ( $row['district_name'] ?? '' ),
				$this->first_non_empty( $row, array( 'place_name', 'settlement_name', 'city_name' ) ),
				$this->first_non_empty( $row, array( 'place_type', 'settlement_type', 'city_type' ) )
			);
			$this->add_unique( $this->name, $key, $location_id );
			++$this->candidate_count;
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function own_fias_candidates( string $fias ): array {
		return $this->lookup_fias_rows( $this->own_fias, $fias );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function city_fias_candidates( string $fias ): array {
		return $this->lookup_fias_rows( $this->city_fias, $fias );
	}

	public function match_kladr( string $kladr ): int {
		foreach ( DpdGeographyLookupKeys::kladr_variants( $kladr ) as $variant ) {
			if ( array_key_exists( $variant, $this->kladr ) ) {
				return (int) $this->kladr[ $variant ];
			}
		}

		return 0;
	}

	public function match_name( string $region, string $district, string $name, string $type ): int {
		$key = DpdGeographyLookupKeys::name_key( $region, $district, $name, $type );
		if ( '' === $key || ! array_key_exists( $key, $this->name ) ) {
			return 0;
		}

		return (int) $this->name[ $key ];
	}

	public function is_ambiguous( int $location_id ): bool {
		return self::AMBIGUOUS === $location_id;
	}

	public function candidate_count(): int {
		return $this->candidate_count;
	}

	/**
	 * @param array<string,array<int,array<string,mixed>>> $index
	 * @param array<string,mixed> $row
	 */
	private function add_fias_row( array &$index, string $fias, array $row ): void {
		$key = DpdGeographyLookupKeys::normalize_guid( $fias );
		$location_id = (int) ( $row['id'] ?? 0 );
		if ( '' === $key || $location_id <= 0 || 'RU' !== strtoupper( (string) ( $row['country_code'] ?? 'RU' ) ) || 1 !== (int) ( $row['active'] ?? 1 ) ) {
			return;
		}
		if ( ! isset( $index[ $key ] ) ) {
			$index[ $key ] = array();
		}
		$index[ $key ][ $location_id ] = $row;
		ksort( $index[ $key ], SORT_NUMERIC );
		++$this->candidate_count;
	}

	/**
	 * @param array<string,array<int,array<string,mixed>>> $index
	 * @return array<int,array<string,mixed>>
	 */
	private function lookup_fias_rows( array $index, string $fias ): array {
		$key = DpdGeographyLookupKeys::normalize_guid( $fias );
		if ( '' === $key || ! isset( $index[ $key ] ) ) {
			return array();
		}

		return array_values( $index[ $key ] );
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
	 * @param array<string,mixed> $row
	 * @param array<int,string> $keys
	 */
	private function first_non_empty( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = trim( (string) ( $row[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}
}

<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Services;

use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class LocationCountryIndexService {
	public const OPTION = 'wdc_location_country_codes';

	public function __construct( private LocationRepository $repository ) {
	}

	/**
	 * @return array<int,string>
	 */
	public function rebuild(): array {
		$counts = $this->normalize_counts( $this->repository->country_counts() );
		$countries = $this->normalize_codes( array_keys( $counts ) );
		$this->update_option(
			array(
				'countries'  => $countries,
				'counts'     => $counts,
				'stale'      => false,
				'rebuilt_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : '',
			)
		);

		return $countries;
	}

	/**
	 * @return array<int,string>
	 */
	public function countries(): array {
		$index = $this->option();
		if ( ! array_key_exists( 'countries', $index ) || ! empty( $index['stale'] ) ) {
			return $this->rebuild();
		}

		return $this->normalize_codes( is_array( $index['countries'] ) ? $index['countries'] : array() );
	}

	public function has_country( string $country_code ): bool {
		$country_code = $this->normalize_code( $country_code );
		return '' !== $country_code && in_array( $country_code, $this->countries(), true );
	}

	/**
	 * @return array<int,array{country_code:string,country_name:string,count:int}>
	 */
	public function countries_with_counts(): array {
		$index = $this->option();
		if ( ! array_key_exists( 'countries', $index ) || ! array_key_exists( 'counts', $index ) || ! empty( $index['stale'] ) ) {
			$this->rebuild();
			$index = $this->option();
		}

		$countries = $this->normalize_codes( is_array( $index['countries'] ?? null ) ? $index['countries'] : array() );
		$counts = $this->normalize_counts( is_array( $index['counts'] ?? null ) ? $index['counts'] : array() );
		$result = array();
		foreach ( $countries as $country_code ) {
			$result[] = array(
				'country_code' => $country_code,
				'country_name' => $this->country_name( $country_code ),
				'count'        => $counts[ $country_code ] ?? 0,
			);
		}

		return $result;
	}

	public function mark_stale(): void {
		self::mark_option_stale();
	}

	public static function mark_option_stale(): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		$index = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$index = is_array( $index ) ? $index : array();
		if ( ! isset( $index['countries'] ) && array() !== $index ) {
			$index = array( 'countries' => self::normalize_codes_static( $index ) );
		}
		$index['stale'] = true;
		update_option( self::OPTION, $index, false );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function option(): array {
		$value = function_exists( 'get_option' ) ? get_option( self::OPTION, null ) : null;
		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<string,mixed> $value
	 */
	private function update_option( array $value ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, $value, false );
		}
	}

	/**
	 * @param array<int|string,mixed> $codes
	 * @return array<int,string>
	 */
	private function normalize_codes( array $codes ): array {
		return self::normalize_codes_static( $codes );
	}

	/**
	 * @param array<int|string,mixed> $codes
	 * @return array<int,string>
	 */
	private static function normalize_codes_static( array $codes ): array {
		$normalized = array();
		foreach ( $codes as $code ) {
			$code = self::normalize_code_static( (string) $code );
			if ( '' !== $code ) {
				$normalized[] = $code;
			}
		}
		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized );
		return $normalized;
	}

	private function normalize_code( string $country_code ): string {
		return self::normalize_code_static( $country_code );
	}

	/**
	 * @param array<int|string,mixed> $counts
	 * @return array<string,int>
	 */
	private function normalize_counts( array $counts ): array {
		$normalized = array();
		foreach ( $counts as $country_code => $count ) {
			$country_code = $this->normalize_code( (string) $country_code );
			if ( '' !== $country_code ) {
				$normalized[ $country_code ] = max( 0, (int) $count );
			}
		}
		ksort( $normalized );
		return $normalized;
	}

	private function country_name( string $country_code ): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}

		$woocommerce = WC();
		if ( ! is_object( $woocommerce ) || ! isset( $woocommerce->countries ) || ! is_object( $woocommerce->countries ) ) {
			return '';
		}

		$countries = $woocommerce->countries->countries ?? array();
		if ( ! is_array( $countries ) ) {
			return '';
		}

		return isset( $countries[ $country_code ] ) ? (string) $countries[ $country_code ] : '';
	}

	private static function normalize_code_static( string $country_code ): string {
		$country_code = strtoupper( trim( $country_code ) );
		return preg_match( '/^[A-Z]{2}$/', $country_code ) ? $country_code : '';
	}
}

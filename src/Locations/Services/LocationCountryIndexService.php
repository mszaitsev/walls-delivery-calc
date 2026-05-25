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
		$countries = $this->normalize_codes( $this->repository->distinct_country_codes() );
		$this->update_option(
			array(
				'countries'  => $countries,
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
		$countries = $this->normalize_codes( is_array( $index['countries'] ?? null ) ? $index['countries'] : ( is_array( $index ) ? $index : array() ) );
		if ( array() === $countries || ! empty( $index['stale'] ) ) {
			return $this->rebuild();
		}

		return $countries;
	}

	public function has_country( string $country_code ): bool {
		$country_code = $this->normalize_code( $country_code );
		return '' !== $country_code && in_array( $country_code, $this->countries(), true );
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
		$value = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
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

	private static function normalize_code_static( string $country_code ): string {
		$country_code = strtoupper( trim( $country_code ) );
		return preg_match( '/^[A-Z]{2}$/', $country_code ) ? $country_code : '';
	}
}

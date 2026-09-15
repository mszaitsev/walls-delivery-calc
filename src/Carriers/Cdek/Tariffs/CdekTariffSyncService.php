<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Cdek\Tariffs;

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class CdekTariffSyncService {
	public function __construct(
		private CdekApiClient $client,
		private CdekTariffRepository $repository,
		private ?Logger $logger = null
	) {
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function fetch_from_api(): array {
		$result = $this->client->allTariffs();

		return $this->normalize_api_response( $result );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{new:array<int,array<string,mixed>>,changed:array<int,array<string,mixed>>,missing:array<int,array<string,mixed>>}
	 */
	public function diff( array $rows ): array {
		return $this->repository->diff( $rows );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{added:int,updated:int,missing:int,warnings:int}
	 */
	public function sync_rows( array $rows ): array {
		$diff = $this->repository->diff( $rows );
		foreach ( $rows as $row ) {
			$this->repository->upsert_from_sync( $row );
		}

		return array(
			'added' => count( $diff['new'] ),
			'updated' => count( $diff['changed'] ),
			'missing' => count( $diff['missing'] ),
			'warnings' => count( array_filter( $rows, static fn( array $row ): bool => ! empty( $row['warning'] ) ) ),
		);
	}

	/**
	 * @return array{rows:array<int,array<string,mixed>>,diff:array<string,array<int,array<string,mixed>>>}
	 */
	public function preview(): array {
		$rows = $this->fetch_from_api();

		return array( 'rows' => $rows, 'diff' => $this->diff( $rows ) );
	}

	/**
	 * @return array{added:int,updated:int,missing:int,warnings:int}
	 */
	public function sync_from_api(): array {
		return $this->sync_rows( $this->fetch_from_api() );
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<int,array<string,mixed>>
	 */
	public function normalize_api_response( array $result ): array {
		$body = is_array( $result['body'] ?? null ) ? $result['body'] : $result;
		$groups = is_array( $body['tariff_codes'] ?? null ) ? $body['tariff_codes'] : array();
		$rows = array();
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$name = $this->normalize_cdek_string( (string) ( $group['tariff_name'] ?? '' ) );
			$modes = is_array( $group['delivery_modes'] ?? null ) ? $group['delivery_modes'] : array();
			foreach ( $modes as $mode_row ) {
				if ( ! is_array( $mode_row ) ) {
					continue;
				}
				$code = trim( (string) ( $mode_row['tariff_code'] ?? '' ) );
				if ( '' === $code ) {
					continue;
				}
				$mode_name = $this->normalize_cdek_string( (string) ( $mode_row['delivery_mode_name'] ?? '' ) );
				$delivery_mode = self::delivery_mode_value( $mode_row['delivery_mode'] ?? null, $mode_name, $name );
				$type = self::delivery_type_from_mode( $delivery_mode );
				$warning = DeliveryType::PICKUP === $type['delivery_type'] && ! empty( $type['unknown'] );
				if ( $warning && $this->logger instanceof Logger ) {
					$this->logger->warning(
						'CDEK tariff delivery mode is unknown; defaulting to pickup.',
						array(
							'endpoint' => '/v2/calculator/alltariffs',
							'tariff_code' => $code,
							'delivery_mode' => $mode_row['delivery_mode'] ?? null,
						)
					);
				}
				$display_name = $this->tariff_display_name( $name, $mode_name );
				$rows[ $code ] = array_merge(
					array(
					'tariff_code' => $code,
					'tariff_name_from_cdek' => $display_name,
					'delivery_type' => $type['delivery_type'],
					'delivery_mode' => $delivery_mode,
					'delivery_mode_name' => $mode_name,
					'warning' => $warning,
					),
					$this->limits_from_api_rows( $group, $mode_row )
				);
			}
		}

		$result_rows = array_values( $rows );
		usort( $result_rows, static fn( array $a, array $b ): int => strnatcmp( (string) ( $a['tariff_code'] ?? '' ), (string) ( $b['tariff_code'] ?? '' ) ) );

		return $result_rows;
	}

	/**
	 * @return array{delivery_type:string,unknown:bool}
	 */
	public static function delivery_type_from_mode( mixed $raw_mode ): array {
		$text = strtolower( str_replace( '_', '-', trim( self::normalize_cdek_string_static( (string) $raw_mode ) ) ) );
		if ( '' !== $text && ! is_numeric( $raw_mode ) ) {
			if ( str_ends_with( $text, '-warehouse' ) || str_ends_with( $text, '-pickup' ) ) {
				return array( 'delivery_type' => DeliveryType::PICKUP, 'unknown' => false );
			}
			if ( str_ends_with( $text, '-door' ) || str_ends_with( $text, '-courier' ) ) {
				return array( 'delivery_type' => DeliveryType::COURIER, 'unknown' => false );
			}
		}
		$mode = (int) $raw_mode;

		return match ( $mode ) {
			2, 4 => array( 'delivery_type' => DeliveryType::PICKUP, 'unknown' => false ),
			1, 3 => array( 'delivery_type' => DeliveryType::COURIER, 'unknown' => false ),
			default => array( 'delivery_type' => DeliveryType::PICKUP, 'unknown' => true ),
		};
	}

	public static function delivery_mode_value( mixed $raw_mode, string $mode_name = '', string $tariff_name = '' ): int {
		$mode = (int) $raw_mode;
		if ( in_array( $mode, array( 1, 2, 3, 4 ), true ) ) {
			return $mode;
		}
		$text = strtolower( str_replace( '_', '-', trim( self::normalize_cdek_string_static( $mode_name . ' ' . $tariff_name . ' ' . ( is_scalar( $raw_mode ) ? (string) $raw_mode : '' ) ) ) ) );
		if ( str_contains( $text, 'дверь-дверь' ) || str_contains( $text, 'door-door' ) ) {
			return 1;
		}
		if (
			str_contains( $text, 'дверь-склад' )
			|| str_contains( $text, 'дверь-пвз' )
			|| str_contains( $text, 'дверь-постамат' )
			|| str_contains( $text, 'door-warehouse' )
			|| str_contains( $text, 'door-pickup' )
			|| str_contains( $text, 'door-locker' )
			|| str_contains( $text, 'door-postamat' )
		) {
			return 2;
		}
		if (
			str_contains( $text, 'склад-дверь' )
			|| str_contains( $text, 'пвз-дверь' )
			|| str_contains( $text, 'постамат-дверь' )
			|| str_contains( $text, 'warehouse-door' )
			|| str_contains( $text, 'pickup-door' )
			|| str_contains( $text, 'locker-door' )
			|| str_contains( $text, 'postamat-door' )
		) {
			return 3;
		}
		if (
			str_contains( $text, 'склад-склад' )
			|| str_contains( $text, 'склад-пвз' )
			|| str_contains( $text, 'склад-постамат' )
			|| str_contains( $text, 'пвз-пвз' )
			|| str_contains( $text, 'пвз-постамат' )
			|| str_contains( $text, 'постамат-пвз' )
			|| str_contains( $text, 'постамат-постамат' )
			|| str_contains( $text, 'warehouse-warehouse' )
			|| str_contains( $text, 'warehouse-pickup' )
			|| str_contains( $text, 'warehouse-locker' )
			|| str_contains( $text, 'pickup-pickup' )
			|| str_contains( $text, 'pickup-locker' )
			|| str_contains( $text, 'locker-pickup' )
			|| str_contains( $text, 'locker-locker' )
		) {
			return 4;
		}

		return 0;
	}

	private function tariff_display_name( string $name, string $mode_name ): string {
		$name = trim( $name );
		$mode_name = trim( $mode_name );
		if ( '' === $mode_name ) {
			return $name;
		}
		if ( '' === $name ) {
			return $mode_name;
		}
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $mode_name ) : strtolower( $mode_name );
		$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
		if ( str_contains( $haystack, $needle ) ) {
			return $name;
		}

		return $name . ' ' . $mode_name;
	}

	/**
	 * @param array<string,mixed> $group
	 * @param array<string,mixed> $mode_row
	 * @return array<string,mixed>
	 */
	private function limits_from_api_rows( array $group, array $mode_row ): array {
		$limits = array();
		foreach ( array( 'weight_min', 'weight_max', 'weight_calc_max', 'length_min', 'length_max', 'width_min', 'width_max', 'height_min', 'height_max' ) as $key ) {
			$limits[ $key ] = array_key_exists( $key, $mode_row ) ? $this->nullable_api_number( $mode_row[ $key ] ) : $this->nullable_api_number( $group[ $key ] ?? null );
		}

		return $limits;
	}

	private function nullable_api_number( mixed $value ): ?float {
		if ( null === $value ) {
			return null;
		}
		if ( is_string( $value ) ) {
			$value = trim( $value );
			if ( '' === $value ) {
				return null;
			}
			$value = str_replace( ',', '.', $value );
		}
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		return (float) $value;
	}

	public function normalize_cdek_string( string $value ): string {
		return self::normalize_cdek_string_static( $value );
	}

	public static function normalize_cdek_string_static( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || ! self::looks_like_mojibake( $value ) ) {
			return $value;
		}
		foreach ( self::mojibake_fix_candidates( $value ) as $fixed ) {
			$fixed = trim( $fixed );
			if ( '' !== $fixed && ! self::looks_like_mojibake( $fixed ) && self::contains_cyrillic( $fixed ) ) {
				return $fixed;
			}
		}

		return $value;
	}

	private static function looks_like_mojibake( string $value ): bool {
		return str_contains( $value, 'Ð' ) || str_contains( $value, 'Ñ' ) || str_contains( $value, 'Â' );
	}

	/**
	 * @return array<int,string>
	 */
	private static function mojibake_fix_candidates( string $value ): array {
		$candidates = array();
		$bytes = self::latin1_bytes_from_utf8_chars( $value );
		if ( null !== $bytes && self::valid_utf8( $bytes ) ) {
			$candidates[] = $bytes;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			foreach ( array( 'Windows-1252', 'ISO-8859-1' ) as $encoding ) {
				$converted = @mb_convert_encoding( $value, $encoding, 'UTF-8' );
				if ( is_string( $converted ) && self::valid_utf8( $converted ) ) {
					$candidates[] = $converted;
				}
			}
		}
		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( 'UTF-8', 'Windows-1252//IGNORE', $value );
			if ( is_string( $converted ) && self::valid_utf8( $converted ) ) {
				$candidates[] = $converted;
			}
		}

		return array_values( array_unique( $candidates ) );
	}

	private static function latin1_bytes_from_utf8_chars( string $value ): ?string {
		if ( ! function_exists( 'preg_split' ) || ! function_exists( 'mb_ord' ) ) {
			return null;
		}
		$chars = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return null;
		}
		$bytes = '';
		foreach ( $chars as $char ) {
			$codepoint = mb_ord( $char, 'UTF-8' );
			if ( false === $codepoint || $codepoint > 255 ) {
				return null;
			}
			$bytes .= chr( $codepoint );
		}

		return $bytes;
	}

	private static function valid_utf8( string $value ): bool {
		return function_exists( 'mb_check_encoding' ) ? mb_check_encoding( $value, 'UTF-8' ) : (bool) preg_match( '//u', $value );
	}

	private static function contains_cyrillic( string $value ): bool {
		return 1 === preg_match( '/\p{Cyrillic}/u', $value );
	}
}

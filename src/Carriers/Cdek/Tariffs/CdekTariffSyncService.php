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
			$name = trim( (string) ( $group['tariff_name'] ?? '' ) );
			$modes = is_array( $group['delivery_modes'] ?? null ) ? $group['delivery_modes'] : array();
			foreach ( $modes as $mode_row ) {
				if ( ! is_array( $mode_row ) ) {
					continue;
				}
				$code = trim( (string) ( $mode_row['tariff_code'] ?? '' ) );
				if ( '' === $code ) {
					continue;
				}
				$type = self::delivery_type_from_mode( $mode_row['delivery_mode'] ?? null );
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
				$mode_name = trim( (string) ( $mode_row['delivery_mode_name'] ?? '' ) );
				$display_name = $this->tariff_display_name( $name, $mode_name );
				$rows[ $code ] = array(
					'tariff_code' => $code,
					'tariff_name_from_cdek' => $display_name,
					'delivery_type' => $type['delivery_type'],
					'delivery_mode' => $mode_row['delivery_mode'] ?? null,
					'delivery_mode_name' => $mode_name,
					'warning' => $warning,
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
		$text = strtolower( str_replace( '_', '-', trim( (string) $raw_mode ) ) );
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
}

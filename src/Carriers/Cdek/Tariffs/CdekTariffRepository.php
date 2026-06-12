<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Cdek\Tariffs;

use WallsShop\WDC\Domain\Quote\DeliveryType;

defined( 'ABSPATH' ) || exit;

final class CdekTariffRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	public function create_schema_if_needed( string $table = '' ): void {
		$this->wpdb->query( $this->schema_sql( $table ) );
	}

	public function schema_sql( string $table = '' ): string {
		$table = '' !== $table ? $this->sanitize_table_name( $table ) : $this->main_table();
		$charset = method_exists( $this->wpdb, 'get_charset_collate' ) ? $this->wpdb->get_charset_collate() : 'DEFAULT CHARSET=utf8mb4';

		return "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tariff_code VARCHAR(32) NOT NULL,
			tariff_name_from_cdek VARCHAR(255) NOT NULL DEFAULT '',
			custom_title VARCHAR(255) NOT NULL DEFAULT '',
			delivery_type VARCHAR(20) NOT NULL DEFAULT 'pickup',
			admin_comment TEXT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			last_sync_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_tariff_code (tariff_code),
			KEY idx_delivery_type (delivery_type),
			KEY idx_is_active (is_active)
		) {$charset}";
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		if ( $this->uses_memory_table() ) {
			$rows = array_values( $this->wpdb->cdek_tariffs );
			usort( $rows, static fn( array $a, array $b ): int => strnatcmp( (string) ( $a['tariff_code'] ?? '' ), (string) ( $b['tariff_code'] ?? '' ) ) );

			return array_map( fn( array $row ): array => $this->normalize_row( $row ), $rows );
		}

		$rows = $this->wpdb->get_results( 'SELECT * FROM ' . $this->main_table() . ' ORDER BY CAST(tariff_code AS UNSIGNED) ASC, tariff_code ASC', ARRAY_A );

		return array_values( array_map( fn( array $row ): array => $this->normalize_row( $row ), is_array( $rows ) ? $rows : array() ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find_by_code( string $code ): ?array {
		$code = $this->normalize_code( $code );
		if ( '' === $code ) {
			return null;
		}
		if ( $this->uses_memory_table() ) {
			foreach ( $this->wpdb->cdek_tariffs as $row ) {
				if ( $code === $this->normalize_code( (string) ( $row['tariff_code'] ?? '' ) ) ) {
					return $this->normalize_row( $row );
				}
			}

			return null;
		}

		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->main_table() . ' WHERE tariff_code = %s LIMIT 1', $code ), ARRAY_A );

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function active_by_code( string $code ): ?array {
		$row = $this->find_by_code( $code );
		if ( ! is_array( $row ) || empty( $row['is_active'] ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public function upsert_from_sync( array $row ): array {
		$now = $this->now();
		$code = $this->normalize_code( (string) ( $row['tariff_code'] ?? '' ) );
		if ( '' === $code ) {
			return array();
		}
		$existing = $this->find_by_code( $code );
		$name = $this->clean_text( (string) ( $row['tariff_name_from_cdek'] ?? $row['tariff_name'] ?? '' ), 255 );
		$type = $this->normalize_delivery_type( (string) ( $row['delivery_type'] ?? DeliveryType::PICKUP ) );
		if ( is_array( $existing ) ) {
			$updated = array_merge(
				$existing,
				array(
					'tariff_name_from_cdek' => $name,
					'delivery_type' => $type,
					'last_sync_at' => $now,
					'updated_at' => $now,
				)
			);
			$this->persist( $updated, true );

			return $this->normalize_row( $updated );
		}

		$created = $this->normalize_row(
			array(
				'tariff_code' => $code,
				'tariff_name_from_cdek' => $name,
				'custom_title' => '',
				'delivery_type' => $type,
				'admin_comment' => '',
				'is_active' => 1,
				'last_sync_at' => $now,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$this->persist( $created, false );

		return $created;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	public function save_admin_rows( array $rows ): void {
		foreach ( $rows as $row ) {
			$code = $this->normalize_code( (string) ( $row['tariff_code'] ?? '' ) );
			if ( '' === $code ) {
				continue;
			}
			$existing = $this->find_by_code( $code );
			if ( ! is_array( $existing ) ) {
				continue;
			}
			$updated = array_merge(
				$existing,
				array(
					'custom_title' => $this->clean_text( (string) ( $row['custom_title'] ?? '' ), 255 ),
					'delivery_type' => $this->normalize_delivery_type( (string) ( $row['delivery_type'] ?? $existing['delivery_type'] ?? DeliveryType::PICKUP ) ),
					'admin_comment' => $this->clean_textarea( (string) ( $row['admin_comment'] ?? '' ) ),
					'is_active' => ! empty( $row['is_active'] ) ? 1 : 0,
					'updated_at' => $this->now(),
				)
			);
			$this->persist( $updated, true );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $api_rows
	 * @return array{new:array<int,array<string,mixed>>,changed:array<int,array<string,mixed>>,missing:array<int,array<string,mixed>>}
	 */
	public function diff( array $api_rows ): array {
		$existing_by_code = array();
		foreach ( $this->all() as $row ) {
			$existing_by_code[ (string) $row['tariff_code'] ] = $row;
		}
		$api_by_code = array();
		$new = array();
		$changed = array();
		foreach ( $api_rows as $row ) {
			$code = $this->normalize_code( (string) ( $row['tariff_code'] ?? '' ) );
			if ( '' === $code ) {
				continue;
			}
			$normalized = array(
				'tariff_code' => $code,
				'tariff_name_from_cdek' => $this->clean_text( (string) ( $row['tariff_name_from_cdek'] ?? $row['tariff_name'] ?? '' ), 255 ),
				'delivery_type' => $this->normalize_delivery_type( (string) ( $row['delivery_type'] ?? DeliveryType::PICKUP ) ),
				'delivery_mode' => $row['delivery_mode'] ?? null,
				'delivery_mode_name' => $row['delivery_mode_name'] ?? '',
				'warning' => ! empty( $row['warning'] ),
			);
			$api_by_code[ $code ] = $normalized;
			if ( ! isset( $existing_by_code[ $code ] ) ) {
				$new[] = $normalized;
				continue;
			}
			$existing = $existing_by_code[ $code ];
			if ( (string) $existing['tariff_name_from_cdek'] !== $normalized['tariff_name_from_cdek'] || (string) $existing['delivery_type'] !== $normalized['delivery_type'] ) {
				$changed[] = array_merge( $normalized, array( 'old' => $existing ) );
			}
		}
		$missing = array();
		foreach ( $existing_by_code as $code => $row ) {
			if ( ! isset( $api_by_code[ $code ] ) ) {
				$missing[] = $row;
			}
		}

		return array( 'new' => $new, 'changed' => $changed, 'missing' => $missing );
	}

	public function main_table(): string {
		return $this->wpdb->prefix . 'wdc_cdek_tariffs';
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function persist( array $row, bool $existing ): void {
		$row = $this->normalize_row( $row );
		if ( $this->uses_memory_table() ) {
			foreach ( $this->wpdb->cdek_tariffs as $index => $stored ) {
				if ( (string) ( $stored['tariff_code'] ?? '' ) === (string) $row['tariff_code'] ) {
					$this->wpdb->cdek_tariffs[ $index ] = $row;
					return;
				}
			}
			$this->wpdb->cdek_tariffs[] = $row;
			return;
		}

		$data = array(
			'tariff_code' => $row['tariff_code'],
			'tariff_name_from_cdek' => $row['tariff_name_from_cdek'],
			'custom_title' => $row['custom_title'],
			'delivery_type' => $row['delivery_type'],
			'admin_comment' => $row['admin_comment'],
			'is_active' => (int) $row['is_active'],
			'last_sync_at' => $row['last_sync_at'],
			'created_at' => $row['created_at'],
			'updated_at' => $row['updated_at'],
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' );
		if ( $existing ) {
			unset( $data['created_at'] );
			array_splice( $formats, 7, 1 );
			$this->wpdb->update( $this->main_table(), $data, array( 'tariff_code' => $row['tariff_code'] ), $formats, array( '%s' ) );
			return;
		}

		$this->wpdb->insert( $this->main_table(), $data, $formats );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function normalize_row( array $row ): array {
		$now = $this->now();

		return array(
			'id' => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'tariff_code' => $this->normalize_code( (string) ( $row['tariff_code'] ?? '' ) ),
			'tariff_name_from_cdek' => $this->clean_text( (string) ( $row['tariff_name_from_cdek'] ?? $row['tariff_name'] ?? '' ), 255 ),
			'custom_title' => $this->clean_text( (string) ( $row['custom_title'] ?? '' ), 255 ),
			'delivery_type' => $this->normalize_delivery_type( (string) ( $row['delivery_type'] ?? DeliveryType::PICKUP ) ),
			'admin_comment' => $this->clean_textarea( (string) ( $row['admin_comment'] ?? '' ) ),
			'is_active' => ! empty( $row['is_active'] ) ? 1 : 0,
			'last_sync_at' => '' !== trim( (string) ( $row['last_sync_at'] ?? '' ) ) ? (string) $row['last_sync_at'] : null,
			'created_at' => '' !== trim( (string) ( $row['created_at'] ?? '' ) ) ? (string) $row['created_at'] : $now,
			'updated_at' => '' !== trim( (string) ( $row['updated_at'] ?? '' ) ) ? (string) $row['updated_at'] : $now,
		);
	}

	private function normalize_code( string $code ): string {
		return substr( preg_replace( '/[^0-9A-Za-z_-]+/', '', trim( $code ) ) ?? '', 0, 32 );
	}

	private function normalize_delivery_type( string $type ): string {
		return DeliveryType::COURIER === $type ? DeliveryType::COURIER : DeliveryType::PICKUP;
	}

	private function clean_text( string $value, int $max_length ): string {
		$value = trim( preg_replace( '/\s+/', ' ', $value ) ?? $value );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max_length ) : substr( $value, 0, $max_length );
	}

	private function clean_textarea( string $value ): string {
		return trim( preg_replace( "/\r\n|\r/", "\n", $value ) ?? $value );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function uses_memory_table(): bool {
		return property_exists( $this->wpdb, 'cdek_tariffs' ) && is_array( $this->wpdb->cdek_tariffs );
	}

	private function sanitize_table_name( string $table ): string {
		$table = trim( $table );
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return $this->main_table();
		}

		return $table;
	}
}

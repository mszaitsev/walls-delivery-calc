<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Status;

use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class JetLogisticStatusMappingRepository {
	private \wpdb $wpdb;

	/** @var array<int,array<string,mixed>>|null */
	private ?array $mapping_cache = null;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->wpdb = $db ?? $wpdb;
	}

	public function create_schema(): void {
		\dbDelta( "CREATE TABLE {$this->table()} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			external_status varchar(255) NOT NULL DEFAULT '',
			normalized_external_status varchar(255) NOT NULL DEFAULT '',
			universal_status varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY normalized_external_status (normalized_external_status)
		) {$this->charset()};" );
	}

	public function ensure_default_mappings(): void {
		foreach ( array( 'Доставка груза на склад выдачи' => DeliveryStatus::READY_FOR_PICKUP, 'Груз выдан' => DeliveryStatus::DELIVERED ) as $external => $universal ) {
			if ( array() === $this->find_by_normalized_status( $external ) ) {
				$this->create_mapping( $external, $universal );
			}
		}
	}

	public function remove_legacy_broad_defaults(): void {
		$legacy = $this->find_by_normalized_status( 'Доставка груза на склад' );
		if ( array() !== $legacy ) {
			$this->delete_mapping( (int) $legacy['id'] );
		}
	}

	/** @return array<string,mixed> */
	public function find( string $external_status ): array {
		return $this->find_by_normalized_status( $external_status );
	}

	/** @return array<string,mixed> */
	public function find_by_id( int $id ): array {
		if ( $id <= 0 ) {
			return array();
		}
		if ( property_exists( $this->wpdb, 'jet_statuses' ) ) {
			foreach ( $this->wpdb->jet_statuses as $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === $id ) {
					return $row;
				}
			}

			return array();
		}

		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	/** @return array<string,mixed> */
	public function find_by_normalized_status( string $external_status ): array {
		$normalized = self::normalize( $external_status );
		if ( '' === $normalized ) {
			return array();
		}
		if ( property_exists( $this->wpdb, 'jet_statuses' ) ) {
			return $this->wpdb->jet_statuses[ $normalized ] ?? array();
		}

		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE normalized_external_status = %s LIMIT 1", $normalized ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	/** @return array<int,array<string,mixed>> */
	public function admin_rows(): array {
		return $this->all_mappings();
	}

	/** @return array<int,array<string,mixed>> */
	public function all_mappings(): array {
		if ( null !== $this->mapping_cache ) {
			return $this->mapping_cache;
		}
		if ( property_exists( $this->wpdb, 'jet_statuses' ) ) {
			$rows = array_values( $this->wpdb->jet_statuses );
			usort(
				$rows,
				static function ( array $a, array $b ): int {
					$length = mb_strlen( (string) ( $b['normalized_external_status'] ?? '' ), 'UTF-8' ) <=> mb_strlen( (string) ( $a['normalized_external_status'] ?? '' ), 'UTF-8' );
					if ( 0 !== $length ) {
						return $length;
					}
					$status = strcmp( (string) ( $a['external_status'] ?? '' ), (string) ( $b['external_status'] ?? '' ) );
					if ( 0 !== $status ) {
						return $status;
					}

					return (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
				}
			);
			$this->mapping_cache = $rows;

			return $rows;
		}

		$rows = $this->wpdb->get_results(
			"SELECT id, external_status, normalized_external_status, universal_status, created_at, updated_at
			FROM {$this->table()}
			ORDER BY CHAR_LENGTH(normalized_external_status) DESC, external_status ASC, id ASC",
			ARRAY_A
		);
		$this->mapping_cache = is_array( $rows ) ? $rows : array();

		return $this->mapping_cache;
	}

	public function create_mapping( string $external_status, string $universal_status ): bool {
		$prepared = $this->prepare_mapping( $external_status, $universal_status );
		if ( array() === $prepared || array() !== $this->find_by_normalized_status( $prepared['external_status'] ) ) {
			return false;
		}
		$now = current_time( 'mysql' );
		$result = $this->wpdb->insert(
			$this->table(),
			array(
				'external_status' => $prepared['external_status'],
				'normalized_external_status' => $prepared['normalized_external_status'],
				'universal_status' => $prepared['universal_status'],
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		$this->mapping_cache = null;

		return false !== $result && '' === trim( (string) ( $this->wpdb->last_error ?? '' ) );
	}

	public function update_mapping( int $id, string $external_status, string $universal_status ): bool {
		$existing = $this->find_by_id( $id );
		$prepared = $this->prepare_mapping( $external_status, $universal_status );
		if ( array() === $existing || array() === $prepared ) {
			return false;
		}
		$duplicate = $this->find_by_normalized_status( $prepared['external_status'] );
		if ( array() !== $duplicate && (int) ( $duplicate['id'] ?? 0 ) !== $id ) {
			return false;
		}
		$result = $this->wpdb->update(
			$this->table(),
			array(
				'external_status' => $prepared['external_status'],
				'normalized_external_status' => $prepared['normalized_external_status'],
				'universal_status' => $prepared['universal_status'],
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		$this->mapping_cache = null;

		return false !== $result && '' === trim( (string) ( $this->wpdb->last_error ?? '' ) );
	}

	public function delete_mapping( int $id ): bool {
		if ( array() === $this->find_by_id( $id ) ) {
			return false;
		}
		$result = $this->wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		$this->mapping_cache = null;

		return false !== $result && '' === trim( (string) ( $this->wpdb->last_error ?? '' ) );
	}

	public function map( string $incoming_message ): string {
		$incoming = self::normalize( $incoming_message );
		if ( '' === $incoming ) {
			return '';
		}
		foreach ( $this->all_mappings() as $mapping ) {
			$pattern = (string) ( $mapping['normalized_external_status'] ?? '' );
			if ( '' === $pattern || ! str_contains( $incoming, $pattern ) ) {
				continue;
			}
			$status = (string) ( $mapping['universal_status'] ?? '' );

			return DeliveryStatus::is_valid( $status ) ? $status : '';
		}

		return '';
	}

	public static function normalize( string $value ): string {
		$value = strtr( trim( $value ), array( 'Ё' => 'Е', 'ё' => 'е' ) );
		$value = mb_strtolower( $value, 'UTF-8' );
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+([:;,])/u', '$1', $value ) ?? $value;
		$value = preg_replace( '/([:;,])(?=\S)/u', '$1 ', $value ) ?? $value;

		return trim( $value );
	}

	/** @return array{external_status:string,normalized_external_status:string,universal_status:string}|array{} */
	private function prepare_mapping( string $external_status, string $universal_status ): array {
		$external_status = trim( $external_status );
		$normalized = self::normalize( $external_status );
		$universal_status = trim( $universal_status );
		if ( '' === $external_status || '' === $normalized || mb_strlen( $external_status, 'UTF-8' ) > 255 || '' === $universal_status || ! DeliveryStatus::is_valid( $universal_status ) ) {
			return array();
		}

		return array(
			'external_status' => $external_status,
			'normalized_external_status' => $normalized,
			'universal_status' => $universal_status,
		);
	}

	private function table(): string {
		return $this->wpdb->prefix . 'wdc_jet_logistic_status_mappings';
	}

	private function charset(): string {
		return method_exists( $this->wpdb, 'get_charset_collate' ) ? $this->wpdb->get_charset_collate() : '';
	}
}

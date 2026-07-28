<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Status;

use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class JetLogisticStatusMappingRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->wpdb = $db ?? $wpdb;
	}

	public function create_schema(): void {
		dbDelta( "CREATE TABLE {$this->table()} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			external_status varchar(255) NOT NULL DEFAULT '',
			normalized_external_status varchar(255) NOT NULL DEFAULT '',
			universal_status varchar(64) NOT NULL DEFAULT '',
			active tinyint(1) NOT NULL DEFAULT 1,
			last_seen datetime NULL,
			occurrence_count bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY normalized_external_status (normalized_external_status),
			KEY active_status (active, universal_status),
			KEY last_seen (last_seen)
		) {$this->charset()};" );
		$this->ensure_default_mappings();
	}

	public function ensure_default_mappings(): void {
		foreach ( array( 'Доставка груза на склад' => DeliveryStatus::READY_FOR_PICKUP, 'Груз выдан' => DeliveryStatus::DELIVERED ) as $external => $universal ) {
			$existing = $this->find( $external );
			if ( array() === $existing ) {
				$this->save_mapping( $external, $universal, true );
			}
		}
	}

	public function observe( string $external_status ): void {
		$normalized = self::normalize( $external_status );
		if ( '' === $normalized ) {
			return;
		}
		$existing = $this->find( $external_status );
		if ( array() === $existing ) {
			$this->save_mapping( $external_status, '', false, true );
			return;
		}
		$this->wpdb->update(
			$this->table(),
			array( 'last_seen' => current_time( 'mysql' ), 'occurrence_count' => (int) ( $existing['occurrence_count'] ?? 0 ) + 1, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $existing['id'] ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/** @return array<string,mixed> */
	public function find( string $external_status ): array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE normalized_external_status = %s LIMIT 1", self::normalize( $external_status ) ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	public function save_mapping( string $external_status, string $universal_status, bool $active, bool $observed = false ): void {
		if ( '' !== $universal_status && ! DeliveryStatus::is_valid( $universal_status ) ) {
			return;
		}
		$now = current_time( 'mysql' );
		$this->wpdb->replace(
			$this->table(),
			array(
				'external_status' => trim( $external_status ),
				'normalized_external_status' => self::normalize( $external_status ),
				'universal_status' => $universal_status,
				'active' => $active ? 1 : 0,
				'last_seen' => $observed ? $now : null,
				'occurrence_count' => $observed ? 1 : 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	public function map( string $external_status ): string {
		$row = $this->find( $external_status );
		if ( empty( $row ) || empty( $row['active'] ) ) {
			return '';
		}
		$status = (string) ( $row['universal_status'] ?? '' );

		return DeliveryStatus::is_valid( $status ) ? $status : '';
	}

	public static function normalize( string $value ): string {
		$value = strtr( trim( $value ), array( 'Ё' => 'Е', 'ё' => 'е' ) );
		$value = mb_strtolower( $value, 'UTF-8' );
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( $value );
	}

	private function table(): string {
		return $this->wpdb->prefix . 'wdc_jet_logistic_status_mappings';
	}

	private function charset(): string {
		return method_exists( $this->wpdb, 'get_charset_collate' ) ? $this->wpdb->get_charset_collate() : '';
	}
}

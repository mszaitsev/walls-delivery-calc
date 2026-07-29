<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

defined( 'ABSPATH' ) || exit;

final class JetLogisticGeographyOverrideRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->wpdb = $db ?? $wpdb;
	}

	public function create_schema(): void {
		\dbDelta( "CREATE TABLE {$this->table()} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_identity varchar(64) NOT NULL,
			location_id bigint(20) unsigned NOT NULL DEFAULT 0,
			country_code char(2) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_identity (source_identity),
			KEY location_id (location_id),
			KEY country_code (country_code)
		) {$this->charset()};" );
	}

	public function save( string $source_identity, int $location_id, string $country_code ): void {
		$now = current_time( 'mysql' );
		$this->wpdb->replace( $this->table(), array( 'source_identity' => $source_identity, 'location_id' => $location_id, 'country_code' => strtoupper( $country_code ), 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%d', '%s', '%s', '%s' ) );
	}

	/** @return array<string,mixed> */
	public function find( string $source_identity ): array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE source_identity = %s LIMIT 1", $source_identity ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	private function table(): string {
		return $this->wpdb->prefix . 'wdc_jet_logistic_location_overrides';
	}

	private function charset(): string {
		return method_exists( $this->wpdb, 'get_charset_collate' ) ? $this->wpdb->get_charset_collate() : '';
	}
}

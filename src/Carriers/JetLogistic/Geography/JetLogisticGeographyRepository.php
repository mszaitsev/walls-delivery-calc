<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

defined( 'ABSPATH' ) || exit;

final class JetLogisticGeographyRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->wpdb = $db ?? $wpdb;
	}

	public function create_schema(): void {
		dbDelta( "CREATE TABLE {$this->table()} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_identity varchar(64) NOT NULL,
			source_city varchar(191) NOT NULL DEFAULT '',
			source_region varchar(191) NOT NULL DEFAULT '',
			raw_source longtext NULL,
			normalized_city varchar(191) NOT NULL DEFAULT '',
			normalized_region varchar(191) NOT NULL DEFAULT '',
			country_code char(2) NOT NULL DEFAULT '',
			location_id bigint(20) unsigned NOT NULL DEFAULT 0,
			match_status varchar(32) NOT NULL DEFAULT 'unmatched',
			match_source varchar(32) NOT NULL DEFAULT '',
			active tinyint(1) NOT NULL DEFAULT 1,
			first_seen_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_identity (source_identity),
			KEY active_location (active, location_id),
			KEY country_status (country_code, match_status),
			KEY normalized_city_region (normalized_city, normalized_region),
			KEY active_country (active, country_code)
		) {$this->charset()};" );
	}

	/** @param array<int,array<string,mixed>> $rows */
	public function replace_snapshot( array $rows ): void {
		$now = current_time( 'mysql' );
		$seen = array();
		foreach ( $rows as $row ) {
			$identity = (string) ( $row['source_identity'] ?? '' );
			if ( '' === $identity ) {
				continue;
			}
			$seen[] = $identity;
			$existing = $this->find_by_source_identity( $identity );
			$this->wpdb->replace(
				$this->table(),
				array(
					'source_identity' => $identity,
					'source_city' => (string) ( $row['source_city'] ?? '' ),
					'source_region' => (string) ( $row['source_region'] ?? '' ),
					'raw_source' => wp_json_encode( $row['raw_source'] ?? array(), JSON_UNESCAPED_UNICODE ),
					'normalized_city' => (string) ( $row['normalized_city'] ?? '' ),
					'normalized_region' => (string) ( $row['normalized_region'] ?? '' ),
					'country_code' => strtoupper( (string) ( $row['country_code'] ?? '' ) ),
					'location_id' => max( 0, (int) ( $row['location_id'] ?? 0 ) ),
					'match_status' => (string) ( $row['match_status'] ?? 'unmatched' ),
					'match_source' => (string) ( $row['match_source'] ?? '' ),
					'active' => (int) ( $row['active'] ?? 1 ),
					'first_seen_at' => (string) ( $existing['first_seen_at'] ?? $now ),
					'last_seen_at' => $now,
					'created_at' => (string) ( $existing['created_at'] ?? $now ),
					'updated_at' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		}
		$this->deactivate_missing( $seen );
	}

	/** @return array<string,mixed> */
	public function find_by_source_identity( string $identity ): array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE source_identity = %s LIMIT 1", $identity ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	/** @return array<string,mixed> */
	public function active_for_location( int $location_id ): array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE active = 1 AND location_id = %d AND match_status = 'matched' LIMIT 1", $location_id ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	/** @return array<int,array<string,mixed>> */
	public function active_origin_options(): array {
		$rows = $this->wpdb->get_results( "SELECT * FROM {$this->table()} WHERE active = 1 AND match_status = 'matched' ORDER BY country_code ASC, source_city ASC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,string> */
	public function matched_country_codes(): array {
		$rows = $this->wpdb->get_col( "SELECT DISTINCT country_code FROM {$this->table()} WHERE active = 1 AND match_status = 'matched' AND country_code <> 'RU' ORDER BY country_code ASC" );
		return array_values( array_filter( array_map( 'strval', is_array( $rows ) ? $rows : array() ) ) );
	}

	/** @param array<int,string> $seen */
	private function deactivate_missing( array $seen ): void {
		if ( array() === $seen ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $seen ), '%s' ) );
		$this->wpdb->query( $this->wpdb->prepare( "UPDATE {$this->table()} SET active = 0, updated_at = %s WHERE source_identity NOT IN ({$placeholders})", current_time( 'mysql' ), ...$seen ) );
	}

	private function table(): string {
		return $this->wpdb->prefix . 'wdc_jet_logistic_cities';
	}

	private function charset(): string {
		return method_exists( $this->wpdb, 'get_charset_collate' ) ? $this->wpdb->get_charset_collate() : '';
	}
}

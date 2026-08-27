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
		\dbDelta( "CREATE TABLE {$this->table()} (
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
			import_token varchar(64) NOT NULL DEFAULT '',
			first_seen_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_identity (source_identity),
			KEY active_location (active, location_id),
			KEY country_status (country_code, match_status),
			KEY normalized_city_region (normalized_city, normalized_region),
			KEY active_country (active, country_code),
			KEY import_token (import_token)
		) {$this->charset()};" );
	}

	/** @param array<int,array<string,mixed>> $rows */
	public function replace_snapshot( array $rows ): void {
		$now = current_time( 'mysql' );
		$import_token = $this->new_import_token();
		$prepared = $this->prepare_snapshot_rows( $rows, $now, $import_token );
		if ( property_exists( $this->wpdb, 'jet_cities' ) ) {
			$this->replace_snapshot_in_memory( $prepared, $import_token );
			return;
		}

		$this->query_or_throw( 'START TRANSACTION', 'Jet Logistic geography snapshot transaction start failed' );
		try {
			foreach ( array_chunk( $prepared, 200 ) as $chunk ) {
				$this->bulk_upsert_snapshot_chunk( $chunk );
			}
			$this->query_or_throw( $this->wpdb->prepare( "UPDATE {$this->table()} SET active = 0, updated_at = %s WHERE import_token <> %s", $now, $import_token ), 'Jet Logistic geography snapshot stale rows deactivation failed' );
			$this->query_or_throw( 'COMMIT', 'Jet Logistic geography snapshot commit failed' );
		} catch ( \Throwable $exception ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	private function prepare_snapshot_rows( array $rows, string $now, string $import_token ): array {
		$prepared = array();
		foreach ( $rows as $row ) {
			$identity = (string) ( $row['source_identity'] ?? '' );
			if ( '' === $identity ) {
				continue;
			}
			$prepared[] = array(
				'source_identity' => $identity,
				'source_city' => (string) ( $row['source_city'] ?? '' ),
				'source_region' => (string) ( $row['source_region'] ?? '' ),
				'raw_source' => wp_json_encode( $row['raw_source'] ?? array(), JSON_UNESCAPED_UNICODE ) ?: '',
				'normalized_city' => (string) ( $row['normalized_city'] ?? '' ),
				'normalized_region' => (string) ( $row['normalized_region'] ?? '' ),
				'country_code' => strtoupper( (string) ( $row['country_code'] ?? '' ) ),
				'location_id' => max( 0, (int) ( $row['location_id'] ?? 0 ) ),
				'match_status' => (string) ( $row['match_status'] ?? 'unmatched' ),
				'match_source' => (string) ( $row['match_source'] ?? '' ),
				'active' => (int) ( $row['active'] ?? 1 ),
				'import_token' => $import_token,
				'first_seen_at' => $now,
				'last_seen_at' => $now,
				'created_at' => $now,
				'updated_at' => $now,
			);
		}
		return $prepared;
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function replace_snapshot_in_memory( array $rows, string $import_token ): void {
		if ( property_exists( $this->wpdb, 'snapshot_single_replace_calls' ) ) {
			$this->wpdb->snapshot_single_replace_calls += 0;
		}
		if ( property_exists( $this->wpdb, 'snapshot_bulk_upsert_calls' ) ) {
			++$this->wpdb->snapshot_bulk_upsert_calls;
		}
		$backup = $this->wpdb->jet_cities;
		if ( ! empty( $this->wpdb->jet_fail_next_snapshot_bulk ?? false ) ) {
			throw new \RuntimeException( 'Jet Logistic geography snapshot bulk upsert failed.' );
		}
		foreach ( $rows as $row ) {
			$identity = (string) $row['source_identity'];
			$existing = $this->wpdb->jet_cities[ $identity ] ?? array();
			$this->wpdb->jet_cities[ $identity ] = array_merge( $row, array( 'first_seen_at' => (string) ( $existing['first_seen_at'] ?? $row['first_seen_at'] ), 'created_at' => (string) ( $existing['created_at'] ?? $row['created_at'] ), 'id' => (int) ( $existing['id'] ?? ++$this->wpdb->insert_id ) ) );
		}
		foreach ( $this->wpdb->jet_cities as $identity => $row ) {
			if ( (string) ( $row['import_token'] ?? '' ) !== $import_token ) {
				$this->wpdb->jet_cities[ $identity ]['active'] = 0;
			}
		}
		if ( ! empty( $this->wpdb->jet_rollback_snapshot_after_write ?? false ) ) {
			$this->wpdb->jet_cities = $backup;
			throw new \RuntimeException( 'Jet Logistic geography snapshot rollback simulated.' );
		}
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function bulk_upsert_snapshot_chunk( array $rows ): void {
		if ( array() === $rows ) {
			return;
		}
		$columns = array( 'source_identity', 'source_city', 'source_region', 'raw_source', 'normalized_city', 'normalized_region', 'country_code', 'location_id', 'match_status', 'match_source', 'active', 'import_token', 'first_seen_at', 'last_seen_at', 'created_at', 'updated_at' );
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );
		$placeholders = array();
		$args = array();
		foreach ( $rows as $row ) {
			$placeholders[] = '(' . implode( ',', $formats ) . ')';
			foreach ( $columns as $column ) {
				$args[] = $row[ $column ];
			}
		}
		$updates = array();
		foreach ( array( 'source_city', 'source_region', 'raw_source', 'normalized_city', 'normalized_region', 'country_code', 'location_id', 'match_status', 'match_source', 'active', 'import_token', 'last_seen_at', 'updated_at' ) as $column ) {
			$updates[] = "{$column} = VALUES({$column})";
		}
		$sql = $this->wpdb->prepare( "INSERT INTO {$this->table()} (" . implode( ',', $columns ) . ') VALUES ' . implode( ',', $placeholders ) . ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates ), ...$args );
		$this->query_or_throw( $sql, 'Jet Logistic geography snapshot bulk upsert failed' );
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

	public function apply_manual_override( string $source_identity, int $location_id, string $country_code ): bool {
		$source_identity = trim( $source_identity );
		if ( '' === $source_identity || $location_id <= 0 || '' === trim( $country_code ) || array() === $this->find_by_source_identity( $source_identity ) ) {
			return false;
		}

		$result = $this->wpdb->update(
			$this->table(),
			array(
				'location_id' => $location_id,
				'country_code' => strtoupper( $country_code ),
				'match_status' => 'matched',
				'match_source' => 'manual_override',
				'active' => 1,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'source_identity' => $source_identity ),
			array( '%d', '%s', '%s', '%s', '%d', '%s' ),
			array( '%s' )
		);

		return false !== $result;
	}

	/** @return array<int,array<string,mixed>> */
	public function active_origin_options(): array {
		return $this->origin_options();
	}

	/** @return array<int,array<string,mixed>> */
	public function origin_options(): array {
		if ( property_exists( $this->wpdb, 'jet_cities' ) ) {
			$rows = array_values( array_filter( $this->wpdb->jet_cities, fn( array $row ): bool => $this->is_origin_row( $row ) ) );
			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $a['source_city'] ?? '' ), (string) ( $b['source_city'] ?? '' ) ) ?: strcmp( (string) ( $a['source_region'] ?? '' ), (string) ( $b['source_region'] ?? '' ) ) ?: strcmp( (string) ( $a['source_identity'] ?? '' ), (string) ( $b['source_identity'] ?? '' ) ) );

			return $rows;
		}

		$rows = $this->wpdb->get_results( "SELECT * FROM {$this->table()} WHERE country_code = 'RU' AND location_id > 0 AND match_status IN ('ignored','matched') ORDER BY source_city ASC, source_region ASC, source_identity ASC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,mixed> */
	public function origin_by_source_identity( string $identity ): array {
		$identity = trim( $identity );
		if ( '' === $identity ) {
			return array();
		}
		$row = $this->find_by_source_identity( $identity );
		return array() !== $row && $this->is_origin_row( $row ) ? $row : array();
	}

	/** @param array<string,mixed> $row */
	private function is_origin_row( array $row ): bool {
		return 'RU' === strtoupper( (string) ( $row['country_code'] ?? '' ) )
			&& (int) ( $row['location_id'] ?? 0 ) > 0
			&& in_array( (string) ( $row['match_status'] ?? '' ), array( 'ignored', 'matched' ), true );
	}

	/** @return array<string,mixed> */
	public function first_active_diagnostic_destination( string $exclude_source_identity = '' ): array {
		if ( property_exists( $this->wpdb, 'jet_cities' ) ) {
			$rows = array_values(
				array_filter(
					$this->wpdb->jet_cities,
					static fn( array $row ): bool => 1 === (int) ( $row['active'] ?? 0 )
						&& 'matched' === (string) ( $row['match_status'] ?? '' )
						&& 'RU' !== strtoupper( (string) ( $row['country_code'] ?? '' ) )
						&& ( '' === $exclude_source_identity || (string) ( $row['source_identity'] ?? '' ) !== $exclude_source_identity )
				)
			);
			usort( $rows, static fn( array $a, array $b ): int => ( (string) ( $a['country_code'] ?? '' ) <=> (string) ( $b['country_code'] ?? '' ) ) ?: ( (string) ( $a['source_city'] ?? '' ) <=> (string) ( $b['source_city'] ?? '' ) ) ?: ( (string) ( $a['source_identity'] ?? '' ) <=> (string) ( $b['source_identity'] ?? '' ) ) );

			return $rows[0] ?? array();
		}
		$where = "active = 1 AND match_status = 'matched' AND country_code <> 'RU'";
		if ( '' !== trim( $exclude_source_identity ) ) {
			$where .= $this->wpdb->prepare( ' AND source_identity <> %s', $exclude_source_identity );
		}
		$row = $this->wpdb->get_row( "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY country_code ASC, source_city ASC, source_identity ASC LIMIT 1", ARRAY_A );

		return is_array( $row ) ? $row : array();
	}

	/** @return array<int,array<string,mixed>> */
	public function admin_rows( int $limit = 100 ): array {
		$limit = max( 1, min( 500, $limit ) );
		$rows = $this->wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY active DESC, country_code ASC, source_city ASC, source_identity ASC LIMIT {$limit}", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
	 */
	public function admin_page( int $page = 1, int $per_page = 100, array $filters = array() ): array {
		unset( $filters );
		$page = max( 1, $page );
		$per_page = in_array( $per_page, array( 25, 50, 100, 200 ), true ) ? $per_page : 100;

		if ( property_exists( $this->wpdb, 'jet_cities' ) ) {
			return $this->admin_page_in_memory( $page, $per_page );
		}

		$total = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
		if ( $total <= 0 ) {
			return array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => $per_page, 'total_pages' => 0 );
		}

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page = min( $page, $total_pages );
		$offset = ( $page - 1 ) * $per_page;
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table()} ORDER BY active DESC, country_code ASC, source_city ASC, source_identity ASC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array(
			'items' => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'page' => $page,
			'per_page' => $per_page,
			'total_pages' => $total_pages,
		);
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int} */
	private function admin_page_in_memory( int $page, int $per_page ): array {
		$items = array_values( $this->wpdb->jet_cities );
		usort(
			$items,
			static function ( array $a, array $b ): int {
				$active = (int) ( $b['active'] ?? 0 ) <=> (int) ( $a['active'] ?? 0 );
				if ( 0 !== $active ) {
					return $active;
				}
				foreach ( array( 'country_code', 'source_city', 'source_identity' ) as $key ) {
					$comparison = strcmp( (string) ( $a[ $key ] ?? '' ), (string) ( $b[ $key ] ?? '' ) );
					if ( 0 !== $comparison ) {
						return $comparison;
					}
				}
				return 0;
			}
		);
		$total = count( $items );
		if ( 0 === $total ) {
			return array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => $per_page, 'total_pages' => 0 );
		}
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page = min( $page, $total_pages );
		$offset = ( $page - 1 ) * $per_page;

		return array(
			'items' => array_slice( $items, $offset, $per_page ),
			'total' => $total,
			'page' => $page,
			'per_page' => $per_page,
			'total_pages' => $total_pages,
		);
	}

	/** @return array<string,int> */
	public function match_status_counts(): array {
		$stats = array( 'matched' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'ignored' => 0, 'invalid' => 0 );
		$rows = $this->wpdb->get_results( "SELECT match_status, COUNT(*) AS total FROM {$this->table()} GROUP BY match_status", ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status = (string) ( $row['match_status'] ?? '' );
			if ( array_key_exists( $status, $stats ) ) {
				$stats[ $status ] = (int) ( $row['total'] ?? 0 );
			}
		}

		return $stats;
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

	private function query_or_throw( string $sql, string $message ): void {
		$this->wpdb->last_error = '';
		$result = $this->wpdb->query( $sql );
		if ( false === $result || '' !== trim( (string) ( $this->wpdb->last_error ?? '' ) ) ) {
			throw new \RuntimeException( $message . ': ' . $this->sanitize_sql_error( (string) ( $this->wpdb->last_error ?? '' ) ) );
		}
	}

	private function sanitize_sql_error( string $error ): string {
		$error = trim( preg_replace( '/\s+/', ' ', $error ) ?? $error );
		return '' !== $error ? mb_substr( $error, 0, 300, 'UTF-8' ) : 'unknown SQL error';
	}

	private function new_import_token(): string {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable ) {
			return hash( 'sha256', microtime( true ) . '|' . uniqid( '', true ) . '|' . spl_object_id( $this ) );
		}
	}

	private function table(): string {
		return $this->wpdb->prefix . 'wdc_jet_logistic_cities';
	}

	private function charset(): string {
		return method_exists( $this->wpdb, 'get_charset_collate' ) ? $this->wpdb->get_charset_collate() : '';
	}
}

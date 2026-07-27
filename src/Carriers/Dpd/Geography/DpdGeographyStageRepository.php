<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyStageRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	public function table_name_for_job( string $job_id ): string {
		$hash = substr( preg_replace( '/[^a-f0-9]/', '', strtolower( sha1( $job_id ) ) ) ?? '', 0, 12 );
		if ( '' === $hash ) {
			$hash = '000000000000';
		}

		return $this->wpdb->prefix . 'wdc_dpd_geography_stage_' . $hash;
	}

	public function create( string $table_name ): bool {
		if ( $this->is_test_mode() ) {
			$this->wpdb->dpd_geography_stage_tables[ $table_name ] = array();
			return true;
		}

		$sql = 'CREATE TABLE IF NOT EXISTS ' . $this->safe_table_name( $table_name ) . ' (
			location_id BIGINT UNSIGNED NOT NULL,
			dpd_city_id BIGINT UNSIGNED NULL,
			match_method VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT \'candidate\',
			updated_at DATETIME NULL,
			PRIMARY KEY (location_id),
			KEY status (status)
		) ' . $this->wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return true;
	}

	public function drop( string $table_name ): bool {
		if ( '' === $table_name ) {
			return false;
		}
		if ( $this->is_test_mode() ) {
			unset( $this->wpdb->dpd_geography_stage_tables[ $table_name ] );
			return true;
		}

		return false !== $this->wpdb->query( 'DROP TABLE IF EXISTS ' . $this->safe_table_name( $table_name ) );
	}

	public function upsert_candidate( string $table_name, int $location_id, string|int $dpd_city_id, string $match_method ): string {
		$location_id = max( 0, $location_id );
		$dpd_city_id = preg_replace( '/\D+/', '', (string) $dpd_city_id ) ?? '';
		$match_method = $this->normalize_match_method( $match_method );
		if ( 0 === $location_id || '' === $dpd_city_id || '0' === $dpd_city_id ) {
			return 'invalid';
		}
		$now = $this->now();

		if ( $this->is_test_mode() ) {
			$this->create_if_missing_for_test( $table_name );
			$current = $this->wpdb->dpd_geography_stage_tables[ $table_name ][ $location_id ] ?? null;
			if ( is_array( $current ) ) {
				if ( 'conflict' === (string) ( $current['status'] ?? '' ) ) {
					return 'conflict';
				}
				if ( (string) ( $current['dpd_city_id'] ?? '' ) === $dpd_city_id ) {
					return 'unchanged';
				}
				$this->wpdb->dpd_geography_stage_tables[ $table_name ][ $location_id ] = array(
					'location_id' => $location_id,
					'dpd_city_id' => null,
					'match_method' => $match_method,
					'status' => 'conflict',
					'updated_at' => $now,
				);
				return 'conflict';
			}
			$this->wpdb->dpd_geography_stage_tables[ $table_name ][ $location_id ] = array(
				'location_id' => $location_id,
				'dpd_city_id' => $dpd_city_id,
				'match_method' => $match_method,
				'status' => 'candidate',
				'updated_at' => $now,
			);
			return 'inserted';
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT dpd_city_id, status FROM ' . $this->safe_table_name( $table_name ) . ' WHERE location_id = %d LIMIT 1', $location_id ),
			ARRAY_A
		);
			if ( is_array( $row ) ) {
				if ( 'conflict' === (string) ( $row['status'] ?? '' ) ) {
					return 'conflict';
				}
			if ( (string) ( $row['dpd_city_id'] ?? '' ) === $dpd_city_id ) {
				return 'unchanged';
			}
			$result = $this->wpdb->update(
				$table_name,
				array( 'dpd_city_id' => null, 'match_method' => $match_method, 'status' => 'conflict', 'updated_at' => $now ),
				array( 'location_id' => $location_id ),
				array( '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $result ) {
				$this->throw_sql_error( 'DPD geography stage conflict update failed' );
			}
			return 'conflict';
		}

		$result = $this->wpdb->insert(
			$table_name,
			array( 'location_id' => $location_id, 'dpd_city_id' => (int) $dpd_city_id, 'match_method' => $match_method, 'status' => 'candidate', 'updated_at' => $now ),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		if ( false === $result ) {
			$this->throw_sql_error( 'DPD geography stage insert failed' );
		}

		return 'inserted';
	}

	/**
	 * @return array{candidate:int,conflict:int}
	 */
	public function counts( string $table_name ): array {
		if ( $this->is_test_mode() ) {
			$counts = array( 'candidate' => 0, 'conflict' => 0 );
			foreach ( $this->wpdb->dpd_geography_stage_tables[ $table_name ] ?? array() as $row ) {
				$status = (string) ( $row['status'] ?? '' );
				if ( isset( $counts[ $status ] ) ) {
					++$counts[ $status ];
				}
			}
			return $counts;
		}

		return array(
			'candidate' => (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->safe_table_name( $table_name ) . " WHERE status = 'candidate'" ),
			'conflict' => (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->safe_table_name( $table_name ) . " WHERE status = 'conflict'" ),
		);
	}

	/**
	 * @return array{mappings:int,changes:int,stale_cleared:int,stale_cleanup_skipped:bool}
	 */
	public function finalize_into_delivery_codes( string $table_name, bool $allow_stale_cleanup = true ): array {
		if ( $this->is_test_mode() ) {
			$this->create_if_missing_for_test( $table_name );
			$snapshot = $this->wpdb->delivery_codes;
			if ( ! empty( $this->wpdb->fail_dpd_stage_candidate_count ) ) {
				$this->wpdb->last_error = 'forced stage candidate count failure';
				$this->wpdb->delivery_codes = $snapshot;
				$this->throw_sql_error( 'DPD geography finalization candidate count failed' );
			}
			if ( ! empty( $this->wpdb->fail_dpd_stage_candidate_change_count ) ) {
				$this->wpdb->last_error = 'forced stage candidate change count failure';
				$this->wpdb->delivery_codes = $snapshot;
				$this->throw_sql_error( 'DPD geography finalization candidate change count failed' );
			}
			if ( $allow_stale_cleanup && ! empty( $this->wpdb->fail_dpd_stage_stale_count ) ) {
				$this->wpdb->last_error = 'forced stage stale count failure';
				$this->wpdb->delivery_codes = $snapshot;
				$this->throw_sql_error( 'DPD geography finalization stale count failed' );
			}
			if ( $allow_stale_cleanup && ! empty( $this->wpdb->fail_dpd_stage_finalize_clear ) ) {
				$this->wpdb->last_error = 'forced stage clear failure';
				$this->wpdb->delivery_codes = $snapshot;
				$this->throw_sql_error( 'DPD geography finalization clear failed' );
			}
			$candidates = array();
			$stage_location_ids = array();
			foreach ( $this->wpdb->dpd_geography_stage_tables[ $table_name ] as $row ) {
				$stage_location_ids[ (int) ( $row['location_id'] ?? 0 ) ] = true;
				if ( 'candidate' === (string) ( $row['status'] ?? '' ) && ! empty( $row['dpd_city_id'] ) ) {
					$candidates[ (int) $row['location_id'] ] = (string) $row['dpd_city_id'];
				}
			}
			$changed = 0;
			$stale_cleared = 0;
			$existing_ids = array();
			foreach ( $this->wpdb->delivery_codes as $index => $row ) {
				$location_id = (int) ( $row['location_id'] ?? 0 );
				$existing_ids[ $location_id ] = true;
				if ( isset( $candidates[ $location_id ] ) ) {
					if ( (string) ( $row['dpd_city_id'] ?? '' ) !== $candidates[ $location_id ] ) {
						$this->wpdb->delivery_codes[ $index ]['dpd_city_id'] = $candidates[ $location_id ];
						$this->wpdb->delivery_codes[ $index ]['updated_at'] = $this->now();
						++$changed;
					}
					continue;
				}
				if ( $allow_stale_cleanup && ! isset( $stage_location_ids[ $location_id ] ) && null !== ( $row['dpd_city_id'] ?? null ) && '' !== (string) ( $row['dpd_city_id'] ?? '' ) ) {
					$this->wpdb->delivery_codes[ $index ]['dpd_city_id'] = null;
					$this->wpdb->delivery_codes[ $index ]['updated_at'] = $this->now();
					++$changed;
					++$stale_cleared;
				}
			}
			if ( ! empty( $this->wpdb->fail_dpd_stage_finalize_upsert ) ) {
				$this->wpdb->last_error = 'forced stage upsert failure';
				$this->wpdb->delivery_codes = $snapshot;
				$this->throw_sql_error( 'DPD geography finalization upsert failed' );
			}
			foreach ( $candidates as $location_id => $dpd_city_id ) {
				if ( isset( $existing_ids[ $location_id ] ) ) {
					continue;
				}
				$this->wpdb->delivery_codes[] = array( 'location_id' => $location_id, 'dpd_city_id' => $dpd_city_id, 'updated_at' => $this->now() );
				++$changed;
			}
			if ( ! empty( $this->wpdb->fail_dpd_stage_finalize_commit ) ) {
				$this->wpdb->last_error = 'forced stage commit failure';
				$this->wpdb->delivery_codes = $snapshot;
				$this->throw_sql_error( 'DPD geography finalization commit failed' );
			}
			return array(
				'mappings' => count( $candidates ),
				'changes' => $changed,
				'stale_cleared' => $stale_cleared,
				'stale_cleanup_skipped' => ! $allow_stale_cleanup,
			);
		}

		$delivery_table = $this->wpdb->prefix . 'wdc_location_delivery_codes';
		$safe_stage = $this->safe_table_name( $table_name );
		$now = $this->now();
		$this->query_or_throw( 'START TRANSACTION', 'DPD geography finalization transaction start failed' );
		try {
			$candidate_count = $this->get_count_or_throw( "SELECT COUNT(*) FROM {$safe_stage} WHERE status = 'candidate' AND dpd_city_id IS NOT NULL", 'DPD geography finalization candidate count failed' );
			$candidate_change_count = $this->get_count_or_throw(
				"SELECT COUNT(*)
				FROM {$safe_stage} stage
				LEFT JOIN {$delivery_table} dc ON dc.location_id = stage.location_id
				WHERE stage.status = 'candidate'
					AND stage.dpd_city_id IS NOT NULL
					AND (dc.location_id IS NULL OR dc.dpd_city_id IS NULL OR NOT (dc.dpd_city_id <=> stage.dpd_city_id))",
				'DPD geography finalization candidate change count failed'
			);
			$stale_clear_count = 0;
			if ( $allow_stale_cleanup ) {
				$stale_clear_count = $this->get_count_or_throw(
					"SELECT COUNT(*)
					FROM {$delivery_table} dc
					LEFT JOIN {$safe_stage} stage ON stage.location_id = dc.location_id
					WHERE stage.location_id IS NULL AND dc.dpd_city_id IS NOT NULL",
					'DPD geography finalization stale count failed'
				);
				$this->query_or_throw(
					"UPDATE {$delivery_table} dc
					LEFT JOIN {$safe_stage} stage ON stage.location_id = dc.location_id
					SET dc.dpd_city_id = NULL, dc.updated_at = '" . esc_sql( $now ) . "'
					WHERE stage.location_id IS NULL AND dc.dpd_city_id IS NOT NULL",
					'DPD geography finalization clear failed'
				);
			}
			$this->query_or_throw(
				"UPDATE {$delivery_table} AS dc
				INNER JOIN {$safe_stage} AS stage ON stage.location_id = dc.location_id
				SET dc.dpd_city_id = stage.dpd_city_id,
					dc.updated_at = '" . esc_sql( $now ) . "'
				WHERE stage.status = 'candidate'
					AND stage.dpd_city_id IS NOT NULL
					AND NOT (dc.dpd_city_id <=> stage.dpd_city_id)",
				'DPD geography finalization existing mappings update failed'
			);
			$this->query_or_throw(
				"INSERT INTO {$delivery_table} (location_id, dpd_city_id, updated_at)
				SELECT stage.location_id, stage.dpd_city_id, '" . esc_sql( $now ) . "'
				FROM {$safe_stage} AS stage
				LEFT JOIN {$delivery_table} AS dc ON dc.location_id = stage.location_id
				WHERE stage.status = 'candidate'
					AND stage.dpd_city_id IS NOT NULL
					AND dc.location_id IS NULL",
				'DPD geography finalization missing mappings insert failed'
			);
			$this->query_or_throw( 'COMMIT', 'DPD geography finalization commit failed' );
		} catch ( \RuntimeException $exception ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $exception;
		}

		return array(
			'mappings' => $candidate_count,
			'changes' => max( 0, $stale_clear_count ) + max( 0, $candidate_change_count ),
			'stale_cleared' => max( 0, $stale_clear_count ),
			'stale_cleanup_skipped' => ! $allow_stale_cleanup,
		);
	}

	public function exists( string $table_name ): bool {
		if ( $this->is_test_mode() ) {
			return isset( $this->wpdb->dpd_geography_stage_tables[ $table_name ] );
		}

		return (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	}

	private function is_test_mode(): bool {
		return isset( $this->wpdb->dpd_geography_stage_tables );
	}

	private function create_if_missing_for_test( string $table_name ): void {
		if ( ! isset( $this->wpdb->dpd_geography_stage_tables[ $table_name ] ) ) {
			$this->wpdb->dpd_geography_stage_tables[ $table_name ] = array();
		}
	}

	private function safe_table_name( string $table_name ): string {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table_name ) ) {
			throw new \InvalidArgumentException( 'Invalid DPD geography stage table name.' );
		}

		return $table_name;
	}

	private function normalize_match_method( string $method ): string {
		$method = preg_replace( '/[^a-z0-9_]/', '', strtolower( $method ) ) ?? '';
		return in_array( $method, array( 'fias', 'own_fias', 'city_fias', 'kladr', 'name', 'foreign' ), true ) ? $method : 'unknown';
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function throw_sql_error( string $message ): never {
		$error = trim( (string) ( $this->wpdb->last_error ?? '' ) );
		$error = preg_replace( '/[\r\n\t]+/', ' ', $error ) ?? $error;
		throw new \RuntimeException( trim( $message . ': ' . ( '' !== $error ? $error : 'unknown SQL error' ) ) );
	}

	private function query_or_throw( string $sql, string $message ): int {
		$result = $this->wpdb->query( $sql );
		if ( false === $result ) {
			$this->throw_sql_error( $message );
		}

		return (int) $result;
	}

	private function get_count_or_throw( string $sql, string $message ): int {
		$this->wpdb->last_error = '';
		$value = $this->wpdb->get_var( $sql );
		if ( '' !== trim( (string) ( $this->wpdb->last_error ?? '' ) ) ) {
			$this->throw_sql_error( $message );
		}
		if ( null === $value || ! is_numeric( $value ) ) {
			throw new \RuntimeException( $message . ': unknown or non-numeric SQL result' );
		}

		return max( 0, (int) $value );
	}
}

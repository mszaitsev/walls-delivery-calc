<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryPickupRepository {
	private const DISCOVERY_INSERT_CHUNK_SIZE = 250;
	private const ENRICHMENT_INSERT_CHUNK_SIZE = 100;
	private object $wpdb;

	public function __construct( ?object $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
	}

	public function generations_table(): string {
		return $this->wpdb->prefix . 'wdc_ozon_delivery_pickup_generations';
	}

	public function points_table(): string {
		return $this->wpdb->prefix . 'wdc_ozon_delivery_pickup_points';
	}

	public function ids_table(): string {
		return $this->wpdb->prefix . 'wdc_ozon_delivery_pickup_ids';
	}

	public function start( string $job_id, ?string $lock_owner = null ): ?int {
		$now = current_time( 'mysql', true );
		if ( false === $this->wpdb->insert(
			$this->generations_table(),
			array(
				'state'               => 'building',
				'phase'               => 'discovery',
				'job_id'              => $job_id,
				'lock_owner'          => null === $lock_owner ? null : substr( $lock_owner, 0, 64 ),
				'started_at'          => $now,
				'progress_updated_at' => $now,
			)
		) ) {
			return null;
		}

		$id = (int) $this->wpdb->insert_id;
		return $id > 0 ? $id : null;
	}

	/** @return array<string,mixed>|null */
	public function generation_by_job( string $job_id ): ?array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->generations_table()} WHERE job_id=%s", $job_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function building_generation(): ?array {
		$row = $this->wpdb->get_row( "SELECT * FROM {$this->generations_table()} WHERE state='building' ORDER BY id DESC LIMIT 1", ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function generation_state( int $generation_id ): ?string {
		$state = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT state FROM {$this->generations_table()} WHERE id=%d", $generation_id ) );
		return is_scalar( $state ) && '' !== (string) $state ? (string) $state : null;
	}

	public function building_phase_matches( int $generation_id, string $phase ): bool {
		$generation = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT state,phase FROM {$this->generations_table()} WHERE id=%d", $generation_id ), ARRAY_A );
		return is_array( $generation ) && 'building' === (string) ( $generation['state'] ?? '' ) && $phase === (string) ( $generation['phase'] ?? 'discovery' );
	}

	/** @return array<string,mixed>|null */
	public function active_generation(): ?array {
		$row = $this->wpdb->get_row( "SELECT * FROM {$this->generations_table()} WHERE state='active' ORDER BY id DESC LIMIT 1", ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function active_count(): int {
		$active = $this->active_generation();
		return $active ? (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->points_table()} WHERE generation_id=%d", $active['id'] ) ) : 0;
	}

	/** @param array<string,mixed> $row */
	public function insert_point( int $generation_id, array $row ): bool {
		$row['generation_id'] = $generation_id;
		return false !== $this->wpdb->insert( $this->points_table(), $row );
	}

	/** @return array<string,mixed>|null */
	public function find_in_generation( int $generation_id, int $point_id ): ?array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT point_id,fingerprint FROM {$this->points_table()} WHERE generation_id=%d AND point_id=%d", $generation_id, $point_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @param array<string,mixed> $patch */
	public function update_generation( int $id, array $patch ): bool {
		$patch['progress_updated_at'] = current_time( 'mysql', true );
		return false !== $this->wpdb->update( $this->generations_table(), $patch, array( 'id' => $id ) );
	}

	/**
	 * @param array<int,int> $ids
	 * @param array<string,mixed> $generation_patch
	 */
	public function commit_discovery_page( int $generation_id, array $ids, array $generation_patch ): bool {
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}

		try {
			$generation = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT state,phase FROM {$this->generations_table()} WHERE id=%d", $generation_id ), ARRAY_A );
			if ( ! is_array( $generation ) || 'building' !== (string) $generation['state'] || 'discovery' !== (string) ( $generation['phase'] ?? 'discovery' ) ) {
				return $this->rollback();
			}

			$now = current_time( 'mysql', true );
			foreach ( array_chunk( $ids, self::DISCOVERY_INSERT_CHUNK_SIZE ) as $chunk ) {
				if ( ! $this->insert_discovery_ids( $generation_id, $chunk, $now ) ) {
					return $this->rollback();
				}
			}

			$discovered_count = $this->count_ids( $generation_id );
			$generation_patch['discovered_count'] = $discovered_count;
			$generation_patch['downloaded_count'] = $discovered_count;
			if ( ! $this->update_generation( $generation_id, $generation_patch ) ) {
				return $this->rollback();
			}
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				return $this->rollback();
			}
			return true;
		} catch ( \Throwable ) {
			return $this->rollback();
		}
	}

	/** @return array<int,int> */
	public function pending_ids( int $generation_id, int $limit = 100 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT point_id FROM {$this->ids_table()} WHERE generation_id=%d AND status=%s ORDER BY id ASC LIMIT %d", $generation_id, 'pending', max( 1, min( 100, $limit ) ) ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_values( array_map( static fn( array $row ): int => (int) $row['point_id'], $rows ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $points
	 * @param array<int,string> $rejects point_id => reject_code
	 */
	public function commit_enrichment_batch( int $generation_id, array $points, array $rejects ): bool {
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}

		try {
			$generation = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT state,phase,accepted_count,rejected_count,enrichment_processed_count FROM {$this->generations_table()} WHERE id=%d", $generation_id ), ARRAY_A );
			if ( ! is_array( $generation ) || 'building' !== (string) $generation['state'] || 'enrichment' !== (string) ( $generation['phase'] ?? '' ) ) {
				return $this->rollback();
			}

			$accepted_ids = array_values( array_map( static fn( array $row ): int => (int) ( $row['point_id'] ?? 0 ), $points ) );
			if ( in_array( 0, $accepted_ids, true ) || ! $this->insert_points_bulk( $generation_id, $points ) || ! $this->mark_ids_enriched( $generation_id, $accepted_ids ) ) {
				return $this->rollback();
			}
			$normalized_rejects = array();
			foreach ( $rejects as $point_id => $code ) {
				$point_id = (int) $point_id;
				if ( $point_id <= 0 ) { return $this->rollback(); }
				$normalized_rejects[ $point_id ] = substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( $code ) ) ?? '', 0, 40 );
			}
			if ( ! $this->mark_ids_rejected( $generation_id, $normalized_rejects ) ) {
				return $this->rollback();
			}
			$accepted = count( $accepted_ids );
			$rejected = count( $normalized_rejects );

			if ( ! $this->update_generation(
				$generation_id,
				$this->clear_diagnostics(
					array(
						'accepted_count'              => (int) $generation['accepted_count'] + $accepted,
						'rejected_count'              => (int) $generation['rejected_count'] + $rejected,
						'enrichment_processed_count'  => (int) ( $generation['enrichment_processed_count'] ?? 0 ) + $accepted + $rejected,
						'retry_count'                 => 0,
					)
				)
			) ) {
				return $this->rollback();
			}

			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				return $this->rollback();
			}
			return true;
		} catch ( \Throwable ) {
			return $this->rollback();
		}
	}

	public function pending_count( int $generation_id ): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->ids_table()} WHERE generation_id=%d AND status=%s", $generation_id, 'pending' ) );
	}

	public function count_ids( int $generation_id ): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->ids_table()} WHERE generation_id=%d", $generation_id ) );
	}

	public function ready_for_activation( int $generation_id ): bool {
		$generation = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT discovered_count,accepted_count,rejected_count,enrichment_processed_count,conflict_count FROM {$this->generations_table()} WHERE id=%d", $generation_id ), ARRAY_A );
		if ( ! is_array( $generation ) || (int) $generation['conflict_count'] > 0 || $this->pending_count( $generation_id ) > 0 ) {
			return false;
		}
		$discovered = (int) $generation['discovered_count'];
		return $discovered > 0
			&& (int) $generation['accepted_count'] + (int) $generation['rejected_count'] === $discovered
			&& (int) $generation['enrichment_processed_count'] === $discovered;
	}

	public function mark_ready_if_complete( int $generation_id ): bool {
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}

		try {
			$generation = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT state,phase,discovered_count,accepted_count,rejected_count,enrichment_processed_count,conflict_count FROM {$this->generations_table()} WHERE id=%d", $generation_id ), ARRAY_A );
			if ( ! is_array( $generation ) || 'building' !== (string) $generation['state'] || 'enrichment' !== (string) ( $generation['phase'] ?? '' ) || (int) $generation['conflict_count'] > 0 || $this->pending_count( $generation_id ) > 0 ) {
				return $this->rollback();
			}

			$discovered = (int) $generation['discovered_count'];
			if ( $discovered <= 0 || (int) $generation['accepted_count'] + (int) $generation['rejected_count'] !== $discovered || (int) $generation['enrichment_processed_count'] !== $discovered ) {
				return $this->rollback();
			}

			$updated = $this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE {$this->generations_table()} SET state='ready', progress_updated_at=%s WHERE id=%d AND state='building' AND phase='enrichment' AND conflict_count=0 AND discovered_count>0 AND accepted_count + rejected_count = discovered_count AND enrichment_processed_count = discovered_count",
					current_time( 'mysql', true ),
					$generation_id
				)
			);
			if ( false === $updated || 1 !== (int) $updated ) {
				return $this->rollback();
			}

			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				return $this->rollback();
			}
			return true;
		} catch ( \Throwable ) {
			return $this->rollback();
		}
	}

	/** @param array<string,mixed> $diagnostics */
	public function record_retry( int $id, int $retry_count, array $diagnostics ): void {
		if ( ! $this->generation_is_building( $id ) ) {
			return;
		}
		$this->update_generation(
			$id,
			array_merge(
				$this->diagnostic_patch( $diagnostics ),
				array(
					'retry_count'        => max( 0, $retry_count ),
					'safe_error_code'    => substr( (string) ( $diagnostics['code'] ?? '' ), 0, 80 ),
					'safe_error_message' => substr( (string) ( $diagnostics['message'] ?? 'Временная ошибка Ozon Delivery.' ), 0, 300 ),
				)
			)
		);
	}

	/** @param array<string,mixed> $diagnostics */
	public function fail( int $id, string $code, string $message, array $diagnostics = array() ): void {
		if ( ! $this->generation_can_fail( $id ) ) {
			return;
		}
		$this->update_generation(
			$id,
			array_merge(
				$this->diagnostic_patch( $diagnostics ),
				array(
					'state'              => 'failed',
					'completed_at'       => current_time( 'mysql', true ),
					'failed_at'          => current_time( 'mysql', true ),
					'safe_error_code'    => substr( $code, 0, 80 ),
					'safe_error_message' => substr( $message, 0, 300 ),
				)
			)
		);
		$this->cleanup_generation_rows( $id );
	}

	public function cancel_building_generation( int $id ): bool {
		$now = current_time( 'mysql', true );
		$updated = $this->wpdb->update(
			$this->generations_table(),
			array(
				'state'              => 'cancelled',
				'completed_at'       => $now,
				'progress_updated_at' => $now,
				'safe_error_code'    => null,
				'safe_error_message' => 'Импорт остановлен администратором.',
			),
			array(
				'id'    => $id,
				'state' => 'building',
			)
		);
		if ( 1 !== (int) $updated ) {
			return false;
		}
		$this->cleanup_generation_rows( $id );
		return true;
	}

	public function activate( int $id ): bool {
		$generation = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->generations_table()} WHERE id=%d", $id ), ARRAY_A );
		if ( ! is_array( $generation ) || 'ready' !== (string) $generation['state'] || (int) $generation['accepted_count'] <= 0 || (int) $generation['conflict_count'] > 0 ) {
			return false;
		}

		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}

		try {
			$obsolete = $this->wpdb->query( "UPDATE {$this->generations_table()} SET state='obsolete' WHERE state='active'" );
			if ( false === $obsolete ) {
				return $this->rollback();
			}

			$activated = $this->wpdb->query( $this->wpdb->prepare( "UPDATE {$this->generations_table()} SET state='active', completed_at=%s WHERE id=%d AND state='ready'", current_time( 'mysql', true ), $id ) );
			if ( false === $activated || 1 !== (int) $activated ) {
				return $this->rollback();
			}

			$active_count = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->generations_table()} WHERE state='active'" );
			$active_id    = (int) $this->wpdb->get_var( "SELECT id FROM {$this->generations_table()} WHERE state='active' ORDER BY id DESC LIMIT 1" );
			if ( 1 !== $active_count || $id !== $active_id ) {
				return $this->rollback();
			}

			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				return $this->rollback();
			}
			$this->cleanup_obsolete_points( $id );
			$this->cleanup_ids_for_generation( $id );
			return true;
		} catch ( \Throwable ) {
			return $this->rollback();
		}
	}

	public function cleanup_obsolete_points( int $active_generation_id ): bool {
		return false !== $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->points_table()} WHERE generation_id <> %d", $active_generation_id ) );
	}

	public function cleanup_ids_for_generation( int $generation_id ): bool {
		return false !== $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->ids_table()} WHERE generation_id=%d", $generation_id ) );
	}

	private function cleanup_generation_rows( int $generation_id ): void {
		$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->points_table()} WHERE generation_id=%d", $generation_id ) );
		$this->cleanup_ids_for_generation( $generation_id );
	}

	private function generation_is_building( int $generation_id ): bool {
		return 'building' === $this->generation_state( $generation_id );
	}

	private function generation_can_fail( int $generation_id ): bool {
		return in_array( $this->generation_state( $generation_id ), array( 'building', 'ready' ), true );
	}

	private function rollback(): bool {
		$this->wpdb->query( 'ROLLBACK' );
		return false;
	}

	/** @return array<string,mixed>|null */
	public function find_active( int $point_id ): ?array {
		$active = $this->active_generation();
		if ( ! $active ) {
			return null;
		}
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->points_table()} WHERE generation_id=%d AND point_id=%d", $active['id'], $point_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> */
	public function find_active_in_area( float $latitude, float $longitude, float $latitude_delta, float $longitude_delta ): array {
		$active = $this->active_generation();
		if ( ! $active ) {
			return array();
		}
		$south = $latitude - $latitude_delta;
		$north = $latitude + $latitude_delta;
		$west = $longitude - $longitude_delta;
		$east = $longitude + $longitude_delta;
		$sql = $this->wpdb->prepare( "SELECT point_id,name,type,full_address,latitude,longitude,schedule,is_active,is_bulky,min_weight_g,max_weight_g,max_width_mm,max_length_mm,max_height_mm FROM {$this->points_table()} WHERE generation_id=%d AND is_active=1 AND latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN %f AND %f AND longitude BETWEEN %f AND %f", $active['id'], $south, $north, $west, $east );
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,mixed> */
	public function status(): array {
		$row = $this->wpdb->get_row( "SELECT * FROM {$this->generations_table()} WHERE state IN ('building','ready','active','failed','cancelled') ORDER BY id DESC LIMIT 1", ARRAY_A );
		$active = $this->active_generation();
		$state = is_array( $row ) ? (string) $row['state'] : 'idle';
		$generation_id = is_array( $row ) ? (int) $row['id'] : 0;
		$discovered = (int) ( $row['discovered_count'] ?? $row['downloaded_count'] ?? 0 );
		$processed = (int) ( $row['enrichment_processed_count'] ?? 0 );
		$pending = $generation_id > 0 && 'building' === $state && 'enrichment' === (string) ( $row['phase'] ?? '' ) ? $this->pending_count( $generation_id ) : max( 0, $discovered - $processed );

		return array(
			'generation_id'               => is_array( $row ) ? (int) $row['id'] : null,
			'state'                       => $state,
			'phase'                       => 'building' === $state ? (string) ( $row['phase'] ?? 'discovery' ) : '',
			'started_at'                  => $row['started_at'] ?? null,
			'completed_at'                => $row['completed_at'] ?? null,
			'progress_updated_at'         => $row['progress_updated_at'] ?? null,
			'page_count'                  => (int) ( $row['page_count'] ?? 0 ),
			'downloaded_count'            => (int) ( $row['downloaded_count'] ?? 0 ),
			'discovery_page_count'        => (int) ( $row['discovery_page_count'] ?? $row['page_count'] ?? 0 ),
			'discovered_count'            => $discovered,
			'discovery_completed_at'      => $row['discovery_completed_at'] ?? null,
			'enrichment_processed_count'  => $processed,
			'pending_count'               => $pending,
			'accepted_count'              => (int) ( $row['accepted_count'] ?? 0 ),
			'rejected_count'              => (int) ( $row['rejected_count'] ?? 0 ),
			'duplicate_count'             => (int) ( $row['duplicate_count'] ?? 0 ),
			'conflict_count'              => (int) ( $row['conflict_count'] ?? 0 ),
			'retry_count'                 => (int) ( $row['retry_count'] ?? 0 ),
			'safe_error_code'             => (string) ( $row['safe_error_code'] ?? '' ),
			'safe_error_message'          => (string) ( $row['safe_error_message'] ?? '' ),
			'safe_error_operation'        => (string) ( $row['safe_error_operation'] ?? '' ),
			'safe_error_http_status'      => (int) ( $row['safe_error_http_status'] ?? 0 ),
			'safe_error_retryable'        => isset( $row['safe_error_retryable'] ) ? (bool) $row['safe_error_retryable'] : null,
			'failed_page'                 => (int) ( $row['failed_page'] ?? 0 ),
			'failed_cursor'               => (string) ( $row['failed_cursor'] ?? '' ),
			'failed_after_ids'            => (int) ( $row['failed_after_ids'] ?? 0 ),
			'failed_attempt'              => (int) ( $row['failed_attempt'] ?? 0 ),
			'failed_at'                   => $row['failed_at'] ?? null,
			'active_generation_id'        => is_array( $active ) ? (int) $active['id'] : null,
			'active_count'                => $this->active_count(),
			'is_current_active'           => is_array( $row ) && is_array( $active ) && (int) $row['id'] === (int) $active['id'],
			'is_running'                  => 'building' === $state,
			'is_terminal'                 => in_array( $state, array( 'active', 'failed', 'cancelled', 'idle' ), true ),
			'active_completed_at'         => $active['completed_at'] ?? null,
		);
	}

	/** @param array<string,mixed> $diagnostics @return array<string,mixed> */
	private function diagnostic_patch( array $diagnostics ): array {
		$cursor = is_scalar( $diagnostics['failed_cursor'] ?? null ) ? (string) $diagnostics['failed_cursor'] : '';
		return array(
			'safe_error_operation'   => substr( is_scalar( $diagnostics['operation'] ?? null ) ? preg_replace( '/[^A-Za-z0-9_\/.-]/', '', (string) $diagnostics['operation'] ) ?? '' : '', 0, 120 ),
			'safe_error_http_status' => max( 0, min( 599, (int) ( $diagnostics['http_status'] ?? 0 ) ) ),
			'safe_error_retryable'   => ! empty( $diagnostics['retryable'] ) ? 1 : 0,
			'failed_page'            => max( 0, (int) ( $diagnostics['failed_page'] ?? 0 ) ),
			'failed_cursor'          => substr( preg_replace( '/[\x00-\x1F\x7F]+/u', '', $cursor ) ?? '', 0, 255 ),
			'failed_after_ids'       => max( 0, (int) ( $diagnostics['failed_after_ids'] ?? 0 ) ),
			'failed_attempt'         => max( 0, (int) ( $diagnostics['failed_attempt'] ?? 0 ) ),
			'failed_at'              => current_time( 'mysql', true ),
		);
	}

	/** @param array<string,mixed> $patch @return array<string,mixed> */
	private function clear_diagnostics( array $patch ): array {
		return array_merge(
			$patch,
			array(
				'safe_error_code'        => null,
				'safe_error_message'     => null,
				'safe_error_operation'   => null,
				'safe_error_http_status' => null,
				'safe_error_retryable'   => null,
				'failed_page'            => null,
				'failed_cursor'          => null,
				'failed_after_ids'       => null,
				'failed_attempt'         => null,
				'failed_at'              => null,
			)
		);
	}

	/** @param array<int,int> $ids */
	private function insert_discovery_ids( int $generation_id, array $ids, string $now ): bool {
		if ( array() === $ids ) { return true; }
		$values = array();
		$args = array();
		foreach ( $ids as $point_id ) {
			$values[] = '(%d,%d,%s,%s,%s)';
			array_push( $args, $generation_id, (int) $point_id, 'pending', $now, $now );
		}
		$sql = $this->wpdb->prepare( "INSERT IGNORE INTO {$this->ids_table()} (generation_id,point_id,status,created_at,updated_at) VALUES " . implode( ',', $values ), ...$args );
		return false !== $this->wpdb->query( $sql );
	}

	/** @param array<int,array<string,mixed>> $points */
	private function insert_points_bulk( int $generation_id, array $points ): bool {
		foreach ( array_chunk( $points, self::ENRICHMENT_INSERT_CHUNK_SIZE ) as $chunk ) {
			$values = array();
			$args = array();
			foreach ( $chunk as $row ) {
				$values[] = '(%d,%d,%s,%s,%s,%s,' . ( null === ( $row['latitude'] ?? null ) ? 'NULL' : '%f' ) . ',' . ( null === ( $row['longitude'] ?? null ) ? 'NULL' : '%f' ) . ',%s,%d,%d,' . $this->nullable_int_placeholder( $row['storage_period_days'] ?? null ) . ',' . $this->nullable_int_placeholder( $row['fitting_rooms_count'] ?? null ) . ',' . $this->nullable_int_placeholder( $row['min_weight_g'] ?? null ) . ',' . $this->nullable_int_placeholder( $row['max_weight_g'] ?? null ) . ',' . $this->nullable_int_placeholder( $row['max_width_mm'] ?? null ) . ',' . $this->nullable_int_placeholder( $row['max_length_mm'] ?? null ) . ',' . $this->nullable_int_placeholder( $row['max_height_mm'] ?? null ) . ',%s)';
				array_push( $args, $generation_id, (int) $row['point_id'], (string) ( $row['name'] ?? '' ), (string) ( $row['point_number'] ?? '' ), (string) ( $row['type'] ?? '' ), (string) ( $row['full_address'] ?? '' ) );
				foreach ( array( 'latitude', 'longitude' ) as $field ) { if ( null !== ( $row[ $field ] ?? null ) ) { $args[] = (float) $row[ $field ]; } }
				array_push( $args, (string) ( $row['schedule'] ?? '' ), (int) ( $row['is_active'] ?? 0 ), (int) ( $row['is_bulky'] ?? 0 ) );
				foreach ( array( 'storage_period_days', 'fitting_rooms_count', 'min_weight_g', 'max_weight_g', 'max_width_mm', 'max_length_mm', 'max_height_mm' ) as $field ) { if ( null !== ( $row[ $field ] ?? null ) ) { $args[] = (int) $row[ $field ]; } }
				$args[] = (string) ( $row['fingerprint'] ?? '' );
			}
			$sql = $this->wpdb->prepare( "INSERT INTO {$this->points_table()} (generation_id,point_id,name,point_number,type,full_address,latitude,longitude,schedule,is_active,is_bulky,storage_period_days,fitting_rooms_count,min_weight_g,max_weight_g,max_width_mm,max_length_mm,max_height_mm,fingerprint) VALUES " . implode( ',', $values ), ...$args );
			$inserted = $this->wpdb->query( $sql );
			if ( false === $inserted || count( $chunk ) !== (int) $inserted ) { return false; }
		}
		return true;
	}

	private function nullable_int_placeholder( mixed $value ): string { return null === $value ? 'NULL' : '%d'; }

	/** @param array<int,int> $ids */
	private function mark_ids_enriched( int $generation_id, array $ids ): bool {
		if ( array() === $ids ) { return true; }
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args = array_merge( array( current_time( 'mysql', true ), $generation_id ), $ids );
		$sql = $this->wpdb->prepare( "UPDATE {$this->ids_table()} SET status='enriched',reject_code=NULL,updated_at=%s WHERE generation_id=%d AND status='pending' AND point_id IN ({$placeholders})", ...$args );
		return count( $ids ) === (int) $this->wpdb->query( $sql );
	}

	/** @param array<int,string> $rejects */
	private function mark_ids_rejected( int $generation_id, array $rejects ): bool {
		if ( array() === $rejects ) { return true; }
		$cases = array();
		$args = array();
		foreach ( $rejects as $point_id => $code ) { $cases[] = 'WHEN %d THEN %s'; array_push( $args, $point_id, $code ); }
		$args[] = current_time( 'mysql', true );
		$args[] = $generation_id;
		$ids = array_keys( $rejects );
		array_push( $args, ...$ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = $this->wpdb->prepare( "UPDATE {$this->ids_table()} SET status='rejected',reject_code=CASE point_id " . implode( ' ', $cases ) . " ELSE reject_code END,updated_at=%s WHERE generation_id=%d AND status='pending' AND point_id IN ({$placeholders})", ...$args );
		return count( $rejects ) === (int) $this->wpdb->query( $sql );
	}
}

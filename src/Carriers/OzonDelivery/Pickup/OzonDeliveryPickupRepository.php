<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryPickupRepository {
	private object $wpdb;
	public function __construct( ?object $wpdb = null ) { if ( null === $wpdb ) { global $wpdb; } $this->wpdb = $wpdb; }
	public function generations_table(): string { return $this->wpdb->prefix . 'wdc_ozon_delivery_pickup_generations'; }
	public function points_table(): string { return $this->wpdb->prefix . 'wdc_ozon_delivery_pickup_points'; }
	public function start( string $job_id ): ?int { $now = current_time( 'mysql', true ); if ( false === $this->wpdb->insert( $this->generations_table(), array( 'state' => 'building', 'job_id' => $job_id, 'started_at' => $now, 'progress_updated_at' => $now ) ) ) { return null; } $id = (int) $this->wpdb->insert_id; return $id > 0 ? $id : null; }
	/** @return array<string,mixed>|null */ public function generation_by_job( string $job_id ): ?array { $row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->generations_table()} WHERE job_id=%s", $job_id ), ARRAY_A ); return is_array( $row ) ? $row : null; }
	/** @return array<string,mixed>|null */ public function active_generation(): ?array { $row = $this->wpdb->get_row( "SELECT * FROM {$this->generations_table()} WHERE state='active' ORDER BY id DESC LIMIT 1", ARRAY_A ); return is_array( $row ) ? $row : null; }
	public function active_count(): int { $active = $this->active_generation(); return $active ? (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->points_table()} WHERE generation_id=%d", $active['id'] ) ) : 0; }
	/** @param array<string,mixed> $row */ public function insert_point( int $generation_id, array $row ): bool { $row['generation_id'] = $generation_id; return false !== $this->wpdb->insert( $this->points_table(), $row ); }
	/** @return array<string,mixed>|null */ public function find_in_generation( int $generation_id, int $point_id ): ?array { $row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT point_id,fingerprint FROM {$this->points_table()} WHERE generation_id=%d AND point_id=%d", $generation_id, $point_id ), ARRAY_A ); return is_array( $row ) ? $row : null; }
	/** @param array<string,mixed> $patch */ public function update_generation( int $id, array $patch ): void { $patch['progress_updated_at'] = current_time( 'mysql', true ); $this->wpdb->update( $this->generations_table(), $patch, array( 'id' => $id ) ); }
	/** @param array<string,mixed> $diagnostics */
	public function record_retry( int $id, int $retry_count, array $diagnostics ): void { $this->update_generation( $id, array_merge( $this->diagnostic_patch( $diagnostics ), array( 'retry_count' => max( 0, $retry_count ), 'safe_error_code' => substr( (string) ( $diagnostics['code'] ?? '' ), 0, 80 ), 'safe_error_message' => substr( (string) ( $diagnostics['message'] ?? 'Временная ошибка Ozon Delivery.' ), 0, 300 ) ) ) ); }
	/** @param array<string,mixed> $diagnostics */
	public function fail( int $id, string $code, string $message, array $diagnostics = array() ): void { $this->update_generation( $id, array_merge( $this->diagnostic_patch( $diagnostics ), array( 'state' => 'failed', 'completed_at' => current_time( 'mysql', true ), 'failed_at' => current_time( 'mysql', true ), 'safe_error_code' => substr( $code, 0, 80 ), 'safe_error_message' => substr( $message, 0, 300 ) ) ) ); $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->points_table()} WHERE generation_id=%d", $id ) ); }
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
				return $this->rollback_activation();
			}

			$activated = $this->wpdb->query( $this->wpdb->prepare( "UPDATE {$this->generations_table()} SET state='active', completed_at=%s WHERE id=%d AND state='ready'", current_time( 'mysql', true ), $id ) );
			if ( false === $activated || 1 !== (int) $activated ) {
				return $this->rollback_activation();
			}

			$active_count = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->generations_table()} WHERE state='active'" );
			$active_id    = (int) $this->wpdb->get_var( "SELECT id FROM {$this->generations_table()} WHERE state='active' ORDER BY id DESC LIMIT 1" );
			if ( 1 !== $active_count || $id !== $active_id ) {
				return $this->rollback_activation();
			}

			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				return $this->rollback_activation();
			}
			$this->cleanup_obsolete_points( $id );
			return true;
		} catch ( \Throwable ) {
			return $this->rollback_activation();
		}
	}
	public function cleanup_obsolete_points( int $active_generation_id ): bool { return false !== $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->points_table()} WHERE generation_id <> %d", $active_generation_id ) ); }
	private function rollback_activation(): bool { $this->wpdb->query( 'ROLLBACK' ); return false; }
	/** @return array<string,mixed>|null */ public function find_active( int $point_id ): ?array { $active = $this->active_generation(); if ( ! $active ) { return null; } $row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->points_table()} WHERE generation_id=%d AND point_id=%d", $active['id'], $point_id ), ARRAY_A ); return is_array( $row ) ? $row : null; }
	/** @return array<int,array<string,mixed>> */ public function find_active_in_area( float $latitude, float $longitude, float $latitude_delta, float $longitude_delta ): array { $active = $this->active_generation(); if ( ! $active ) { return array(); } $south = $latitude - $latitude_delta; $north = $latitude + $latitude_delta; $west = $longitude - $longitude_delta; $east = $longitude + $longitude_delta; $sql = $this->wpdb->prepare( "SELECT point_id,name,type,full_address,latitude,longitude,schedule,is_active,is_bulky,min_weight_g,max_weight_g,max_width_mm,max_length_mm,max_height_mm FROM {$this->points_table()} WHERE generation_id=%d AND is_active=1 AND latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN %f AND %f AND longitude BETWEEN %f AND %f", $active['id'], $south, $north, $west, $east ); $rows = $this->wpdb->get_results( $sql, ARRAY_A ); return is_array( $rows ) ? $rows : array(); }
	/** @return array<string,mixed> */ public function status(): array { $row = $this->wpdb->get_row( "SELECT * FROM {$this->generations_table()} WHERE state IN ('building','ready','active','failed') ORDER BY id DESC LIMIT 1", ARRAY_A ); $active = $this->active_generation(); $state = is_array( $row ) ? (string) $row['state'] : 'idle'; return array( 'generation_id' => is_array( $row ) ? (int) $row['id'] : null, 'state' => $state, 'started_at' => $row['started_at'] ?? null, 'completed_at' => $row['completed_at'] ?? null, 'progress_updated_at' => $row['progress_updated_at'] ?? null, 'page_count' => (int) ( $row['page_count'] ?? 0 ), 'downloaded_count' => (int) ( $row['downloaded_count'] ?? 0 ), 'accepted_count' => (int) ( $row['accepted_count'] ?? 0 ), 'rejected_count' => (int) ( $row['rejected_count'] ?? 0 ), 'duplicate_count' => (int) ( $row['duplicate_count'] ?? 0 ), 'conflict_count' => (int) ( $row['conflict_count'] ?? 0 ), 'retry_count' => (int) ( $row['retry_count'] ?? 0 ), 'safe_error_code' => (string) ( $row['safe_error_code'] ?? '' ), 'safe_error_message' => (string) ( $row['safe_error_message'] ?? '' ), 'safe_error_operation' => (string) ( $row['safe_error_operation'] ?? '' ), 'safe_error_http_status' => (int) ( $row['safe_error_http_status'] ?? 0 ), 'safe_error_retryable' => isset( $row['safe_error_retryable'] ) ? (bool) $row['safe_error_retryable'] : null, 'failed_page' => (int) ( $row['failed_page'] ?? 0 ), 'failed_cursor' => (string) ( $row['failed_cursor'] ?? '' ), 'failed_after_ids' => (int) ( $row['failed_after_ids'] ?? 0 ), 'failed_attempt' => (int) ( $row['failed_attempt'] ?? 0 ), 'failed_at' => $row['failed_at'] ?? null, 'active_generation_id' => is_array( $active ) ? (int) $active['id'] : null, 'active_count' => $this->active_count(), 'is_current_active' => is_array( $row ) && is_array( $active ) && (int) $row['id'] === (int) $active['id'], 'is_running' => 'building' === $state, 'is_terminal' => in_array( $state, array( 'active','failed','idle' ), true ), 'active_completed_at' => $active['completed_at'] ?? null ); }
	/** @param array<string,mixed> $diagnostics @return array<string,mixed> */
	private function diagnostic_patch( array $diagnostics ): array {
		$cursor = is_scalar( $diagnostics['failed_cursor'] ?? null ) ? (string) $diagnostics['failed_cursor'] : '';
		return array(
			'safe_error_operation' => substr( is_scalar( $diagnostics['operation'] ?? null ) ? preg_replace( '/[^A-Za-z0-9_\/.-]/', '', (string) $diagnostics['operation'] ) ?? '' : '', 0, 120 ),
			'safe_error_http_status' => max( 0, min( 599, (int) ( $diagnostics['http_status'] ?? 0 ) ) ),
			'safe_error_retryable' => ! empty( $diagnostics['retryable'] ) ? 1 : 0,
			'failed_page' => max( 0, (int) ( $diagnostics['failed_page'] ?? 0 ) ),
			'failed_cursor' => substr( preg_replace( '/[\x00-\x1F\x7F]+/u', '', $cursor ) ?? '', 0, 255 ),
			'failed_after_ids' => max( 0, (int) ( $diagnostics['failed_after_ids'] ?? 0 ) ),
			'failed_attempt' => max( 0, (int) ( $diagnostics['failed_attempt'] ?? 0 ) ),
			'failed_at' => current_time( 'mysql', true ),
		);
	}
}

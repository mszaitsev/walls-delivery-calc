<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Gar;

use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class GarSyncManager {
	public const DAILY_HOOK = 'wdc_gar_daily_check';
	private const LAST_CHECK_OPTION = 'wdc_gar_changes_last_check_at';
	private const PENDING_OPTION = 'wdc_gar_changes_pending';
	private const LAST_STATUS_OPTION = 'wdc_gar_changes_last_status';

	private \wpdb $wpdb;

	public function __construct(
		private ActionScheduler $scheduler,
		private GarChangesClient $client,
		private Logger $logger,
		private ?SettingsRepository $settings = null,
		?\wpdb $db = null
	) {
		global $wpdb;
		$this->wpdb = $db ?? $wpdb;
	}

	public function register(): void {
		add_action( self::DAILY_HOOK, array( $this, 'check_for_changes' ) );

		if ( ! $this->scheduler->has_scheduled( self::DAILY_HOOK ) ) {
			$this->scheduler->schedule_recurring( time() + $this->hour(), $this->day(), self::DAILY_HOOK );
		}
	}

	public function check_for_changes(): array {
		if ( ! $this->settings instanceof SettingsRepository || ! $this->settings->get_bool( 'gar_sync_enabled', false ) ) {
			$status = array(
				'ok'            => true,
				'pending'       => false,
				'disabled'      => true,
				'last_check_at' => current_time( 'mysql' ),
				'message'       => 'GAR runtime requests are disabled until API methods are verified.',
			);
			update_option( self::LAST_STATUS_OPTION, $status, false );
			update_option( self::PENDING_OPTION, false, false );

			return $status;
		}

		$response = $this->client->request_changes();
		$now      = current_time( 'mysql' );
		update_option( self::LAST_CHECK_OPTION, $now, false );

		if ( empty( $response['success'] ) ) {
			$status = array(
				'ok'            => false,
				'pending'       => false,
				'last_check_at' => $now,
				'error'         => (string) ( $response['error_message'] ?? 'GAR request failed.' ),
			);
			update_option( self::LAST_STATUS_OPTION, $status, false );
			update_option( self::PENDING_OPTION, false, false );
			$this->logger->warning( 'GAR changes check failed.', array( 'error' => $status['error'] ) );

			return $status;
		}

		$payload = $response['body'] ?? null;
		$pending = is_array( $payload ) && array() !== $payload;
		$task_id = $this->task_id_from_payload( $payload );
		$this->store_detection( $task_id, $pending ? 'pending' : 'empty', $payload );

		$status = array(
			'ok'            => true,
			'pending'       => $pending,
			'last_check_at' => $now,
			'task_id'       => $task_id,
		);
		update_option( self::PENDING_OPTION, $pending, false );
		update_option( self::LAST_STATUS_OPTION, $status, false );

		return $status;
	}

	public function status(): array {
		$status = get_option( self::LAST_STATUS_OPTION, array() );
		return is_array( $status ) ? $status : array();
	}

	private function store_detection( string $task_id, string $status, mixed $payload ): void {
		$now = current_time( 'mysql' );
		$this->wpdb->insert(
			$this->table_name(),
			array(
				'task_id'      => $task_id,
				'status'       => $status,
				'requested_at' => $now,
				'completed_at' => 'empty' === $status ? $now : null,
				'payload'      => wp_json_encode( $payload ),
				'applied'      => 0,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	private function task_id_from_payload( mixed $payload ): string {
		if ( is_array( $payload ) ) {
			foreach ( array( 'task_id', 'taskId', 'id' ) as $key ) {
				if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) ) {
					return (string) $payload[ $key ];
				}
			}
		}

		return sha1( wp_json_encode( $payload ) ?: uniqid( 'gar_', true ) );
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_gar_changes';
	}

	private function hour(): int {
		return defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
	}

	private function day(): int {
		return defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
	}
}

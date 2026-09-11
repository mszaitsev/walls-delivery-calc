<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

defined( 'ABSPATH' ) || exit;

/** Temporary bounded forensic journal for the Russian Post pickup import lock. */
final class RussianPostPickupImportLockAudit {
	public const OPTION_NAME = 'wdc_russian_post_pickup_import_lock_audit';
	private const MAX_EVENTS = 40;

	/**
	 * @param array<string,mixed> $context
	 */
	public function record( string $event, string $requested_job_id, array $context = array() ): void {
		$entry = array(
			'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'microtime' => sprintf( '%.6f', microtime( true ) ),
			'event' => $this->safe_label( $event ),
			'requested_job_id' => trim( $requested_job_id ),
			'current_lock_job_id_before' => trim( (string) ( $context['current_lock_job_id_before'] ?? '' ) ),
			'current_lock_job_id_after' => trim( (string) ( $context['current_lock_job_id_after'] ?? '' ) ),
			'success' => is_bool( $context['success'] ?? null ) ? $context['success'] : null,
			'reason' => $this->safe_label( (string) ( $context['reason'] ?? '' ) ),
			'php_sapi' => PHP_SAPI,
			'doing_cron' => defined( 'DOING_CRON' ) && DOING_CRON,
			'doing_ajax' => function_exists( 'wp_doing_ajax' ) && wp_doing_ajax(),
			'current_action' => function_exists( 'current_action' ) ? $this->safe_label( (string) current_action() ) : '',
			'request_path' => $this->request_path(),
			'request_method' => $this->request_method(),
			'wdc_frames' => $this->wdc_frames(),
		);
		$this->append( $entry );
	}

	/** @return array<int,array<string,mixed>> */
	public function events(): array {
		$events = get_option( self::OPTION_NAME, array() );

		return is_array( $events ) ? array_values( array_slice( $events, -self::MAX_EVENTS ) ) : array();
	}

	/** @param array<string,mixed> $entry */
	private function append( array $entry ): void {
		global $wpdb;
		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			$current = get_option( self::OPTION_NAME, null );
			$events = is_array( $current ) ? array_values( $current ) : array();
			$events[] = $entry;
			$events = array_slice( $events, -self::MAX_EVENTS );
			if ( null === $current ) {
				if ( add_option( self::OPTION_NAME, $events, '', 'no' ) ) {
					return;
				}
				$this->clear_option_cache();
				continue;
			}
			if ( ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
				update_option( self::OPTION_NAME, $events, false );
				return;
			}

			$serialize = static fn( mixed $value ): string => (string) ( function_exists( 'maybe_serialize' ) ? maybe_serialize( $value ) : serialize( $value ) );
			$sql = $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s LIMIT 1",
				$serialize( $events ),
				self::OPTION_NAME,
				$serialize( $current )
			);
			$result = $wpdb->query( $sql );
			if ( false === $result ) {
				return;
			}
			$this->clear_option_cache();
			if ( 1 === (int) $result ) {
				return;
			}
		}
	}

	private function clear_option_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_NAME, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	private function safe_label( string $value ): string {
		$value = preg_replace( '/[^A-Za-z0-9_.:\/-]/', '_', trim( $value ) ) ?? '';

		return substr( $value, 0, 120 );
	}

	private function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = '' !== $uri ? parse_url( $uri, PHP_URL_PATH ) : '';

		return substr( is_string( $path ) ? $path : '', 0, 240 );
	}

	private function request_method(): string {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';

		return substr( preg_replace( '/[^A-Z]/', '', $method ) ?? '', 0, 12 );
	}

	/** @return array<int,string> */
	private function wdc_frames(): array {
		$frames = array();
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 ) as $frame ) {
			$class = (string) ( $frame['class'] ?? '' );
			$file = str_replace( '\\', '/', (string) ( $frame['file'] ?? '' ) );
			if ( ! str_starts_with( $class, 'WallsShop\\WDC\\' ) && ! str_contains( $file, '/walls-delivery-calc/' ) ) {
				continue;
			}
			$call = '' !== $class ? $class . (string) ( $frame['type'] ?? '::' ) . (string) ( $frame['function'] ?? '' ) : (string) ( $frame['function'] ?? '' );
			$frames[] = substr( $call, 0, 180 );
			if ( 5 <= count( $frames ) ) {
				break;
			}
		}

		return $frames;
	}
}

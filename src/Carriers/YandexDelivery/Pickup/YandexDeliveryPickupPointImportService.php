<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pickup;

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryPickupPointImportService {
	private const LOCK_OPTION = 'wdc_yandex_delivery_pickup_import_lock';
	private const STATE_OPTION = 'wdc_yandex_delivery_pickup_import_state';
	private const LOCK_TTL = 30 * 60;
	private const MAX_PAGES = 500;
	private const PAGE_SIZE = 100;

	public function __construct(
		private YandexDeliveryApiClient $api,
		private YandexDeliveryPickupPointNormalizer $normalizer,
		private YandexDeliveryPickupPointRepository $repository,
		private YandexDeliverySettings $settings,
		private ?Logger $logger = null
	) {
	}

	/** @return array<string,mixed> */
	public function start_import( string $context = 'manual_ajax' ): array {
		$page_size = $this->settings->pickup_import_page_size();
		$token = $this->acquire_lock();
		if ( '' === $token ) {
			$state = $this->current_import_state();
			if ( array() === $state || 'idle' === (string) ( $state['status'] ?? '' ) ) {
				$state = $this->base_state( 'stale_lock', $page_size, $context );
				$state['message'] = 'Yandex Delivery pickup import skipped: another import is already running.';
			}

			return $state;
		}

		$started = $this->now();
		$state = $this->base_state( 'running', $page_size, $context );
		$state['session_id'] = $this->new_session_id();
		$state['started_at'] = $started;
		$state['updated_at'] = $started;
		$state['message'] = 'Yandex Delivery pickup import started.';
		$state['inactive'] = $this->repository->mark_all_inactive();
		$state['lock_token'] = $token;
		$this->save_state( $state );

		return $this->public_state( $state );
	}

	/** @return array<string,mixed> */
	public function run_import_step( string $session_id ): array {
		$state = $this->raw_state();
		if ( array() === $state || 'running' !== (string) ( $state['status'] ?? '' ) ) {
			return $this->public_state( $state ?: $this->idle_state() );
		}
		if ( $session_id !== (string) ( $state['session_id'] ?? '' ) ) {
			$error = $this->public_state( $state );
			$error['status'] = 'error';
			$error['message'] = 'Yandex Delivery pickup import session mismatch.';
			$error['errors'] = $this->limited_errors( array_merge( is_array( $error['errors'] ?? null ) ? $error['errors'] : array(), array( 'Yandex Delivery pickup import session mismatch.' ) ) );
			return $error;
		}
		if ( ! $this->lock_is_active( (string) ( $state['lock_token'] ?? '' ) ) ) {
			$state['status'] = 'stale_lock';
			$state['updated_at'] = $this->now();
			$state['message'] = 'Yandex Delivery pickup import lock is stale. Reset and start again.';
			$this->save_state( $state );
			return $this->public_state( $state );
		}

		try {
			if ( (int) ( $state['page'] ?? 0 ) >= self::MAX_PAGES ) {
				throw new \RuntimeException( 'Yandex Delivery pickup import stopped: max pages reached.' );
			}
			$response = $this->api->pickupPointsList( $this->page_payload( (string) ( $state['next_page_token'] ?? '' ), (int) ( $state['page_size'] ?? self::PAGE_SIZE ) ) );
			$body = is_array( $response['body'] ?? null ) ? $response['body'] : array();
			$normalized = $this->normalizer->normalize_response( $body );
			$page_points = is_array( $normalized['points'] ) ? $normalized['points'] : array();
			$state['page'] = (int) ( $state['page'] ?? 0 ) + 1;
			$state['fetched'] = (int) ( $state['fetched'] ?? 0 ) + (int) $normalized['fetched_count'];
			$state['normalized'] = (int) ( $state['normalized'] ?? 0 ) + count( $page_points );
			$skipped = (int) $normalized['skipped_invalid'];
			if ( array() !== $page_points ) {
				$save = $this->repository->save_batch( $page_points, (string) ( $state['started_at'] ?? $this->now() ) );
				$state['saved'] = (int) ( $state['saved'] ?? 0 ) + (int) $save['saved'];
				$skipped += (int) $save['skipped_invalid'];
			}
			if ( $skipped > 0 ) {
				$state['errors'] = $this->limited_errors( array_merge( is_array( $state['errors'] ?? null ) ? $state['errors'] : array(), array( 'Yandex Delivery pickup import skipped invalid rows: ' . $skipped ) ) );
			}
			$state['next_page_token'] = $this->next_page_token( $body );
			$state['memory_peak_mb'] = $this->memory_peak_mb();
			$state['updated_at'] = $this->now();
			$state['message'] = sprintf( 'Step %d: fetched=%d saved=%d.', (int) $state['page'], (int) $state['fetched'], (int) $state['saved'] );
			unset( $response, $body, $normalized, $page_points, $save );

			if ( '' === (string) $state['next_page_token'] ) {
				if ( 0 === (int) ( $state['fetched'] ?? 0 ) ) {
					throw new \RuntimeException( 'Yandex Delivery pickup import returned no rows.' );
				}
				if ( 0 === (int) ( $state['normalized'] ?? 0 ) ) {
					throw new \RuntimeException( 'Yandex Delivery pickup import returned rows, but no valid points were normalized.' );
				}
				if ( 0 === (int) ( $state['saved'] ?? 0 ) ) {
					throw new \RuntimeException( 'Yandex Delivery pickup import normalized rows, but no points were saved.' );
				}
				$this->repository->activate_imported_points( (string) ( $state['started_at'] ?? $this->now() ) );
				$state['status'] = 'success';
				$state['finished_at'] = $this->now();
				$state['updated_at'] = $state['finished_at'];
				$state['message'] = 'Yandex Delivery pickup points imported.';
				$this->settings->save_pickup_import_report( $this->report_from_state( $state ) );
				$this->release_lock( (string) ( $state['lock_token'] ?? '' ) );
			}
		} catch ( YandexDeliveryApiException $exception ) {
			$state = $this->fail_state( $state, $exception->getMessage() );
		} catch ( \Throwable $exception ) {
			$state = $this->fail_state( $state, 'Yandex Delivery pickup import failed: ' . $exception->getMessage() );
		}

		$this->save_state( $state );

		return $this->public_state( $state );
	}

	/** @return array<string,mixed> */
	public function reset_import(): array {
		$state = $this->raw_state();
		if ( '' !== (string) ( $state['lock_token'] ?? '' ) ) {
			$this->release_lock( (string) $state['lock_token'] );
		}
		$this->reset_lock();
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::STATE_OPTION );
		}
		$this->settings->clear_pickup_action_result();
		$this->settings->save_pickup_import_report( array() );

		return $this->idle_state( 'Yandex Delivery pickup import reset.' );
	}

	/** @return array<string,mixed> */
	public function current_import_state(): array {
		$state = $this->raw_state();
		if ( array() === $state ) {
			return $this->idle_state();
		}
		if ( 'running' === (string) ( $state['status'] ?? '' ) && ! $this->lock_is_active( (string) ( $state['lock_token'] ?? '' ) ) ) {
			$state['status'] = 'stale_lock';
			$state['updated_at'] = $this->now();
			$state['message'] = 'Yandex Delivery pickup import lock is stale. Reset and start again.';
			$this->save_state( $state );
		}

		return $this->public_state( $state );
	}

	/** @return array<string,mixed> */
	public function import_all( string $context = 'manual' ): array {
		$state = $this->start_import( $context );
		if ( 'running' !== (string) ( $state['status'] ?? '' ) ) {
			return $this->report_from_state( $state );
		}
		$session_id = (string) $state['session_id'];
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$state = $this->run_import_step( $session_id );
			if ( 'running' !== (string) ( $state['status'] ?? '' ) ) {
				break;
			}
		}

		return $this->report_from_state( $state );
	}

	public function reset_lock(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/** @return array<string,mixed> */
	private function base_state( string $status, int $page_size, string $context ): array {
		$now = $this->now();
		return array(
			'session_id' => '',
			'status' => $status,
			'started_at' => '',
			'updated_at' => $now,
			'finished_at' => '',
			'page_size' => $page_size,
			'page' => 0,
			'next_page_token' => '',
			'fetched' => 0,
			'normalized' => 0,
			'saved' => 0,
			'inactive' => 0,
			'errors' => array(),
			'message' => '',
			'memory_peak_mb' => $this->memory_peak_mb(),
			'context' => $this->sanitize_context( $context ),
			'lock_token' => '',
		);
	}

	/** @return array<string,mixed> */
	private function idle_state( string $message = '' ): array {
		$state = $this->base_state( 'idle', $this->settings->pickup_import_page_size(), 'manual_ajax' );
		$state['message'] = $message;

		return $this->public_state( $state );
	}

	/** @return array<string,mixed> */
	private function raw_state(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/** @param array<string,mixed> $state */
	private function save_state( array $state ): void {
		$state['errors'] = $this->limited_errors( is_array( $state['errors'] ?? null ) ? $state['errors'] : array() );
		if ( function_exists( 'update_option' ) ) {
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function public_state( array $state ): array {
		unset( $state['lock_token'] );
		$state['errors'] = $this->limited_errors( is_array( $state['errors'] ?? null ) ? $state['errors'] : array() );

		return $state;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function fail_state( array $state, string $message ): array {
		$state['status'] = 'error';
		$state['message'] = $this->safe_error( $message );
		$state['errors'] = $this->limited_errors( array_merge( is_array( $state['errors'] ?? null ) ? $state['errors'] : array(), array( $message ) ) );
		$state['finished_at'] = $this->now();
		$state['updated_at'] = $state['finished_at'];
		$state['memory_peak_mb'] = $this->memory_peak_mb();
		$this->release_lock( (string) ( $state['lock_token'] ?? '' ) );
		$this->settings->save_pickup_import_report( $this->report_from_state( $state ) );
		$this->log( 'warning', 'Yandex Delivery pickup import failed.', array( 'context' => (string) ( $state['context'] ?? '' ), 'errors' => $state['errors'] ) );

		return $state;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function report_from_state( array $state ): array {
		return array(
			'source' => 'pickup-points/list',
			'started_at' => (string) ( $state['started_at'] ?? '' ),
			'finished_at' => (string) ( $state['finished_at'] ?? '' ),
			'fetched' => (int) ( $state['fetched'] ?? 0 ),
			'normalized' => (int) ( $state['normalized'] ?? 0 ),
			'saved' => (int) ( $state['saved'] ?? 0 ),
			'inactive' => (int) ( $state['inactive'] ?? 0 ),
			'errors' => $this->limited_errors( is_array( $state['errors'] ?? null ) ? $state['errors'] : array() ),
			'duration' => $this->duration_seconds( (string) ( $state['started_at'] ?? '' ), (string) ( $state['finished_at'] ?? '' ) ),
			'page_size' => (int) ( $state['page_size'] ?? self::PAGE_SIZE ),
			'pages' => (int) ( $state['page'] ?? 0 ),
			'memory_peak_mb' => (float) ( $state['memory_peak_mb'] ?? 0.0 ),
			'message' => (string) ( $state['message'] ?? '' ),
			'context' => $this->sanitize_context( (string) ( $state['context'] ?? 'manual_ajax' ) ),
			'status' => (string) ( $state['status'] ?? 'idle' ),
		);
	}

	/** @return array<string,mixed> */
	private function page_payload( string $page_token, int $page_size ): array {
		$payload = array(
			'type' => YandexDeliveryPickupPointNormalizer::TYPE_PICKUP_POINT,
			'limit' => $page_size,
		);
		if ( '' !== $page_token ) {
			$payload['page_token'] = $page_token;
		}

		return $payload;
	}

	/** @param array<string,mixed> $body */
	private function next_page_token( array $body ): string {
		foreach ( array( 'next_page_token', 'nextPageToken', 'next_page', 'nextPage' ) as $key ) {
			if ( is_scalar( $body[ $key ] ?? null ) && '' !== trim( (string) $body[ $key ] ) ) {
				return trim( (string) $body[ $key ] );
			}
		}

		return '';
	}

	private function acquire_lock(): string {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'add_option' ) ) {
			return sha1( uniqid( 'yandex-delivery-pickup-import-', true ) );
		}
		$token = sha1( uniqid( 'yandex-delivery-pickup-import-', true ) );
		$existing = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $existing ) && (int) ( $existing['expires'] ?? 0 ) > time() ) {
			return '';
		}
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::LOCK_OPTION );
			delete_option( self::STATE_OPTION );
		}

		return add_option( self::LOCK_OPTION, array( 'token' => $token, 'expires' => time() + self::LOCK_TTL ), '', 'no' ) ? $token : '';
	}

	private function lock_is_active( string $token ): bool {
		if ( '' === $token || ! function_exists( 'get_option' ) ) {
			return true;
		}
		$existing = get_option( self::LOCK_OPTION, array() );

		return is_array( $existing ) && $token === (string) ( $existing['token'] ?? '' ) && (int) ( $existing['expires'] ?? 0 ) > time();
	}

	private function release_lock( string $token ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'delete_option' ) ) {
			return;
		}
		$existing = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $existing ) && $token === (string) ( $existing['token'] ?? '' ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/** @param array<int,mixed> $errors @return array<int,string> */
	private function limited_errors( array $errors ): array {
		$clean = array();
		foreach ( $errors as $error ) {
			if ( count( $clean ) >= 20 ) {
				break;
			}
			$clean[] = $this->safe_error( is_scalar( $error ) ? (string) $error : '' );
		}

		return array_values( array_filter( $clean, static fn( string $error ): bool => '' !== $error ) );
	}

	private function safe_error( string $error ): string {
		$error = trim( preg_replace( '/\s+/', ' ', $this->settings->redact( $error ) ) ?? $error );

		return substr( $error, 0, 500 );
	}

	private function memory_peak_mb(): float {
		return function_exists( 'memory_get_peak_usage' ) ? round( memory_get_peak_usage( true ) / 1048576, 1 ) : 0.0;
	}

	private function duration_seconds( string $started, string $finished ): float {
		$start = '' !== $started ? strtotime( $started ) : false;
		$finish = '' !== $finished ? strtotime( $finished ) : false;
		if ( false === $start || false === $finish || $finish < $start ) {
			return 0.0;
		}

		return (float) ( $finish - $start );
	}

	private function new_session_id(): string {
		return sha1( uniqid( 'yandex-delivery-pickup-session-', true ) );
	}

	/** @param array<string,mixed> $context */
	private function log( string $level, string $message, array $context ): void {
		if ( $this->logger instanceof Logger && method_exists( $this->logger, $level ) ) {
			$this->logger->{$level}( $message, $context );
		}
	}

	private function sanitize_context( string $context ): string {
		$context = preg_replace( '/[^A-Za-z0-9_\-]/', '', $context ) ?? '';

		return '' !== $context ? substr( $context, 0, 64 ) : 'manual_ajax';
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}

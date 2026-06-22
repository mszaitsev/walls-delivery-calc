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
	private const LOCK_TTL = 30 * 60;
	private const MAX_PAGES = 500;
	private const PAGE_SIZE = 1000;

	public function __construct(
		private YandexDeliveryApiClient $api,
		private YandexDeliveryPickupPointNormalizer $normalizer,
		private YandexDeliveryPickupPointRepository $repository,
		private YandexDeliverySettings $settings,
		private ?Logger $logger = null
	) {
	}

	/** @return array<string,mixed> */
	public function import_all( string $context = 'manual' ): array {
		$token = $this->acquire_lock();
		if ( '' === $token ) {
			$report = $this->report( 'skipped_lock_busy', $this->now(), $this->now(), 0, 0, 0, 0, array(), 'Yandex Delivery pickup points import skipped: another import is already running.', $context );
			$this->settings->save_pickup_import_report( $report );
			return $report;
		}

		$started = $this->now();
		$time_start = microtime( true );
		$fetched = 0;
		$normalized_count = 0;
		$saved = 0;
		$inactive = 0;
		$errors = array();

		try {
			$inactive = $this->repository->mark_all_inactive();
			$responses = $this->download_all_pages();
			$points = array();
			$skipped = 0;
			foreach ( $responses as $response ) {
				$normalized = $this->normalizer->normalize_response( $response );
				$fetched += (int) $normalized['fetched_count'];
				$skipped += (int) $normalized['skipped_invalid'];
				$points = array_merge( $points, $normalized['points'] );
			}
			$normalized_count = count( $points );
			if ( 0 === $fetched ) {
				$errors[] = 'Yandex Delivery pickup import returned no rows.';
			} elseif ( 0 === $normalized_count ) {
				$errors[] = 'Yandex Delivery pickup import returned rows, but no valid points were normalized.';
			} else {
				$save = $this->repository->save_batch( $points, $started );
				$saved = (int) $save['saved'];
				$errors = array_merge( $errors, $this->skipped_errors( $skipped + (int) $save['skipped_invalid'] ) );
				$this->repository->activate_imported_points( $started );
			}
		} catch ( YandexDeliveryApiException $exception ) {
			$errors[] = $exception->getMessage();
		} catch ( \Throwable $exception ) {
			$errors[] = 'Yandex Delivery pickup import failed: ' . $exception->getMessage();
		} finally {
			$this->release_lock( $token );
		}

		$finished = $this->now();
		$report = $this->report(
			array() === $errors ? 'success' : 'error',
			$started,
			$finished,
			$fetched,
			$normalized_count,
			$saved,
			$inactive,
			$errors,
			array() === $errors ? 'Yandex Delivery pickup points imported.' : 'Yandex Delivery pickup points import finished with errors.',
			$context,
			round( microtime( true ) - $time_start, 3 )
		);
		$this->settings->save_pickup_import_report( $report );
		if ( array() !== $errors ) {
			$this->log( 'warning', 'Yandex Delivery pickup import finished with errors.', array( 'context' => $context, 'errors' => $errors ) );
		}

		return $report;
	}

	/** @return array<int,array<string,mixed>> */
	private function download_all_pages(): array {
		$responses = array();
		$page_token = '';
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$payload = array(
				'type' => YandexDeliveryPickupPointNormalizer::TYPE_PICKUP_POINT,
				'limit' => self::PAGE_SIZE,
			);
			if ( '' !== $page_token ) {
				$payload['page_token'] = $page_token;
			}
			$response = $this->api->pickupPointsList( $payload );
			$body = is_array( $response['body'] ?? null ) ? $response['body'] : array();
			$responses[] = $body;
			$page_token = $this->next_page_token( $body );
			if ( '' === $page_token ) {
				break;
			}
		}

		return $responses;
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

	/** @return array<int,string> */
	private function skipped_errors( int $skipped ): array {
		return $skipped > 0 ? array( 'Yandex Delivery pickup import skipped invalid rows: ' . $skipped ) : array();
	}

	/**
	 * @param array<int,string> $errors
	 * @return array<string,mixed>
	 */
	private function report( string $status, string $started, string $finished, int $fetched, int $normalized, int $saved, int $inactive, array $errors, string $message, string $context, float $duration = 0.0 ): array {
		return array(
			'source' => 'pickup-points/list',
			'started_at' => $started,
			'finished_at' => $finished,
			'fetched' => $fetched,
			'normalized' => $normalized,
			'saved' => $saved,
			'inactive' => $inactive,
			'errors' => $errors,
			'duration' => $duration,
			'message' => $message,
			'context' => $this->sanitize_context( $context ),
			'status' => $status,
		);
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
		}

		return add_option( self::LOCK_OPTION, array( 'token' => $token, 'expires' => time() + self::LOCK_TTL ), '', 'no' ) ? $token : '';
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

	/** @param array<string,mixed> $context */
	private function log( string $level, string $message, array $context ): void {
		if ( $this->logger instanceof Logger && method_exists( $this->logger, $level ) ) {
			$this->logger->{$level}( $message, $context );
		}
	}

	private function sanitize_context( string $context ): string {
		$context = preg_replace( '/[^A-Za-z0-9_\-]/', '', $context ) ?? '';

		return '' !== $context ? substr( $context, 0, 64 ) : 'manual';
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}

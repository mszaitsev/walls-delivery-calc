<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiException;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryPickupImportService {
	private const MAX_PAGES = 50000;
	private const MAX_ROWS = 5000000;
	private const MAX_RETRIES = 3;
	private const RETRY_BACKOFF_SECONDS = array( 1 => 2, 2 => 5, 3 => 10 );
	public function __construct( private OzonDeliveryApiClient $api, private OzonDeliveryPickupParser $parser, private OzonDeliveryPickupRepository $repository ) {}
	public function start( string $job_id ): ?int { return $this->repository->start( $job_id ); }
	public function fail_job( string $job_id, string $code, string $message ): void { $generation = $this->repository->generation_by_job( $job_id ); if ( is_array( $generation ) && 'building' === (string) $generation['state'] ) { $this->repository->fail( (int) $generation['id'], $code, $message, $this->diagnostics( $generation, $code, $message, null, (int) ( $generation['retry_count'] ?? 0 ) + 1, 0 ) ); } }
	/** @return array{complete:bool,failed:bool,retry?:bool,retry_after?:int} */
	public function run_step( string $job_id ): array {
		$generation = $this->repository->generation_by_job( $job_id ); if ( ! is_array( $generation ) || 'building' !== $generation['state'] ) { return array( 'complete' => true, 'failed' => 'failed' === ( $generation['state'] ?? '' ) ); }
		$listed_ids_count = 0;
		try {
			$page = $this->parser->list_page( $this->api->pickup_list( (string) ( $generation['cursor_value'] ?? '' ) ) );
			$listed_ids_count = count( $page['ids'] );
			if ( (int) $generation['page_count'] >= self::MAX_PAGES || (int) $generation['downloaded_count'] + count( $page['ids'] ) > self::MAX_ROWS || ( null !== $page['next_cursor'] && $page['next_cursor'] === (string) ( $generation['cursor_value'] ?? '' ) ) ) { throw new \RuntimeException( 'pickup_pagination_invalid' ); }
			$details = array(); if ( array() !== $page['ids'] ) { $details = $this->parser->info_page( $this->api->pickup_info( $page['ids'] ) ); }
			$by_id = array(); foreach ( $details as $row ) { $by_id[(int) $row['point_id']] = $row; }
			$accepted = 0; $rejected = 0; $duplicates = 0; foreach ( $page['ids'] as $id ) { if ( ! isset( $by_id[$id] ) ) { ++$rejected; continue; } $existing = $this->repository->find_in_generation( (int) $generation['id'], $id ); if ( is_array( $existing ) ) { if ( hash_equals( (string) $existing['fingerprint'], (string) $by_id[$id]['fingerprint'] ) ) { ++$duplicates; continue; } throw new \RuntimeException( 'pickup_duplicate_conflict' ); } if ( ! $this->repository->insert_point( (int) $generation['id'], $by_id[$id] ) ) { throw new \RuntimeException( 'pickup_persistence_failed' ); } ++$accepted; }
			$patch = array( 'cursor_value' => $page['next_cursor'], 'page_count' => (int) $generation['page_count'] + 1, 'downloaded_count' => (int) $generation['downloaded_count'] + count( $page['ids'] ), 'accepted_count' => (int) $generation['accepted_count'] + $accepted, 'rejected_count' => (int) $generation['rejected_count'] + $rejected, 'duplicate_count' => (int) $generation['duplicate_count'] + $duplicates, 'retry_count' => 0, 'safe_error_code' => null, 'safe_error_message' => null, 'safe_error_operation' => null, 'safe_error_http_status' => null, 'safe_error_retryable' => null, 'failed_page' => null, 'failed_cursor' => null, 'failed_after_ids' => null, 'failed_attempt' => null, 'failed_at' => null );
			$this->repository->update_generation( (int) $generation['id'], $patch );
			if ( null === $page['next_cursor'] ) { $this->repository->update_generation( (int) $generation['id'], array( 'state' => 'ready' ) ); if ( ! $this->repository->activate( (int) $generation['id'] ) ) { throw new \RuntimeException( 'pickup_activation_rejected' ); } return array( 'complete' => true, 'failed' => false ); }
			return array( 'complete' => false, 'failed' => false );
		} catch ( \Throwable $exception ) {
			$attempt = (int) ( $generation['retry_count'] ?? 0 ) + 1;
			$code = $exception instanceof OzonDeliveryApiException ? $exception->safe_code : ( preg_replace( '/[^a-z0-9_]/', '', strtolower( $exception->getMessage() ) ) ?: 'pickup_import_failed' );
			$diagnostics = $this->diagnostics( $generation, $code, $exception->getMessage(), $exception, $attempt, $listed_ids_count );
			if ( $exception instanceof OzonDeliveryApiException && $exception->retryable && $attempt <= self::MAX_RETRIES ) {
				$this->repository->record_retry( (int) $generation['id'], $attempt, $diagnostics );
				return array( 'complete' => false, 'failed' => false, 'retry' => true, 'retry_after' => self::RETRY_BACKOFF_SECONDS[$attempt] ?? 10 );
			}
			$this->repository->fail( (int) $generation['id'], $code, 'Не удалось обновить справочник ПВЗ Ozon Delivery.', $diagnostics );
			return array( 'complete' => true, 'failed' => true );
		}
	}
	/** @param array<string,mixed> $generation @return array<string,mixed> */
	private function diagnostics( array $generation, string $code, string $message, ?\Throwable $exception, int $attempt, int $after_ids ): array {
		return array(
			'operation' => $exception instanceof OzonDeliveryApiException ? $exception->operation : $code,
			'code' => $code,
			'http_status' => $exception instanceof OzonDeliveryApiException ? $exception->http_status : 0,
			'retryable' => $exception instanceof OzonDeliveryApiException && $exception->retryable,
			'failed_page' => (int) ( $generation['page_count'] ?? 0 ) + 1,
			'failed_cursor' => (string) ( $generation['cursor_value'] ?? '' ),
			'failed_after_ids' => $after_ids,
			'failed_attempt' => $attempt,
			'message' => $message,
		);
	}
}

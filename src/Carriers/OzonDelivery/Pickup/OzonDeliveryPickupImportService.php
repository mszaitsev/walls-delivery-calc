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
	private const INFO_BATCH_SIZE = 100;
	private const MAX_INFO_SALVAGE_REQUESTS = 199;
	private const RETRY_BACKOFF_SECONDS = array( 1 => 2, 2 => 5, 3 => 10 );

	public function __construct( private OzonDeliveryApiClient $api, private OzonDeliveryPickupParser $parser, private OzonDeliveryPickupRepository $repository ) {}

	public function start( string $job_id, ?string $lock_owner = null ): ?int {
		return $this->repository->start( $job_id, $lock_owner );
	}

	/** @return array<string,mixed>|null */
	public function building_generation(): ?array {
		return $this->repository->building_generation();
	}

	public function cancel_generation( int $generation_id ): bool {
		return $this->repository->cancel_building_generation( $generation_id );
	}

	public function fail_job( string $job_id, string $code, string $message ): void {
		$generation = $this->repository->generation_by_job( $job_id );
		if ( is_array( $generation ) && 'building' === (string) $generation['state'] ) {
			$this->repository->fail( (int) $generation['id'], $code, $message, $this->diagnostics( $generation, $code, $message, null, (int) ( $generation['retry_count'] ?? 0 ) + 1, 0 ) );
		}
	}

	/** @return array{complete:bool,failed:bool,retry?:bool,retry_after?:int} */
	public function run_step( string $job_id ): array {
		$generation = $this->repository->generation_by_job( $job_id );
		if ( ! is_array( $generation ) || 'building' !== $generation['state'] ) {
			return array( 'complete' => true, 'failed' => 'failed' === ( $generation['state'] ?? '' ) );
		}

		$handled_ids_count = 0;
		try {
			$result = 'enrichment' === (string) ( $generation['phase'] ?? 'discovery' )
				? $this->run_enrichment_step( $generation, $handled_ids_count )
				: $this->run_discovery_step( $generation, $handled_ids_count );
			return $result;
		} catch ( \Throwable $exception ) {
			$terminal = $this->terminal_result_if_not_current_building_phase( (int) $generation['id'], (string) ( $generation['phase'] ?? 'discovery' ) );
			if ( null !== $terminal ) {
				return $terminal;
			}
			$attempt = (int) ( $generation['retry_count'] ?? 0 ) + 1;
			$code = $exception instanceof OzonDeliveryApiException ? $exception->safe_code : ( preg_replace( '/[^a-z0-9_]/', '', strtolower( $exception->getMessage() ) ) ?: 'pickup_import_failed' );
			$diagnostics = $this->diagnostics( $generation, $code, $exception->getMessage(), $exception, $attempt, $handled_ids_count );
			if ( $exception instanceof OzonDeliveryApiException && $exception->retryable && $attempt <= self::MAX_RETRIES ) {
				$this->repository->record_retry( (int) $generation['id'], $attempt, $diagnostics );
				return array( 'complete' => false, 'failed' => false, 'retry' => true, 'retry_after' => self::RETRY_BACKOFF_SECONDS[$attempt] ?? 10 );
			}
			$this->repository->fail( (int) $generation['id'], $code, 'Не удалось обновить справочник ПВЗ Ozon Delivery.', $diagnostics );
			return array( 'complete' => true, 'failed' => true );
		}
	}

	/**
	 * @param array<string,mixed> $generation
	 * @param int $handled_ids_count
	 * @return array{complete:bool,failed:bool}
	 */
	private function run_discovery_step( array $generation, int &$handled_ids_count ): array {
		$page = $this->parser->list_page( $this->api->pickup_list( (string) ( $generation['cursor_value'] ?? '' ) ) );
		$handled_ids_count = count( $page['ids'] );
		$current_cursor = (string) ( $generation['cursor_value'] ?? '' );
		$current_pages = (int) ( $generation['discovery_page_count'] ?? $generation['page_count'] ?? 0 );
		$current_discovered = (int) ( $generation['discovered_count'] ?? $generation['downloaded_count'] ?? 0 );

		if ( $current_pages >= self::MAX_PAGES || $current_discovered + count( $page['ids'] ) > self::MAX_ROWS || ( null !== $page['next_cursor'] && $page['next_cursor'] === $current_cursor ) ) {
			throw new \RuntimeException( 'pickup_pagination_invalid' );
		}

		$patch = $this->clear_generation_errors(
			array(
				'cursor_value'            => $page['next_cursor'],
				'page_count'              => $current_pages + 1,
				'discovery_page_count'    => $current_pages + 1,
				'retry_count'             => 0,
			)
		);

		if ( null === $page['next_cursor'] ) {
			$patch['phase'] = 'enrichment';
			$patch['discovery_completed_at'] = current_time( 'mysql', true );
		}

		if ( ! $this->repository->commit_discovery_page( (int) $generation['id'], $page['ids'], $patch ) ) {
			$terminal = $this->terminal_result_if_not_current_building_phase( (int) $generation['id'], 'discovery' );
			if ( null !== $terminal ) {
				return $terminal;
			}
			throw new \RuntimeException( 'pickup_persistence_failed' );
		}

		if ( null === $page['next_cursor'] ) {
			return array( 'complete' => false, 'failed' => false );
		}

		return array( 'complete' => false, 'failed' => false );
	}

	/**
	 * @param array<string,mixed> $generation
	 * @param int $handled_ids_count
	 * @return array{complete:bool,failed:bool}
	 */
	private function run_enrichment_step( array $generation, int &$handled_ids_count ): array {
		$ids = $this->repository->pending_ids( (int) $generation['id'], self::INFO_BATCH_SIZE );
		if ( array() === $ids ) {
			if ( ! $this->repository->ready_for_activation( (int) $generation['id'] ) ) {
				throw new \RuntimeException( 'pickup_enrichment_incomplete' );
			}
			$this->repository->update_generation( (int) $generation['id'], array( 'state' => 'ready' ) );
			if ( ! $this->repository->activate( (int) $generation['id'] ) ) {
				throw new \RuntimeException( 'pickup_activation_rejected' );
			}
			return array( 'complete' => true, 'failed' => false );
		}

		$handled_ids_count = count( $ids );
		$budget = self::MAX_INFO_SALVAGE_REQUESTS;
		$resolved = $this->resolve_info_batch( $ids, $budget );
		$by_id = array();
		foreach ( $resolved['points'] as $row ) {
			$by_id[(int) $row['point_id']] = $row;
		}

		$points = array();
		$rejects = $resolved['rejects'];
		foreach ( $ids as $id ) {
			if ( isset( $by_id[$id] ) ) {
				$points[] = $by_id[$id];
			} elseif ( ! isset( $rejects[$id] ) ) {
				$rejects[$id] = 'info_missing';
			}
		}

		if ( ! $this->repository->commit_enrichment_batch( (int) $generation['id'], $points, $rejects ) ) {
			$terminal = $this->terminal_result_if_not_current_building_phase( (int) $generation['id'], 'enrichment' );
			if ( null !== $terminal ) {
				return $terminal;
			}
			throw new \RuntimeException( 'pickup_persistence_failed' );
		}

		return array( 'complete' => false, 'failed' => false );
	}

	/**
	 * @param array<int,int> $ids
	 * @return array{points:array<int,array<string,mixed>>,rejects:array<int,string>}
	 */
	private function resolve_info_batch( array $ids, int &$budget ): array {
		if ( array() === $ids ) {
			return array( 'points' => array(), 'rejects' => array() );
		}
		if ( $budget <= 0 ) {
			throw new \RuntimeException( 'pickup_info_salvage_budget_exceeded' );
		}
		--$budget;

		try {
			return array( 'points' => $this->parser->info_page( $this->api->pickup_info( $ids ) ), 'rejects' => array() );
		} catch ( OzonDeliveryApiException $exception ) {
			if ( ! $this->is_info_not_found( $exception ) ) {
				throw $exception;
			}
			if ( 1 === count( $ids ) ) {
				return array( 'points' => array(), 'rejects' => array( (int) $ids[0] => 'not_found_404' ) );
			}
			$middle = (int) ceil( count( $ids ) / 2 );
			$left = $this->resolve_info_batch( array_slice( $ids, 0, $middle ), $budget );
			$right = $this->resolve_info_batch( array_slice( $ids, $middle ), $budget );
			return array(
				'points'  => array_merge( $left['points'], $right['points'] ),
				'rejects' => $left['rejects'] + $right['rejects'],
			);
		}
	}

	private function is_info_not_found( OzonDeliveryApiException $exception ): bool {
		return 'v1/delivery-point/info' === $exception->operation && 404 === $exception->http_status;
	}

	/** @return array{complete:bool,failed:bool}|null */
	private function terminal_result_if_not_current_building_phase( int $generation_id, string $phase ): ?array {
		if ( $this->repository->building_phase_matches( $generation_id, $phase ) ) {
			return null;
		}
		$state = $this->repository->generation_state( $generation_id );
		if ( null === $state ) {
			return null;
		}
		return array( 'complete' => true, 'failed' => 'failed' === $state );
	}

	/** @param array<string,mixed> $generation @return array<string,mixed> */
	private function diagnostics( array $generation, string $code, string $message, ?\Throwable $exception, int $attempt, int $after_ids ): array {
		return array(
			'operation'        => $exception instanceof OzonDeliveryApiException ? $exception->operation : $code,
			'code'             => $code,
			'http_status'      => $exception instanceof OzonDeliveryApiException ? $exception->http_status : 0,
			'retryable'        => $exception instanceof OzonDeliveryApiException && $exception->retryable,
			'failed_page'      => (int) ( $generation['discovery_page_count'] ?? $generation['page_count'] ?? 0 ) + 1,
			'failed_cursor'    => 'discovery' === (string) ( $generation['phase'] ?? 'discovery' ) ? (string) ( $generation['cursor_value'] ?? '' ) : '',
			'failed_after_ids' => $after_ids,
			'failed_attempt'   => $attempt,
			'message'          => $message,
		);
	}

	/** @param array<string,mixed> $patch @return array<string,mixed> */
	private function clear_generation_errors( array $patch ): array {
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
}

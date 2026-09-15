<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	function current_time( string $type, bool $gmt = false ): string { return '2026-09-04 00:00:00'; }
	require_once dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Api/OzonDeliveryApiException.php';
}

namespace WallsShop\WDC\Carriers\OzonDelivery\Api {
	final class OzonDeliveryApiClient {
		/** @var callable|null */
		public $before_throw = null;
		/** @var array<string,mixed> */
		public array $list_response = array( 'delivery_points' => array( array( 'delivery_point_id' => 10 ) ), 'next_cursor' => 'next' );
		/** @var array<string,mixed> */
		public array $info_response = array( 'delivery_points' => array( array( 'delivery_point_id' => 10 ) ) );
		public ?OzonDeliveryApiException $list_exception = null;
		public ?OzonDeliveryApiException $info_exception = null;
		public int $list_calls = 0;
		public int $info_calls = 0;

		/** @return array<string,mixed> */
		public function pickup_list( ?string $cursor = null ): array {
			++$this->list_calls;
			if ( $this->list_exception instanceof OzonDeliveryApiException ) {
				if ( is_callable( $this->before_throw ) ) {
					( $this->before_throw )();
				}
				throw $this->list_exception;
			}
			return $this->list_response;
		}

		/** @param array<int,int> $ids @return array<string,mixed> */
		public function pickup_info( array $ids ): array {
			++$this->info_calls;
			if ( $this->info_exception instanceof OzonDeliveryApiException ) {
				if ( is_callable( $this->before_throw ) ) {
					( $this->before_throw )();
				}
				throw $this->info_exception;
			}
			return $this->info_response;
		}
	}
}

namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup {
	final class OzonDeliveryPickupParser {
		/** @param array<string,mixed> $payload @return array{ids:array<int,int>,next_cursor:?string} */
		public function list_page( array $payload ): array {
			$ids = array();
			foreach ( $payload['delivery_points'] ?? array() as $row ) {
				$ids[] = (int) $row['delivery_point_id'];
			}
			return array( 'ids' => $ids, 'next_cursor' => $payload['next_cursor'] ?? null );
		}

		/** @param array<string,mixed> $payload @return array<int,array<string,mixed>> */
		public function info_page( array $payload ): array {
			$rows = array();
			foreach ( $payload['delivery_points'] ?? array() as $row ) {
				$rows[] = array( 'point_id' => (int) $row['delivery_point_id'], 'fingerprint' => 'fp-' . (string) $row['delivery_point_id'] );
			}
			return $rows;
		}
	}

	final class OzonDeliveryPickupRepository {
		/** @var array<string,mixed> */
		public array $generation;
		/** @var array<int,int> */
		public array $pending = array( 10 );
		public string $commit_mode = 'success';
		public int $retry_calls = 0;
		public int $fail_calls = 0;
		public int $activate_calls = 0;

		/** @param array<string,mixed> $generation */
		public function __construct( array $generation ) {
			$this->generation = $generation;
		}

		/** @return array<string,mixed>|null */
		public function generation_by_job( string $job_id ): ?array {
			return $job_id === (string) ( $this->generation['job_id'] ?? '' ) ? $this->generation : null;
		}

		public function generation_state( int $generation_id ): ?string {
			return $generation_id === (int) ( $this->generation['id'] ?? 0 ) ? (string) ( $this->generation['state'] ?? '' ) : null;
		}

		public function building_phase_matches( int $generation_id, string $phase ): bool {
			return $generation_id === (int) ( $this->generation['id'] ?? 0 ) && 'building' === (string) ( $this->generation['state'] ?? '' ) && $phase === (string) ( $this->generation['phase'] ?? 'discovery' );
		}

		/** @param array<int,int> $ids @param array<string,mixed> $generation_patch */
		public function commit_discovery_page( int $generation_id, array $ids, array $generation_patch ): bool {
			if ( 'cancel' === $this->commit_mode ) {
				$this->generation['state'] = 'cancelled';
				return false;
			}
			if ( 'fail' === $this->commit_mode ) {
				return false;
			}
			$this->generation = array_merge( $this->generation, $generation_patch );
			$this->generation['downloaded_count'] = count( array_unique( $ids ) );
			$this->generation['discovered_count'] = count( array_unique( $ids ) );
			return true;
		}

		/** @return array<int,int> */
		public function pending_ids( int $generation_id, int $limit = 100 ): array {
			return array_slice( $this->pending, 0, $limit );
		}

		/** @param array<int,array<string,mixed>> $points @param array<int,string> $rejects */
		public function commit_enrichment_batch( int $generation_id, array $points, array $rejects ): bool {
			if ( 'cancel' === $this->commit_mode ) {
				$this->generation['state'] = 'cancelled';
				return false;
			}
			if ( 'fail' === $this->commit_mode ) {
				return false;
			}
			$this->pending = array();
			$this->generation['accepted_count'] = (int) ( $this->generation['accepted_count'] ?? 0 ) + count( $points );
			$this->generation['rejected_count'] = (int) ( $this->generation['rejected_count'] ?? 0 ) + count( $rejects );
			$this->generation['enrichment_processed_count'] = (int) ( $this->generation['enrichment_processed_count'] ?? 0 ) + count( $points ) + count( $rejects );
			return true;
		}

		public function mark_ready_if_complete( int $generation_id ): bool { $this->generation['state'] = 'ready'; return true; }
		public function update_generation( int $id, array $patch ): void { $this->generation = array_merge( $this->generation, $patch ); }
		public function activate( int $id ): bool { ++$this->activate_calls; return true; }
		public function record_retry( int $id, int $retry_count, array $diagnostics ): void { ++$this->retry_calls; }
		public function fail( int $id, string $code, string $message, array $diagnostics = array() ): void { ++$this->fail_calls; $this->generation['state'] = 'failed'; }
	}

	require_once dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupImportService.php';

	use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;
	use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiException;

	function oz_race_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	function oz_race_importer( string $phase, string $commit_mode = 'success' ): array {
		$api = new OzonDeliveryApiClient();
		$repository = new OzonDeliveryPickupRepository(
			array(
				'id' => 7,
				'job_id' => 'job-1',
				'state' => 'building',
				'phase' => $phase,
				'retry_count' => 0,
				'discovery_page_count' => 6,
				'discovered_count' => 100,
				'accepted_count' => 0,
				'rejected_count' => 0,
				'enrichment_processed_count' => 0,
			)
		);
		$repository->commit_mode = $commit_mode;
		return array( new OzonDeliveryPickupImportService( $api, new OzonDeliveryPickupParser(), $repository ), $api, $repository );
	}

	[ $importer, $api, $repository ] = oz_race_importer( 'discovery', 'cancel' );
	$result = $importer->run_step( 'job-1' );
	oz_race_assert( array( 'complete' => true, 'failed' => false ) === $result && 0 === $repository->retry_calls && 0 === $repository->fail_calls && 0 === $repository->activate_calls, 'Discovery cancellation between API response and commit must be harmless terminal completion.' );

	[ $importer, $api, $repository ] = oz_race_importer( 'enrichment', 'cancel' );
	$result = $importer->run_step( 'job-1' );
	oz_race_assert( array( 'complete' => true, 'failed' => false ) === $result && 0 === $repository->retry_calls && 0 === $repository->fail_calls && 0 === $repository->activate_calls && array( 10 ) === $repository->pending, 'Enrichment cancellation between /info response and commit must not persist or activate.' );

	[ $importer, $api, $repository ] = oz_race_importer( 'enrichment', 'success' );
	$api->info_exception = new OzonDeliveryApiException( 'v1/delivery-point/info', 'transport_error', 0, true, 'Temporary transport failure.' );
	$api->before_throw = static function () use ( $repository ): void { $repository->generation['state'] = 'cancelled'; };
	$result = $importer->run_step( 'job-1' );
	oz_race_assert( array( 'complete' => true, 'failed' => false ) === $result && 0 === $repository->retry_calls && 0 === $repository->fail_calls, 'Retryable exception after cancellation must not record retry or fail.' );

	[ $importer, $api, $repository ] = oz_race_importer( 'discovery', 'fail' );
	$result = $importer->run_step( 'job-1' );
	oz_race_assert( true === $result['complete'] && true === $result['failed'] && 1 === $repository->fail_calls && 0 === $repository->retry_calls, 'Commit false while still building in the same phase must remain a real persistence failure.' );

	[ $importer, $api, $repository ] = oz_race_importer( 'enrichment', 'success' );
	$repository->generation['state'] = 'cancelled';
	$result = $importer->run_step( 'job-1' );
	oz_race_assert( array( 'complete' => true, 'failed' => false ) === $result && 0 === $api->info_calls && 0 === $repository->activate_calls, 'Stale run_step for an already cancelled generation must no-op before API calls.' );

	echo "Ozon Delivery pickup cancellation race smoke passed.\n";
}

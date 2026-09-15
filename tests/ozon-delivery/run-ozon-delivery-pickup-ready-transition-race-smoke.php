<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	function current_time( string $type, bool $gmt = false ): string { return '2026-09-04 00:00:00'; }
	require_once dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Api/OzonDeliveryApiException.php';
}

namespace WallsShop\WDC\Carriers\OzonDelivery\Api {
	final class OzonDeliveryApiClient {
		public int $list_calls = 0;
		public int $info_calls = 0;
		public function pickup_list( ?string $cursor = null ): array { ++$this->list_calls; return array(); }
		/** @param array<int,int> $ids @return array<string,mixed> */
		public function pickup_info( array $ids ): array { ++$this->info_calls; return array( 'delivery_points' => array() ); }
	}
}

namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup {
	final class OzonDeliveryPickupParser {
		/** @param array<string,mixed> $payload @return array{ids:array<int,int>,next_cursor:?string} */
		public function list_page( array $payload ): array { return array( 'ids' => array(), 'next_cursor' => null ); }
		/** @param array<string,mixed> $payload @return array<int,array<string,mixed>> */
		public function info_page( array $payload ): array { return array(); }
	}

	final class OzonDeliveryPickupRepository {
		/** @var array<string,mixed> */
		public array $generation;
		/** @var array<int,int> */
		public array $pending = array();
		public int $pending_count = 0;
		public string $ready_mode = 'normal';
		public bool $activate_result = true;
		public int $ready_calls = 0;
		public int $activate_calls = 0;
		public int $fail_calls = 0;
		public int $retry_calls = 0;
		public int $staging_rows = 100;
		public int $partial_point_rows = 95;
		public int $counter_mutations_after_cancel = 0;

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
			return $generation_id === (int) ( $this->generation['id'] ?? 0 ) && 'building' === (string) ( $this->generation['state'] ?? '' ) && $phase === (string) ( $this->generation['phase'] ?? '' );
		}

		/** @return array<int,int> */
		public function pending_ids( int $generation_id, int $limit = 100 ): array {
			return array_slice( $this->pending, 0, $limit );
		}

		public function mark_ready_if_complete( int $generation_id ): bool {
			++$this->ready_calls;
			if ( 'cancel' === $this->ready_mode ) {
				$this->generation['state'] = 'cancelled';
				$this->staging_rows = 0;
				$this->partial_point_rows = 0;
				return false;
			}
			if ( $generation_id !== (int) ( $this->generation['id'] ?? 0 ) || 'building' !== (string) ( $this->generation['state'] ?? '' ) || 'enrichment' !== (string) ( $this->generation['phase'] ?? '' ) ) {
				return false;
			}
			$discovered = (int) ( $this->generation['discovered_count'] ?? 0 );
			if ( $this->pending_count > 0 || (int) ( $this->generation['conflict_count'] ?? 0 ) > 0 || $discovered <= 0 ) {
				return false;
			}
			if ( (int) ( $this->generation['accepted_count'] ?? 0 ) + (int) ( $this->generation['rejected_count'] ?? 0 ) !== $discovered || (int) ( $this->generation['enrichment_processed_count'] ?? 0 ) !== $discovered ) {
				return false;
			}
			$this->generation['state'] = 'ready';
			return true;
		}

		public function activate( int $id ): bool {
			++$this->activate_calls;
			if ( ! $this->activate_result || 'ready' !== (string) ( $this->generation['state'] ?? '' ) ) {
				return false;
			}
			$this->generation['state'] = 'active';
			return true;
		}

		public function record_retry( int $id, int $retry_count, array $diagnostics ): void {
			++$this->retry_calls;
		}

		public function fail( int $id, string $code, string $message, array $diagnostics = array() ): void {
			++$this->fail_calls;
			if ( 'cancelled' === (string) ( $this->generation['state'] ?? '' ) ) {
				return;
			}
			$this->generation['state'] = 'failed';
		}
	}

	require_once dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupImportService.php';

	use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;

	function oz_ready_race_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	/** @param array<string,mixed> $overrides @return array{0:OzonDeliveryPickupImportService,1:OzonDeliveryApiClient,2:OzonDeliveryPickupRepository} */
	function oz_ready_race_importer( array $overrides = array() ): array {
		$generation = array_merge(
			array(
				'id' => 7,
				'job_id' => 'job-1',
				'state' => 'building',
				'phase' => 'enrichment',
				'retry_count' => 0,
				'cursor_value' => 'cursor-safe',
				'discovery_page_count' => 1,
				'discovered_count' => 100,
				'accepted_count' => 95,
				'rejected_count' => 5,
				'enrichment_processed_count' => 100,
				'conflict_count' => 0,
			),
			$overrides
		);
		$api = new OzonDeliveryApiClient();
		$repository = new OzonDeliveryPickupRepository( $generation );
		return array( new OzonDeliveryPickupImportService( $api, new OzonDeliveryPickupParser(), $repository ), $api, $repository );
	}

	[ $importer, $api, $repository ] = oz_ready_race_importer();
	$repository->ready_mode = 'cancel';
	$result = $importer->run_step( 'job-1' );
	oz_ready_race_assert( array( 'complete' => true, 'failed' => false ) === $result && 'cancelled' === $repository->generation['state'] && 0 === $repository->activate_calls && 0 === $repository->fail_calls && 0 === $repository->retry_calls, 'Cancellation between completion checks and ready transition must win without retry/fail/activate.' );
	oz_ready_race_assert( 0 === $repository->staging_rows && 0 === $repository->partial_point_rows && 0 === $api->info_calls, 'Cancelled cleanup plus late ready attempt must not recreate rows or call /info.' );

	$result = $importer->run_step( 'job-1' );
	oz_ready_race_assert( array( 'complete' => true, 'failed' => false ) === $result && 'cancelled' === $repository->generation['state'] && 0 === $repository->activate_calls, 'A cancelled generation must never become ready/active on a stale completion step.' );

	[ $importer, $api, $repository ] = oz_ready_race_importer();
	$result = $importer->run_step( 'job-1' );
	oz_ready_race_assert( array( 'complete' => true, 'failed' => false ) === $result && 'active' === $repository->generation['state'] && 1 === $repository->ready_calls && 1 === $repository->activate_calls, 'Normal 95+5 completed generation must mark ready and activate.' );

	[ $importer, $api, $repository ] = oz_ready_race_importer();
	$repository->pending_count = 1;
	$result = $importer->run_step( 'job-1' );
	oz_ready_race_assert( true === $result['complete'] && true === $result['failed'] && 'failed' === $repository->generation['state'] && 0 === $repository->activate_calls, 'Pending IDs must block ready transition and fail as incomplete if completion path is reached.' );

	foreach (
		array(
			'counter mismatch' => array( 'accepted_count' => 94, 'rejected_count' => 5, 'enrichment_processed_count' => 99 ),
			'conflict' => array( 'conflict_count' => 1 ),
			'zero discovery' => array( 'discovered_count' => 0, 'accepted_count' => 0, 'rejected_count' => 0, 'enrichment_processed_count' => 0 ),
		) as $label => $overrides
	) {
		[ $importer, $api, $repository ] = oz_ready_race_importer( $overrides );
		$result = $importer->run_step( 'job-1' );
		oz_ready_race_assert( true === $result['complete'] && true === $result['failed'] && 'failed' === $repository->generation['state'] && 0 === $repository->activate_calls, $label . ' must block ready transition and fail safely.' );
	}

	[ $importer, $api, $repository ] = oz_ready_race_importer( array( 'state' => 'ready' ) );
	$result = $importer->run_step( 'job-1' );
	oz_ready_race_assert( array( 'complete' => true, 'failed' => false ) === $result && 0 === $repository->ready_calls && 0 === $repository->activate_calls && 'ready' === $repository->generation['state'], 'Ready generation must not be treated as a cancellable/running enrichment job.' );

	[ $importer, $api, $repository ] = oz_ready_race_importer();
	$repository->activate_result = false;
	$result = $importer->run_step( 'job-1' );
	oz_ready_race_assert( true === $result['complete'] && true === $result['failed'] && 'failed' === $repository->generation['state'] && 1 === $repository->activate_calls && 1 === $repository->fail_calls, 'Activation rejection after ready must remain a real failure, not a harmless terminal no-op.' );

	echo "Ozon Delivery pickup ready transition race smoke passed.\n";
}

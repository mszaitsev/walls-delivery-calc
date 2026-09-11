<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

defined( 'ABSPATH' ) || exit;

/**
 * Request-local, batch-scoped profiler for the Russian Post pickup importer.
 *
 * It records only aggregate timings, counters, and hashes used for cardinality.
 * No source values, SQL text, or customer data leave this object.
 */
final class RussianPostImportBatchProfiler {
	/** @var array<string,float> */
	private array $phase_ms = array();

	/** @var array<string,int> */
	private array $query_counts = array(
		'location_select_queries' => 0,
		'staging_select_queries' => 0,
		'staging_write_queries' => 0,
		'state_option_writes' => 0,
		'other_wdc_queries' => 0,
		'fias_lookup_queries' => 0,
		'postcode_lookup_queries' => 0,
		'region_city_lookup_queries' => 0,
	);

	/** @var array<string,int> */
	private array $match_counts = array(
		'matched_fias' => 0,
		'matched_postal_code' => 0,
		'matched_region_city' => 0,
		'no_match' => 0,
		'ambiguous' => 0,
		'skipped' => 0,
	);

	/** @var array<string,int> */
	private array $lookup_counts = array(
		'fias_lookups' => 0,
		'postcode_lookups' => 0,
		'region_city_lookups' => 0,
	);

	/** @var array<string,array<string,bool>> */
	private array $unique_lookup_hashes = array(
		'fias' => array(),
		'postcode' => array(),
		'region_city' => array(),
	);

	private int $started_ns;
	private int $memory_before;

	public function __construct(
		private int $batch_sequence,
		private int $payload_offset_start,
		private string $worker_slice_started_at
	) {
		$this->started_ns = $this->now_ns();
		$this->memory_before = $this->memory_usage();
	}

	public function measure( string $phase, callable $callback ): mixed {
		$started = $this->now_ns();
		try {
			return $callback();
		} finally {
			$this->phase_ms[ $phase ] = ( $this->phase_ms[ $phase ] ?? 0.0 ) + $this->duration_ms( $started );
		}
	}

	public function measure_query_delta( string $phase, string $query_category, callable $callback ): mixed {
		$before = $this->wpdb_query_count();
		$result = $this->measure( $phase, $callback );
		$after = $this->wpdb_query_count();
		if ( null !== $before && null !== $after && $after > $before ) {
			$this->increment_query( $query_category, $after - $before );
		}

		return $result;
	}

	public function record_lookup( string $strategy, string $key ): void {
		$count_key = match ( $strategy ) {
			'fias' => 'fias_lookups',
			'postcode' => 'postcode_lookups',
			'region_city' => 'region_city_lookups',
			default => '',
		};
		if ( '' === $count_key || '' === $key ) {
			return;
		}
		++$this->lookup_counts[ $count_key ];
		$this->unique_lookup_hashes[ $strategy ][ sha1( $key ) ] = true;
	}

	public function record_lookup_query( string $strategy, float $duration_ms ): void {
		$query_key = match ( $strategy ) {
			'fias' => 'fias_lookup_queries',
			'postcode' => 'postcode_lookup_queries',
			'region_city' => 'region_city_lookup_queries',
			default => '',
		};
		$phase_key = match ( $strategy ) {
			'fias' => 'fias_match_ms',
			'postcode' => 'postal_match_ms',
			'region_city' => 'region_city_match_ms',
			default => '',
		};
		if ( '' === $query_key || '' === $phase_key ) {
			return;
		}
		$this->increment_query( $query_key );
		$this->increment_query( 'location_select_queries' );
		$this->phase_ms[ $phase_key ] = ( $this->phase_ms[ $phase_key ] ?? 0.0 ) + max( 0.0, $duration_ms );
	}

	/** @param array{status?:mixed,strategy?:mixed} $match */
	public function record_match( array $match ): void {
		$status = (string) ( $match['status'] ?? '' );
		$strategy = (string) ( $match['strategy'] ?? '' );
		if ( 'ambiguous' === $status ) {
			++$this->match_counts['ambiguous'];
			return;
		}
		if ( 'unique' !== $status ) {
			++$this->match_counts['no_match'];
			return;
		}
		$key = match ( $strategy ) {
			'fias' => 'matched_fias',
			'postal_code' => 'matched_postal_code',
			'region_city' => 'matched_region_city',
			default => 'no_match',
		};
		++$this->match_counts[ $key ];
	}

	public function record_skipped( int $count = 1 ): void {
		$this->match_counts['skipped'] += max( 0, $count );
	}

	public function increment_query( string $category, int $count = 1 ): void {
		if ( ! array_key_exists( $category, $this->query_counts ) ) {
			return;
		}
		$this->query_counts[ $category ] += max( 0, $count );
	}

	/** @return array<string,mixed> */
	public function finish( int $payload_offset_end, int $objects ): array {
		$phase_keys = array( 'payload_read_ms', 'parse_ms', 'normalize_ms', 'location_match_ms', 'staging_prepare_ms', 'staging_write_ms', 'checkpoint_ms', 'lock_renew_ms', 'fias_match_ms', 'postal_match_ms', 'region_city_match_ms' );
		$timings = array();
		foreach ( $phase_keys as $key ) {
			$timings[ $key ] = max( 0, (int) round( $this->phase_ms[ $key ] ?? 0.0 ) );
		}
		$this->query_counts['total_profiled_queries'] = array_sum( array_intersect_key( $this->query_counts, array_flip( array( 'location_select_queries', 'staging_select_queries', 'staging_write_queries', 'state_option_writes', 'other_wdc_queries' ) ) ) );
		$lookup_counts = $this->lookup_counts;
		$lookup_counts['unique_fias_keys'] = count( $this->unique_lookup_hashes['fias'] );
		$lookup_counts['unique_postcode_keys'] = count( $this->unique_lookup_hashes['postcode'] );
		$lookup_counts['unique_region_city_keys'] = count( $this->unique_lookup_hashes['region_city'] );

		return array_merge(
			array(
				'batch_sequence' => max( 1, $this->batch_sequence ),
				'payload_offset_start' => max( 0, $this->payload_offset_start ),
				'payload_offset_end' => max( 0, $payload_offset_end ),
				'objects' => max( 0, $objects ),
				'total_batch_ms' => max( 0, (int) round( $this->duration_ms( $this->started_ns ) ) ),
				'memory_before' => $this->memory_before,
				'memory_after' => $this->memory_usage(),
				'memory_peak' => $this->memory_peak(),
				'worker_slice_started_at' => $this->worker_slice_started_at,
				'timestamp' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			),
			$timings,
			array(
				'query_counts' => $this->query_counts,
				'match_counts' => $this->match_counts,
				'lookup_counts' => $lookup_counts,
			)
		);
	}

	public function monotonic_now(): int {
		return $this->now_ns();
	}

	public function elapsed_since_ms( int $started_ns ): float {
		return $this->duration_ms( $started_ns );
	}

	private function now_ns(): int {
		return function_exists( 'hrtime' ) ? (int) hrtime( true ) : (int) round( microtime( true ) * 1000000000 );
	}

	private function duration_ms( int $started_ns ): float {
		return max( 0.0, ( $this->now_ns() - $started_ns ) / 1000000 );
	}

	private function memory_usage(): int {
		return function_exists( 'memory_get_usage' ) ? (int) memory_get_usage( true ) : 0;
	}

	private function memory_peak(): int {
		return function_exists( 'memory_get_peak_usage' ) ? (int) memory_get_peak_usage( true ) : 0;
	}

	private function wpdb_query_count(): ?int {
		global $wpdb;

		return is_object( $wpdb ) && property_exists( $wpdb, 'num_queries' ) ? (int) $wpdb->num_queries : null;
	}
}

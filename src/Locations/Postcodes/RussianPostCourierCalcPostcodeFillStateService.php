<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Postcodes;

use WallsShop\WDC\Carriers\RussianPost\RussianPostCourierTariffProbeService;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class RussianPostCourierCalcPostcodeFillStateService {
	public const MAX_PROBES_PER_STEP = 4;

	private \wpdb $wpdb;

	public function __construct(
		private LocationRepository $locations,
		private RussianPostCourierTariffProbeService $probe,
		?\wpdb $db = null
	) {
		global $wpdb;
		$this->wpdb = $db ?? $wpdb;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function create_job(): array {
		$total = $this->count_pending();
		$now = current_time( 'mysql' );

		return array(
			'job_id' => md5( 'rp-courier-postcode-' . microtime( true ) ),
			'phase' => $total > 0 ? 'running' : 'finished',
			'status' => $total > 0 ? 'running' : 'finished',
			'total' => $total,
			'processed' => 0,
			'updated' => 0,
			'bulk_updated' => 0,
			'skipped' => 0,
			'failed' => 0,
			'errors' => 0,
			'probes' => 0,
			'step_probes' => 0,
			'last_id' => 0,
			'current_priority' => 'cities',
			'candidate_offset' => 0,
			'current_location' => array(),
			'current_candidates' => array(),
			'last_location_id' => 0,
			'last_postal_code' => '',
			'last_success_postal_code' => '',
			'last_error' => '',
			'last_error_code' => '',
			'started_at' => $now,
			'updated_at' => $now,
			'finished_at' => $total > 0 ? '' : $now,
		);
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public function step( array $job ): array {
		if ( 'running' !== (string) ( $job['phase'] ?? '' ) ) {
			return $job;
		}

		$job['step_probes'] = 0;
		$location = is_array( $job['current_location'] ?? null ) && array() !== $job['current_location'] ? $job['current_location'] : $this->next_location( $job );
		if ( null === $location ) {
			$job['phase'] = 'finished';
			$job['status'] = 'finished';
			$job['finished_at'] = current_time( 'mysql' );
			$job['updated_at'] = current_time( 'mysql' );
			return $job;
		}

		$location_id = (int) ( $location['id'] ?? 0 );
		$base_postal_code = $this->valid_postcode( (string) ( $location['postal_code'] ?? '' ) );
		$job['current_location'] = $location;
		$job['last_location_id'] = $location_id;
		$job['last_postal_code'] = $base_postal_code;
		$job['last_id'] = max( (int) ( $job['last_id'] ?? 0 ), $location_id );

		$candidates = is_array( $job['current_candidates'] ?? null ) && array() !== $job['current_candidates']
			? array_values( array_map( 'strval', $job['current_candidates'] ) )
			: $this->candidates_for_location( $location );
		$job['current_candidates'] = $candidates;
		$offset = max( 0, (int) ( $job['candidate_offset'] ?? 0 ) );
		$found = '';

		while ( $offset < count( $candidates ) && (int) $job['step_probes'] < self::MAX_PROBES_PER_STEP ) {
			$candidate = $this->valid_postcode( (string) $candidates[ $offset ] );
			++$offset;
			if ( '' === $candidate ) {
				continue;
			}

			++$job['probes'];
			++$job['step_probes'];
			$result = $this->probe->probe( $candidate );
			if ( ! empty( $result['success'] ) ) {
				$found = $candidate;
				break;
			}

			$job['last_error'] = (string) ( $result['error_message'] ?? '' );
			$job['last_error_code'] = (string) ( $result['error_code'] ?? '' );
		}

		$job['candidate_offset'] = $offset;
		if ( '' !== $found ) {
			$updated = $this->locations->update_russianpost_courier_calc_postal_code_for_postal_code( $base_postal_code, $found, true );
			$job['updated'] = (int) ( $job['updated'] ?? 0 ) + ( $updated > 0 ? 1 : 0 );
			$job['bulk_updated'] = (int) ( $job['bulk_updated'] ?? 0 ) + $updated;
			$job['last_success_postal_code'] = $found;
			$job = $this->finish_location( $job, $location_id );
		} elseif ( $offset >= count( $candidates ) ) {
			++$job['failed'];
			$job = $this->finish_location( $job, $location_id );
		}

		$job['updated_at'] = current_time( 'mysql' );
		return $job;
	}

	public function count_pending(): int {
		if ( property_exists( $this->wpdb, 'rows' ) || property_exists( $this->wpdb, 'locations' ) || property_exists( $this->wpdb, 'location_rows' ) ) {
			$rows = property_exists( $this->wpdb, 'locations' ) ? $this->wpdb->locations : ( property_exists( $this->wpdb, 'location_rows' ) ? $this->wpdb->location_rows : $this->wpdb->rows );
			return count(
				array_filter(
					is_array( $rows ) ? $rows : array(),
					fn( array $row ): bool =>
						1 === (int) ( $row['active'] ?? 1 )
						&& 'RU' === strtoupper( trim( (string) ( $row['country_code'] ?? 'RU' ) ) )
						&& '' !== $this->valid_postcode( (string) ( $row['postal_code'] ?? '' ) )
						&& '' === trim( (string) ( $row['russianpost_courier_calc_postal_code'] ?? '' ) )
				)
			);
		}

		return (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->locations_table()}
			WHERE active = 1
				AND country_code = 'RU'
				AND postal_code IS NOT NULL
				AND postal_code != ''
				AND (russianpost_courier_calc_postal_code IS NULL OR russianpost_courier_calc_postal_code = '')"
		);
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>|null
	 */
	private function next_location( array &$job ): ?array {
		$priority = (string) ( $job['current_priority'] ?? 'cities' );
		$location = $this->locations->next_russianpost_courier_calc_postcode_location( (int) ( $job['last_id'] ?? 0 ), $priority );
		if ( null === $location && 'cities' === $priority ) {
			$job['current_priority'] = 'others';
			$job['last_id'] = 0;
			$location = $this->locations->next_russianpost_courier_calc_postcode_location( 0, 'others' );
		}

		return $location;
	}

	/**
	 * @param array<string,mixed> $location
	 * @return array<int,string>
	 */
	private function candidates_for_location( array $location ): array {
		$base = $this->valid_postcode( (string) ( $location['postal_code'] ?? '' ) );
		$candidates = '' !== $base ? array( $base ) : array();
		foreach ( $this->pickup_postcodes_by_location_id( (int) ( $location['id'] ?? 0 ) ) as $postcode ) {
			$candidates[] = $postcode;
		}
		if ( '' !== trim( (string) ( $location['fias_id'] ?? '' ) ) ) {
			foreach ( $this->pickup_postcodes_by_fias_location_guid( (string) $location['fias_id'] ) as $postcode ) {
				$candidates[] = $postcode;
			}
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( fn( string $postcode ): string => $this->valid_postcode( $postcode ), $candidates ),
					static fn( string $postcode ): bool => '' !== $postcode && '999999999' !== $postcode
				)
			)
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function pickup_postcodes_by_location_id( int $location_id ): array {
		if ( $location_id <= 0 ) {
			return array();
		}
		if ( property_exists( $this->wpdb, 'russian_post_pickup_rows' ) && is_array( $this->wpdb->russian_post_pickup_rows ) ) {
			return $this->pickup_postcodes_from_rows( $this->wpdb->russian_post_pickup_rows, fn( array $row ): bool => $location_id === (int) ( $row['location_id'] ?? 0 ) );
		}
		$rows = $this->wpdb->get_col( $this->wpdb->prepare( "SELECT DISTINCT postcode FROM {$this->pickup_table()} WHERE active = 1 AND location_id = %d ORDER BY postcode ASC", $location_id ) );

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/**
	 * @return array<int,string>
	 */
	private function pickup_postcodes_by_fias_location_guid( string $fias_id ): array {
		$fias_id = trim( $fias_id );
		if ( '' === $fias_id ) {
			return array();
		}
		if ( property_exists( $this->wpdb, 'russian_post_pickup_rows' ) && is_array( $this->wpdb->russian_post_pickup_rows ) ) {
			return $this->pickup_postcodes_from_rows( $this->wpdb->russian_post_pickup_rows, fn( array $row ): bool => $fias_id === (string) ( $row['fias_location_guid'] ?? '' ) );
		}
		$rows = $this->wpdb->get_col( $this->wpdb->prepare( "SELECT DISTINCT postcode FROM {$this->pickup_table()} WHERE active = 1 AND fias_location_guid = %s ORDER BY postcode ASC", $fias_id ) );

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,string>
	 */
	private function pickup_postcodes_from_rows( array $rows, callable $predicate ): array {
		$postcodes = array();
		foreach ( $rows as $row ) {
			if ( 1 === (int) ( $row['active'] ?? 1 ) && $predicate( $row ) ) {
				$postcodes[] = (string) ( $row['postcode'] ?? '' );
			}
		}
		sort( $postcodes );

		return array_values( array_unique( $postcodes ) );
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function finish_location( array $job, int $location_id ): array {
		++$job['processed'];
		$job['last_id'] = max( (int) ( $job['last_id'] ?? 0 ), $location_id );
		$job['candidate_offset'] = 0;
		$job['current_location'] = array();
		$job['current_candidates'] = array();

		return $job;
	}

	private function valid_postcode( string $postcode ): string {
		$postcode = preg_replace( '/\D+/', '', $postcode ) ?? '';
		if ( '' === $postcode || '999999999' === $postcode ) {
			return '';
		}

		return preg_match( '/^\d{6}$/', $postcode ) ? $postcode : '';
	}

	private function locations_table(): string {
		return $this->wpdb->prefix . 'wdc_locations';
	}

	private function pickup_table(): string {
		return $this->wpdb->prefix . 'wdc_pickup_points_russian_post';
	}
}

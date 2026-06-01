<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class RussianPostPickupDiagnosticsService {
	public const DEFAULT_PROBLEM = 'all_problematic';
	public const DEFAULT_PER_PAGE = 50;
	public const DEFAULT_THRESHOLD_KM = 50.0;

	private \wpdb $wpdb;

	/** @var array<int,string> */
	private const PROBLEMS = array(
		'missing_coordinates',
		'zero_coordinates',
		'missing_fias',
		'missing_postal_code',
		'dummy_postal_code',
		'missing_address',
		'missing_city',
		'missing_region',
		'missing_location',
		'suspicious_coordinates',
		'all_problematic',
	);

	/** @var array<string,string> */
	private const CSV_COLUMNS = array(
		'id' => 'ID',
		'point_code' => 'point_code',
		'postcode' => 'postal_code',
		'region_name' => 'region',
		'city_name' => 'city',
		'address' => 'address',
		'fias_location_guid' => 'fias_location_guid',
		'location_id' => 'location_id',
		'latitude' => 'lat',
		'longitude' => 'lng',
		'problem_flags' => 'problem_flags',
		'distance_to_location_km' => 'distance_to_location_km',
		'updated_at' => 'updated_at',
		'last_seen_at' => 'imported_at',
	);

	public function __construct(
		private RussianPostPickupPointRepository $pickup_repository,
		private LocationRepository $location_repository,
		?\wpdb $db = null,
		private float $suspicious_threshold_km = self::DEFAULT_THRESHOLD_KM
	) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	/**
	 * @return array<string,int|float|string|null>
	 */
	public function summary(): array {
		if ( $this->has_test_rows() ) {
			return $this->summary_from_test_rows();
		}

		return array(
			'total' => $this->count_where( '1=1' ),
			'active' => $this->count_where( 'active = 1' ),
			'missing_coordinates' => $this->count_where( '(latitude IS NULL OR longitude IS NULL)' ),
			'zero_coordinates' => $this->count_where( 'latitude = 0 AND longitude = 0' ),
			'missing_fias' => $this->count_where( "(fias_location_guid IS NULL OR TRIM(fias_location_guid) = '')" ),
			'missing_postal_code' => $this->count_where( "(postcode IS NULL OR TRIM(postcode) = '')" ),
			'dummy_postal_code' => $this->count_where( "postcode = '999999999'" ),
			'missing_address' => $this->count_where( "(address IS NULL OR TRIM(address) = '')" ),
			'missing_city' => $this->count_where( "(city_name IS NULL OR TRIM(city_name) = '')" ),
			'missing_region' => $this->count_where( "(region_name IS NULL OR TRIM(region_name) = '')" ),
			'missing_location' => $this->count_where( '(location_id IS NULL OR location_id = 0)' ),
			'suspicious_coordinates' => null,
			'suspicious_coordinates_note' => 'filter_only',
			'suspicious_threshold_km' => $this->suspicious_threshold_km,
		);
	}

	/**
	 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,problem:string}
	 */
	public function list_problematic( string $problem = self::DEFAULT_PROBLEM, int $page = 1, int $per_page = self::DEFAULT_PER_PAGE ): array {
		$problem = $this->normalize_problem( $problem );
		$page = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );

		if ( $this->has_test_rows() ) {
			return $this->list_problematic_from_test_rows( $problem, $page, $per_page );
		}

		$where = $this->problem_where_sql( $problem );
		$total = 'suspicious_coordinates' === $problem ? 0 : $this->count_problem_sql( $problem );
		$offset = ( $page - 1 ) * $per_page;
		$select_sql = 'suspicious_coordinates' === $problem ? $this->select_sql( true ) : $this->select_sql( false );
		$sql = $this->wpdb->prepare(
			$select_sql . " WHERE {$where} ORDER BY rp.updated_at DESC, rp.id ASC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		if ( 'suspicious_coordinates' === $problem ) {
			$total = $offset + count( $rows ) + ( count( $rows ) === $per_page ? $per_page : 0 );
		}

		return array(
			'items' => array_map( fn( array $row ): array => $this->decorate_row( $row ), $rows ),
			'total' => $total,
			'page' => $page,
			'per_page' => $per_page,
			'problem' => $problem,
		);
	}

	public function export_csv( string $problem = self::DEFAULT_PROBLEM ): string {
		$problem = $this->normalize_problem( $problem );
		$handle = fopen( 'php://temp', 'r+' );
		if ( false === $handle ) {
			return '';
		}
		fputcsv( $handle, array_values( self::CSV_COLUMNS ), ',', '"', '' );

		$page = 1;
		do {
			$result = $this->list_problematic( $problem, $page, 100 );
			foreach ( $result['items'] as $row ) {
				$csv_row = array();
				foreach ( array_keys( self::CSV_COLUMNS ) as $column ) {
					$value = $row[ $column ] ?? '';
					$csv_row[] = is_array( $value ) ? implode( '|', array_map( 'strval', $value ) ) : (string) $value;
				}
				fputcsv( $handle, $csv_row, ',', '"', '' );
			}
			$item_count = count( $result['items'] );
			++$page;
		} while ( $item_count === 100 && ( 'suspicious_coordinates' === $problem || ( $page - 1 ) * 100 < $result['total'] ) );

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return is_string( $csv ) ? $csv : '';
	}

	/**
	 * @return array<string,mixed>
	 */
	public function rebind_dry_run( string $problem = self::DEFAULT_PROBLEM, int $limit = 500 ): array {
		return $this->rebind( $problem, false, $limit );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function rebind_apply( string $problem = self::DEFAULT_PROBLEM, int $limit = 500 ): array {
		return $this->rebind( $problem, true, $limit );
	}

	/**
	 * @return array<int,string>
	 */
	public function problems(): array {
		return self::PROBLEMS;
	}

	public function filename(): string {
		$date = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = gmdate( 'Y-m-d' );
		}

		return 'wdc-russian-post-pickup-diagnostics-' . $date . '.csv';
	}

	private function normalize_problem( string $problem ): string {
		$problem = function_exists( 'sanitize_key' ) ? sanitize_key( $problem ) : ( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $problem ) ) ?? '' );

		return in_array( $problem, self::PROBLEMS, true ) ? $problem : self::DEFAULT_PROBLEM;
	}

	private function count_where( string $where ): int {
		return (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->pickup_repository->main_table() . ' WHERE ' . $where );
	}

	private function count_problem_sql( string $problem ): int {
		return (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->pickup_repository->main_table() . ' rp WHERE ' . $this->problem_where_sql( $problem ) );
	}

	private function select_sql( bool $with_location_distance ): string {
		$pickup = $this->pickup_repository->main_table();
		if ( ! $with_location_distance ) {
			return "SELECT rp.*, NULL AS matched_location_id, NULL AS location_latitude, NULL AS location_longitude, NULL AS distance_to_location_km FROM {$pickup} rp";
		}

		$locations = $this->wpdb->prefix . 'wdc_locations';
		$distance = $this->distance_sql();

		return "SELECT rp.*, l.id AS matched_location_id, l.latitude AS location_latitude, l.longitude AS location_longitude, {$distance} AS distance_to_location_km
			FROM {$pickup} rp
			LEFT JOIN {$locations} l ON (
				(rp.location_id IS NOT NULL AND rp.location_id > 0 AND l.id = rp.location_id)
				OR (
					(rp.location_id IS NULL OR rp.location_id = 0)
					AND rp.fias_location_guid IS NOT NULL
					AND rp.fias_location_guid != ''
					AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(l.fias_id, '-', ''), '{', ''), '}', ''), ' ', '')) = LOWER(REPLACE(REPLACE(REPLACE(REPLACE(rp.fias_location_guid, '-', ''), '{', ''), '}', ''), ' ', ''))
				)
			)";
	}

	private function problem_where_sql( string $problem ): string {
		$missing_coordinates = '(rp.latitude IS NULL OR rp.longitude IS NULL)';
		$zero_coordinates = '(rp.latitude = 0 AND rp.longitude = 0)';
		$missing_fias = "(rp.fias_location_guid IS NULL OR TRIM(rp.fias_location_guid) = '')";
		$missing_postal_code = "(rp.postcode IS NULL OR TRIM(rp.postcode) = '')";
		$dummy_postal_code = "rp.postcode = '999999999'";
		$missing_address = "(rp.address IS NULL OR TRIM(rp.address) = '')";
		$missing_city = "(rp.city_name IS NULL OR TRIM(rp.city_name) = '')";
		$missing_region = "(rp.region_name IS NULL OR TRIM(rp.region_name) = '')";
		$missing_location = '(rp.location_id IS NULL OR rp.location_id = 0)';
		$cheap = '(' . implode( ' OR ', array( $missing_coordinates, $zero_coordinates, $missing_fias, $missing_postal_code, $dummy_postal_code, $missing_address, $missing_city, $missing_region, $missing_location ) ) . ')';

		return match ( $problem ) {
			'missing_coordinates' => $missing_coordinates,
			'zero_coordinates' => $zero_coordinates,
			'missing_fias' => $missing_fias,
			'missing_postal_code' => $missing_postal_code,
			'dummy_postal_code' => $dummy_postal_code,
			'missing_address' => $missing_address,
			'missing_city' => $missing_city,
			'missing_region' => $missing_region,
			'missing_location' => $missing_location,
			'suspicious_coordinates' => '(rp.latitude IS NOT NULL AND rp.longitude IS NOT NULL AND rp.latitude != 0 AND rp.longitude != 0 AND l.latitude IS NOT NULL AND l.longitude IS NOT NULL AND l.latitude != 0 AND l.longitude != 0 AND ' . $this->distance_sql() . ' > ' . (float) $this->suspicious_threshold_km . ')',
			default => $cheap,
		};
	}

	private function distance_sql(): string {
		return '(
			6371 * 2 * ASIN(
				SQRT(
					POWER(SIN(RADIANS(CAST(l.latitude AS DECIMAL(10,7)) - CAST(rp.latitude AS DECIMAL(10,7))) / 2), 2)
					+ COS(RADIANS(CAST(rp.latitude AS DECIMAL(10,7))))
					* COS(RADIANS(CAST(l.latitude AS DECIMAL(10,7))))
					* POWER(SIN(RADIANS(CAST(l.longitude AS DECIMAL(10,7)) - CAST(rp.longitude AS DECIMAL(10,7))) / 2), 2)
				)
			)
		)';
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function decorate_row( array $row ): array {
		$row['location_id'] = (int) ( $row['location_id'] ?? $row['matched_location_id'] ?? 0 );
		$row['problem_flags'] = $this->problem_flags( $row );
		if ( null !== ( $row['distance_to_location_km'] ?? null ) && '' !== (string) $row['distance_to_location_km'] ) {
			$row['distance_to_location_km'] = round( (float) $row['distance_to_location_km'], 2 );
		}

		return $row;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<int,string>
	 */
	private function problem_flags( array $row ): array {
		$flags = array();
		if ( ! is_numeric( $row['latitude'] ?? null ) || ! is_numeric( $row['longitude'] ?? null ) ) {
			$flags[] = 'missing_coordinates';
		}
		if ( 0.0 === (float) ( $row['latitude'] ?? -1 ) && 0.0 === (float) ( $row['longitude'] ?? -1 ) ) {
			$flags[] = 'zero_coordinates';
		}
		if ( '' === trim( (string) ( $row['fias_location_guid'] ?? '' ) ) ) {
			$flags[] = 'missing_fias';
		}
		if ( '' === trim( (string) ( $row['postcode'] ?? '' ) ) ) {
			$flags[] = 'missing_postal_code';
		}
		if ( '999999999' === trim( (string) ( $row['postcode'] ?? '' ) ) ) {
			$flags[] = 'dummy_postal_code';
		}
		if ( '' === trim( (string) ( $row['address'] ?? '' ) ) ) {
			$flags[] = 'missing_address';
		}
		if ( '' === trim( (string) ( $row['city_name'] ?? '' ) ) ) {
			$flags[] = 'missing_city';
		}
		if ( '' === trim( (string) ( $row['region_name'] ?? '' ) ) ) {
			$flags[] = 'missing_region';
		}
		if ( (int) ( $row['location_id'] ?? 0 ) <= 0 ) {
			$flags[] = 'missing_location';
		}
		if ( is_numeric( $row['distance_to_location_km'] ?? null ) && (float) $row['distance_to_location_km'] > $this->suspicious_threshold_km ) {
			$flags[] = 'suspicious_coordinates';
		}

		return array_values( array_unique( $flags ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function rebind( string $problem, bool $apply, int $limit ): array {
		$problem = $this->normalize_problem( $problem );
		$limit = max( 1, min( 1000, $limit ) );
		$rows = array();
		$page = 1;
		do {
			$result = $this->list_problematic( $problem, $page, min( 100, $limit ) );
			$rows = array_merge( $rows, $result['items'] );
			++$page;
		} while ( count( $rows ) < $limit && ( $page - 1 ) * $result['per_page'] < $result['total'] );
		$rows = array_slice( $rows, 0, $limit );
		$checked = 0;
		$planned = 0;
		$updated = 0;
		$skipped = array( 'no_match' => 0, 'ambiguous' => 0, 'already_bound' => 0, 'not_safe_scope' => 0 );
		$details = array();

		foreach ( $rows as $row ) {
			++$checked;
			$current_location_id = (int) ( $row['location_id'] ?? 0 );
			$flags = $row['problem_flags'] ?? array();
			$safe_scope = $current_location_id <= 0 || in_array( 'suspicious_coordinates', is_array( $flags ) ? $flags : array(), true );
			if ( ! $safe_scope ) {
				++$skipped['already_bound'];
				continue;
			}

			$candidate = $this->resolve_rebind_candidate( $row );
			if ( 'none' === $candidate['status'] ) {
				++$skipped['no_match'];
				continue;
			}
			if ( 'ambiguous' === $candidate['status'] ) {
				++$skipped['ambiguous'];
				continue;
			}

			$location = $candidate['location'];
			if ( ! $location instanceof Location || null === $location->id || $location->id <= 0 || $location->id === $current_location_id ) {
				++$skipped['not_safe_scope'];
				continue;
			}

			++$planned;
			$details[] = array(
				'point_id' => (int) ( $row['id'] ?? 0 ),
				'point_code' => (string) ( $row['point_code'] ?? '' ),
				'from_location_id' => $current_location_id,
				'to_location_id' => $location->id,
				'strategy' => $candidate['strategy'],
			);

			if ( $apply && $this->update_location_id( (int) ( $row['id'] ?? 0 ), $location->id ) ) {
				++$updated;
			}
		}

		return array(
			'applied' => $apply,
			'checked' => $checked,
			'planned' => $planned,
			'updated' => $updated,
			'skipped' => $skipped,
			'details' => array_slice( $details, 0, 50 ),
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array{status:string,strategy:string,location:Location|null}
	 */
	private function resolve_rebind_candidate( array $row ): array {
		$fias = trim( (string) ( $row['fias_location_guid'] ?? '' ) );
		if ( '' !== $fias ) {
			$location = $this->location_repository->find_by_fias_id( $fias );
			if ( $location instanceof Location ) {
				return array( 'status' => 'unique', 'strategy' => 'fias_location_guid', 'location' => $location );
			}
		}

		$postcode = preg_replace( '/\D+/', '', (string) ( $row['postcode'] ?? '' ) ) ?? '';
		if ( '' !== $postcode && '999999999' !== $postcode ) {
			$candidates = $this->locations_by_postcode( $postcode );
			if ( 1 === count( $candidates ) ) {
				return array( 'status' => 'unique', 'strategy' => 'postal_code', 'location' => $candidates[0] );
			}
			if ( count( $candidates ) > 1 ) {
				return array( 'status' => 'ambiguous', 'strategy' => 'postal_code', 'location' => null );
			}
		}

		$tokens = array_values( array_filter( array( trim( (string) ( $row['region_name'] ?? '' ) ), trim( (string) ( $row['city_name'] ?? '' ) ) ) ) );
		if ( array() !== $tokens ) {
			$candidates = $this->location_repository->search_by_tokens( $tokens, 20, true, '', 'RU' );
			$candidates = array_values(
				array_filter(
					$candidates,
					fn( Location $location ): bool => $this->same_normalized( (string) ( $row['region_name'] ?? '' ), $location->region_name )
						&& $this->same_normalized( (string) ( $row['city_name'] ?? '' ), $location->resolved_place_name() . ' ' . $location->city_name . ' ' . $location->display_name )
				)
			);
			if ( 1 === count( $candidates ) ) {
				return array( 'status' => 'unique', 'strategy' => 'region_city', 'location' => $candidates[0] );
			}
			if ( count( $candidates ) > 1 ) {
				return array( 'status' => 'ambiguous', 'strategy' => 'region_city', 'location' => null );
			}
		}

		return array( 'status' => 'none', 'strategy' => '', 'location' => null );
	}

	/**
	 * @return array<int,Location>
	 */
	private function locations_by_postcode( string $postcode ): array {
		if ( property_exists( $this->wpdb, 'locations' ) && is_array( $this->wpdb->locations ) ) {
			$rows = array_values( array_filter( $this->wpdb->locations, static fn( array $row ): bool => 1 === (int) ( $row['active'] ?? 1 ) && $postcode === (string) ( $row['postal_code'] ?? '' ) ) );

			return array_map( static fn( array $row ): Location => Location::from_array( $row ), $rows );
		}

		$locations = $this->wpdb->prefix . 'wdc_locations';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$locations} WHERE active = 1 AND postal_code = %s LIMIT 2", $postcode ),
			ARRAY_A
		);

		return array_map( static fn( array $row ): Location => Location::from_array( $row ), is_array( $rows ) ? $rows : array() );
	}

	private function update_location_id( int $point_id, int $location_id ): bool {
		if ( $point_id <= 0 || $location_id <= 0 ) {
			return false;
		}
		if ( $this->has_test_rows() ) {
			foreach ( $this->wpdb->russian_post_pickup_rows as $index => $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === $point_id ) {
					$this->wpdb->russian_post_pickup_rows[ $index ]['location_id'] = $location_id;
					return true;
				}
			}
			return false;
		}

		$result = $this->wpdb->update(
			$this->pickup_repository->main_table(),
			array( 'location_id' => $location_id, 'updated_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $point_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	private function same_normalized( string $needle, string $haystack ): bool {
		$needle = $this->normalize_text( $needle );
		$haystack = $this->normalize_text( $haystack );

		return '' !== $needle && '' !== $haystack && ( $needle === $haystack || str_contains( $haystack, $needle ) );
	}

	private function normalize_text( string $value ): string {
		$value = str_replace( array( 'ё', 'Ё' ), array( 'е', 'Е' ), $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = preg_replace( '/[^a-zа-я0-9]+/u', ' ', $value ) ?? $value;

		return trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
	}

	private function has_test_rows(): bool {
		return property_exists( $this->wpdb, 'russian_post_pickup_rows' ) && is_array( $this->wpdb->russian_post_pickup_rows );
	}

	/**
	 * @return array<string,int|float|string|null>
	 */
	private function summary_from_test_rows(): array {
		$rows = $this->wpdb->russian_post_pickup_rows;
		$count = fn( callable $predicate ): int => count( array_filter( $rows, $predicate ) );

		return array(
			'total' => count( $rows ),
			'active' => $count( static fn( array $row ): bool => 1 === (int) ( $row['active'] ?? 0 ) ),
			'missing_coordinates' => $count( static fn( array $row ): bool => ! is_numeric( $row['latitude'] ?? null ) || ! is_numeric( $row['longitude'] ?? null ) ),
			'zero_coordinates' => $count( static fn( array $row ): bool => 0.0 === (float) ( $row['latitude'] ?? -1 ) && 0.0 === (float) ( $row['longitude'] ?? -1 ) ),
			'missing_fias' => $count( static fn( array $row ): bool => '' === trim( (string) ( $row['fias_location_guid'] ?? '' ) ) ),
			'missing_postal_code' => $count( static fn( array $row ): bool => '' === trim( (string) ( $row['postcode'] ?? '' ) ) ),
			'dummy_postal_code' => $count( static fn( array $row ): bool => '999999999' === trim( (string) ( $row['postcode'] ?? '' ) ) ),
			'missing_address' => $count( static fn( array $row ): bool => '' === trim( (string) ( $row['address'] ?? '' ) ) ),
			'missing_city' => $count( static fn( array $row ): bool => '' === trim( (string) ( $row['city_name'] ?? '' ) ) ),
			'missing_region' => $count( static fn( array $row ): bool => '' === trim( (string) ( $row['region_name'] ?? '' ) ) ),
			'missing_location' => $count( static fn( array $row ): bool => (int) ( $row['location_id'] ?? 0 ) <= 0 ),
			'suspicious_coordinates' => null,
			'suspicious_coordinates_note' => 'filter_only',
			'suspicious_threshold_km' => $this->suspicious_threshold_km,
		);
	}

	/**
	 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,problem:string}
	 */
	private function list_problematic_from_test_rows( string $problem, int $page, int $per_page ): array {
		$rows = array_map( fn( array $row ): array => $this->decorate_test_row( $row ), $this->wpdb->russian_post_pickup_rows );
		$rows = array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => 'all_problematic' === $problem
					? array() !== array_values( array_diff( $row['problem_flags'] ?? array(), array( 'suspicious_coordinates' ) ) )
					: in_array( $problem, $row['problem_flags'] ?? array(), true )
			)
		);
		$total = count( $rows );

		return array(
			'items' => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
			'total' => $total,
			'page' => $page,
			'per_page' => $per_page,
			'problem' => $problem,
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function decorate_test_row( array $row ): array {
		$location = null;
		$location_id = (int) ( $row['location_id'] ?? 0 );
		foreach ( $this->wpdb->locations ?? array() as $candidate ) {
			if ( $location_id > 0 && (int) ( $candidate['id'] ?? 0 ) === $location_id ) {
				$location = $candidate;
				break;
			}
			if ( $location_id <= 0 && '' !== trim( (string) ( $row['fias_location_guid'] ?? '' ) ) && $this->normalize_guid( (string) ( $candidate['fias_id'] ?? '' ) ) === $this->normalize_guid( (string) $row['fias_location_guid'] ) ) {
				$location = $candidate;
				break;
			}
		}
		$row['matched_location_id'] = $location['id'] ?? null;
		if ( is_array( $location ) && $this->has_coordinates( $row ) && $this->has_coordinates( array( 'latitude' => $location['latitude'] ?? null, 'longitude' => $location['longitude'] ?? null ) ) ) {
			$row['distance_to_location_km'] = $this->distance_km( (float) $row['latitude'], (float) $row['longitude'], (float) $location['latitude'], (float) $location['longitude'] );
		} else {
			$row['distance_to_location_km'] = '';
		}

		return $this->decorate_row( $row );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function has_coordinates( array $row ): bool {
		return is_numeric( $row['latitude'] ?? null ) && is_numeric( $row['longitude'] ?? null ) && 0.0 !== (float) $row['latitude'] && 0.0 !== (float) $row['longitude'];
	}

	private function distance_km( float $from_lat, float $from_lng, float $to_lat, float $to_lng ): float {
		$earth = 6371.0;
		$d_lat = deg2rad( $to_lat - $from_lat );
		$d_lng = deg2rad( $to_lng - $from_lng );
		$a = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( $from_lat ) ) * cos( deg2rad( $to_lat ) ) * sin( $d_lng / 2 ) ** 2;

		return round( $earth * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) ), 2 );
	}

	private function normalize_guid( string $value ): string {
		return strtolower( preg_replace( '/[^a-f0-9]/i', '', $value ) ?? '' );
	}
}

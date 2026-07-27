<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public string $options = 'wp_options';
		public int $insert_id = 0;
		public string $last_error = '';
		public string $last_query = '';
		public int|false $next_option_delete_result = 0;
		public bool $fail_dpd_stage_finalize_clear = false;
		public bool $fail_dpd_stage_finalize_upsert = false;
		public bool $fail_dpd_stage_finalize_commit = false;
		public bool $fail_dpd_stage_candidate_count = false;
		public bool $fail_dpd_stage_candidate_change_count = false;
		public bool $fail_dpd_stage_stale_count = false;
		/** @var array<int,bool> */
		public array $fail_location_update_ids = array();
		/** @var array<int,array<string,mixed>> */
		public array $delivery_codes = array();
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		/** @var array<string,array<int,array<string,mixed>>> */
		public array $dpd_geography_stage_tables = array();

		public function insert( string $table, array $data, array $format = array() ): bool {
			unset( $table, $format );
			$this->last_error = '';
			foreach ( $this->locations as $row ) {
				if (
					array_key_exists( 'gar_object_id', $data )
					&& null !== $data['gar_object_id']
					&& '' !== (string) $data['gar_object_id']
					&& array_key_exists( 'gar_object_id', $row )
					&& null !== $row['gar_object_id']
					&& (string) $row['gar_object_id'] === (string) $data['gar_object_id']
				) {
					$this->last_error = 'Duplicate entry "' . (string) $data['gar_object_id'] . '" for key "ux_gar_object_id"';
					return false;
				}
				if (
					array_key_exists( 'fias_id', $data )
					&& null !== $data['fias_id']
					&& '' !== (string) $data['fias_id']
					&& array_key_exists( 'fias_id', $row )
					&& null !== $row['fias_id']
					&& (string) $row['fias_id'] === (string) $data['fias_id']
				) {
					$this->last_error = 'Duplicate entry "' . (string) $data['fias_id'] . '" for key "ux_fias_id"';
					return false;
				}
			}
			$ids = array_map( static fn( array $row ): int => (int) ( $row['id'] ?? 0 ), $this->locations );
			$this->insert_id = ( $ids ? max( $ids ) : 0 ) + 1;
			$data['id'] = $this->insert_id;
			$this->locations[] = $data;

			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			unset( $table, $format, $where_format );
			$this->last_error = '';
			$id = (int) ( $where['id'] ?? 0 );
			if ( ! empty( $this->fail_location_update_ids[ $id ] ) ) {
				$this->last_error = 'forced location update failure for id=' . $id;
				return false;
			}
			foreach ( $this->locations as $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === $id ) {
					continue;
				}
				if (
					array_key_exists( 'gar_object_id', $data )
					&& null !== $data['gar_object_id']
					&& '' !== (string) $data['gar_object_id']
					&& array_key_exists( 'gar_object_id', $row )
					&& null !== $row['gar_object_id']
					&& (string) $row['gar_object_id'] === (string) $data['gar_object_id']
				) {
					$this->last_error = 'Duplicate entry "' . (string) $data['gar_object_id'] . '" for key "ux_gar_object_id"';
					return false;
				}
				if (
					array_key_exists( 'fias_id', $data )
					&& null !== $data['fias_id']
					&& '' !== (string) $data['fias_id']
					&& array_key_exists( 'fias_id', $row )
					&& null !== $row['fias_id']
					&& (string) $row['fias_id'] === (string) $data['fias_id']
				) {
					$this->last_error = 'Duplicate entry "' . (string) $data['fias_id'] . '" for key "ux_fias_id"';
					return false;
				}
			}
			foreach ( $this->locations as $index => $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === $id ) {
					$this->locations[ $index ] = array_merge( $row, $data, array( 'id' => $id ) );
					return true;
				}
			}

			return false;
		}

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $replacement, $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function query( string $query ): int|false {
			$this->last_query = $query;
			$this->last_error = '';
			if ( str_starts_with( ltrim( $query ), 'DELETE FROM ' . $this->options ) ) {
				if ( false === $this->next_option_delete_result ) {
					$this->next_option_delete_result = 0;
					$this->last_error = 'forced option delete failure';
					return false;
				}
				if ( 0 !== $this->next_option_delete_result ) {
					$result = $this->next_option_delete_result;
					$this->next_option_delete_result = 0;
					return $result;
				}
				if ( ! preg_match( "/option_name = '([^']+)' AND option_value = '((?:\\\\'|[^'])*)'/", $query, $matches ) ) {
					return 0;
				}
				$name = stripslashes( $matches[1] );
				$value = stripslashes( $matches[2] );
				$current = $GLOBALS['wdc_dpd_import_options'][ $name ] ?? null;
				$current_serialized = maybe_serialize( $current );
				if ( array_key_exists( $name, $GLOBALS['wdc_dpd_import_options'] ?? array() ) && hash_equals( $current_serialized, $value ) ) {
					unset( $GLOBALS['wdc_dpd_import_options'][ $name ] );
					return 1;
				}

				return 0;
			}

			return 1;
		}
	}
}

final class DpdProductionPathWpdb extends wpdb {
	/** @var array<int,array<string,mixed>> */
	public array $candidate_rows = array();
	/** @var array<int,mixed> */
	public array $last_prepare_args = array();
	/** @var array<int,mixed> */
	public array $last_insert_args = array();
	public string $last_sql = '';
	public string $last_query = '';
	public string $last_insert_query = '';

	public function __construct() {
		unset( $this->locations, $this->delivery_codes, $this->dpd_geography_stage_tables );
	}

	public function prepare( string $query, mixed ...$args ): string {
		$this->last_sql = $query;
		$this->last_prepare_args = $args;
		if ( str_starts_with( ltrim( $query ), 'INSERT ' ) ) {
			$this->last_insert_args = $args;
		}
		return $query;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $query, string $output = ARRAY_A ): array {
		unset( $output );
		$this->last_query = $query;
		if ( str_contains( $query, 'SELECT id, gar_object_id' ) ) {
			return array_values(
				array_filter(
					array_map(
						static fn( array $row ): array => array( 'id' => (int) ( $row['id'] ?? 0 ), 'gar_object_id' => (int) ( $row['gar_object_id'] ?? 0 ) ),
						$this->candidate_rows
					),
					static fn( array $row ): bool => (int) $row['gar_object_id'] > 0
				)
			);
		}
		$args = $this->last_prepare_args;
		$country = strtoupper( (string) array_shift( $args ) );
		$tokens = array_map(
			static fn( mixed $arg ): string => trim( str_replace( array( '%', '\\' ), '', (string) $arg ) ),
			$args
		);
		$rows = array();
		foreach ( $this->candidate_rows as $row ) {
			if ( $country !== strtoupper( (string) ( $row['country_code'] ?? '' ) ) ) {
				continue;
			}
			$search = mb_strtolower( strtr( (string) ( $row['searchable_text'] ?? '' ), array( 'Ё' => 'Е', 'ё' => 'е' ) ), 'UTF-8' );
			foreach ( $tokens as $token ) {
				if ( '' !== $token && ! str_contains( $search, $token ) ) {
					continue 2;
				}
			}
			$rows[] = $row;
		}

		return $rows;
	}

	public function query( string $query ): int|false {
		$this->last_query = $query;
		if ( str_starts_with( ltrim( $query ), 'INSERT ' ) ) {
			$this->last_insert_query = $query;
		}
		return 1;
	}
}

final class DpdForeignIdentityLookupWpdb extends wpdb {
	/** @var array<int,array<string,mixed>> */
	public array $foreign_rows = array();
	/** @var array<int,array<string,mixed>> */
	public array $delivery_codes = array();
	/** @var array<string,array<int,array<string,mixed>>> */
	public array $dpd_geography_stage_tables = array();
	/** @var array<int,mixed> */
	public array $last_prepare_args = array();
	public string $identity_mode = 'ok';
	public string $legacy_mode = 'ok';
	public string $last_sql = '';

	public function __construct() {
		unset( $this->locations );
	}

	public function prepare( string $query, mixed ...$args ): string {
		$this->last_sql = $query;
		$this->last_prepare_args = $args;

		return $query;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_results( string $query, string $output = ARRAY_A ): mixed {
		unset( $output );
		$this->last_sql = $query;
		if ( str_contains( $query, 'SELECT id, gar_object_id' ) || ! str_contains( $query, 'REPLACE(LOWER(l.searchable_text)' ) ) {
			$this->last_error = '';
			return array();
		}

		$is_legacy = str_contains( $query, "TRIM(l.district_name) = ''" );
		$mode = $is_legacy ? $this->legacy_mode : $this->identity_mode;
		if ( 'sql_error' === $mode ) {
			$this->last_error = $is_legacy ? 'forced legacy foreign identity SQL failure' : 'forced foreign identity SQL failure';
			return null;
		}
		$this->last_error = '';
		if ( 'non_array' === $mode ) {
			return null;
		}
		if ( 'invalid_row' === $mode ) {
			return array( 'not-an-array-row' );
		}
		if ( 'empty' === $mode ) {
			return array();
		}

		return $this->foreign_rows;
	}

	public function get_row( string $query, mixed $output = null ): ?array {
		unset( $query, $output );
		$id = (int) ( $this->last_prepare_args[0] ?? 0 );
		foreach ( $this->foreign_rows as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $id ) {
				return $row;
			}
		}

		return null;
	}

	public function insert( string $table, array $data, array $format = array() ): bool {
		unset( $table, $format );
		$ids = array_map( static fn( array $row ): int => (int) ( $row['id'] ?? 0 ), $this->foreign_rows );
		$this->insert_id = ( $ids ? max( $ids ) : 0 ) + 1;
		$data['id'] = $this->insert_id;
		$this->foreign_rows[] = $data;
		$this->last_error = '';

		return true;
	}

	public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
		unset( $table, $format, $where_format );
		$id = (int) ( $where['id'] ?? 0 );
		foreach ( $this->foreign_rows as $index => $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $id ) {
				$this->foreign_rows[ $index ] = array_merge( $row, $data, array( 'id' => $id ) );
				$this->last_error = '';
				return true;
			}
		}

		$this->last_error = 'foreign row not found';
		return false;
	}

	public function query( string $query ): int|false {
		$this->last_sql = $query;
		$this->last_error = '';

		return 1;
	}
}

final class DpdDeliveryCodeProductionWpdb extends wpdb {
	/** @var array<int,array<string,mixed>> */
	public array $mapping_rows = array();
	public bool $fail_mapping_lookup = false;
	public mixed $forced_value = null;
	public string $last_sql = '';

	public function __construct() {
		unset( $this->delivery_codes );
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[sd]/', $replacement, $query, 1 ) ?? $query;
		}
		$this->last_sql = $query;

		return $query;
	}

	public function get_var( string $query ): mixed {
		$this->last_sql = $query;
		if ( $this->fail_mapping_lookup ) {
			$this->last_error = 'forced DPD mapping SQL failure';
			return null;
		}
		$this->last_error = '';
		if ( null !== $this->forced_value ) {
			return $this->forced_value;
		}
		if ( ! preg_match( '/dpd_city_id = ([0-9]+)/', $query, $matches ) ) {
			return null;
		}
		$dpd_city_id = (string) $matches[1];
		$ids = array();
		foreach ( $this->mapping_rows as $row ) {
			if ( (string) ( $row['dpd_city_id'] ?? '' ) === $dpd_city_id && (int) ( $row['location_id'] ?? 0 ) > 0 ) {
				$ids[] = (int) $row['location_id'];
			}
		}

		return array() !== $ids ? min( $ids ) : null;
	}
}

final class DpdProductionFinalizationWpdb extends wpdb {
	/** @var array<int,array<string,mixed>> */
	public array $stage_rows = array();
	/** @var array<int,string> */
	public array $queries = array();
	/** @var array<int,array<string,mixed>> */
	private array $transaction_snapshot = array();
	public bool $fail_dpd_stage_finalize_update_existing = false;
	public bool $fail_dpd_stage_finalize_insert_missing = false;

	public function __construct() {
		unset( $this->dpd_geography_stage_tables );
	}

	public function get_var( string $query ): int|string|null {
		$this->last_query = $query;
		$this->last_error = '';
		if ( str_contains( $query, "FROM wp_wdc_dpd_stage_production" ) && str_contains( $query, "status = 'candidate'" ) && ! str_contains( $query, 'LEFT JOIN' ) ) {
			return count( $this->candidate_rows() );
		}
		if ( str_contains( $query, 'LEFT JOIN wp_wdc_location_delivery_codes dc ON dc.location_id = stage.location_id' ) ) {
			$count = 0;
			foreach ( $this->candidate_rows() as $candidate ) {
				$existing = $this->delivery_row_by_location( (int) $candidate['location_id'] );
				if ( null === $existing || null === ( $existing['dpd_city_id'] ?? null ) || (string) $existing['dpd_city_id'] !== (string) $candidate['dpd_city_id'] ) {
					++$count;
				}
			}
			return $count;
		}
		if ( str_contains( $query, 'FROM wp_wdc_location_delivery_codes dc' ) && str_contains( $query, 'LEFT JOIN wp_wdc_dpd_stage_production stage ON stage.location_id = dc.location_id' ) ) {
			$stage_ids = $this->stage_location_ids();
			$count = 0;
			foreach ( $this->delivery_codes as $row ) {
				$location_id = (int) ( $row['location_id'] ?? 0 );
				if ( ! isset( $stage_ids[ $location_id ] ) && null !== ( $row['dpd_city_id'] ?? null ) ) {
					++$count;
				}
			}
			return $count;
		}

		return 0;
	}

	public function query( string $query ): int|false {
		$this->last_query = $query;
		$this->last_error = '';
		$this->queries[] = $query;
		$trimmed = ltrim( $query );
		if ( 'START TRANSACTION' === $trimmed ) {
			$this->transaction_snapshot = $this->delivery_codes;
			return 1;
		}
		if ( 'ROLLBACK' === $trimmed ) {
			$this->delivery_codes = $this->transaction_snapshot;
			return 1;
		}
		if ( 'COMMIT' === $trimmed ) {
			if ( ! empty( $this->fail_dpd_stage_finalize_commit ) ) {
				$this->last_error = 'forced production commit failure';
				return false;
			}
			$this->transaction_snapshot = array();
			return 1;
		}
		if ( str_contains( $query, 'SET dc.dpd_city_id = NULL' ) ) {
			return $this->apply_stale_cleanup( $query );
		}
		if ( str_starts_with( $trimmed, 'UPDATE wp_wdc_location_delivery_codes AS dc' ) ) {
			if ( $this->fail_dpd_stage_finalize_update_existing ) {
				$this->last_error = 'forced production existing update failure';
				return false;
			}
			return $this->apply_existing_update( $query );
		}
		if ( str_starts_with( $trimmed, 'INSERT INTO wp_wdc_location_delivery_codes' ) ) {
			if ( $this->fail_dpd_stage_finalize_insert_missing ) {
				$this->last_error = 'forced production missing insert failure';
				return false;
			}
			return $this->apply_missing_insert( $query );
		}

		return 1;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function candidate_rows(): array {
		return array_values(
			array_filter(
				$this->stage_rows,
				static fn( array $row ): bool => 'candidate' === (string) ( $row['status'] ?? '' ) && null !== ( $row['dpd_city_id'] ?? null )
			)
		);
	}

	/**
	 * @return array<int,bool>
	 */
	private function stage_location_ids(): array {
		$ids = array();
		foreach ( $this->stage_rows as $row ) {
			$ids[ (int) ( $row['location_id'] ?? 0 ) ] = true;
		}
		return $ids;
	}

	private function delivery_row_by_location( int $location_id ): ?array {
		foreach ( $this->delivery_codes as $row ) {
			if ( $location_id === (int) ( $row['location_id'] ?? 0 ) ) {
				return $row;
			}
		}
		return null;
	}

	private function apply_stale_cleanup( string $query ): int {
		$stage_ids = $this->stage_location_ids();
		$updated_at = $this->extract_sql_datetime( $query );
		$changed = 0;
		foreach ( $this->delivery_codes as $index => $row ) {
			$location_id = (int) ( $row['location_id'] ?? 0 );
			if ( ! isset( $stage_ids[ $location_id ] ) && null !== ( $row['dpd_city_id'] ?? null ) ) {
				$this->delivery_codes[ $index ]['dpd_city_id'] = null;
				$this->delivery_codes[ $index ]['updated_at'] = $updated_at;
				++$changed;
			}
		}
		return $changed;
	}

	private function apply_existing_update( string $query ): int {
		$updated_at = $this->extract_sql_datetime( $query );
		$changed = 0;
		foreach ( $this->candidate_rows() as $candidate ) {
			foreach ( $this->delivery_codes as $index => $row ) {
				if ( (int) $candidate['location_id'] !== (int) ( $row['location_id'] ?? 0 ) ) {
					continue;
				}
				if ( (string) ( $row['dpd_city_id'] ?? '' ) === (string) $candidate['dpd_city_id'] ) {
					continue;
				}
				$this->delivery_codes[ $index ]['dpd_city_id'] = (string) $candidate['dpd_city_id'];
				$this->delivery_codes[ $index ]['updated_at'] = $updated_at;
				++$changed;
			}
		}
		return $changed;
	}

	private function apply_missing_insert( string $query ): int {
		$updated_at = $this->extract_sql_datetime( $query );
		$existing_ids = array();
		foreach ( $this->delivery_codes as $row ) {
			$existing_ids[ (int) ( $row['location_id'] ?? 0 ) ] = true;
		}
		$inserted = 0;
		foreach ( $this->candidate_rows() as $candidate ) {
			$location_id = (int) $candidate['location_id'];
			if ( isset( $existing_ids[ $location_id ] ) ) {
				continue;
			}
			$this->delivery_codes[] = array(
				'location_id' => $location_id,
				'dpd_city_id' => (string) $candidate['dpd_city_id'],
				'updated_at' => $updated_at,
			);
			$existing_ids[ $location_id ] = true;
			++$inserted;
		}
		return $inserted;
	}

	private function extract_sql_datetime( string $query ): string {
		if ( preg_match( "/'([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})'/", $query, $matches ) ) {
			return $matches[1];
		}
		return 'production-new';
	}
}

final class DpdIndexQueryFailureWpdb extends wpdb {
	public string $index_mode = 'first_error';
	public int $index_calls = 0;
	/** @var array<int,array<string,mixed>> */
	public array $first_page_rows = array();
	public string $last_sql = '';

	public function __construct() {
		unset( $this->locations );
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[sd]/', $replacement, $query, 1 ) ?? $query;
		}
		$this->last_sql = $query;

		return $query;
	}

	public function get_results( string $query, string $output = ARRAY_A ): mixed {
		unset( $output );
		$this->last_sql = $query;
		if ( ! str_contains( $query, 'FROM wp_wdc_locations' ) ) {
			return array();
		}
		++$this->index_calls;
		if ( 'empty' === $this->index_mode ) {
			$this->last_error = '';
			return array();
		}
		if ( 'non_array' === $this->index_mode ) {
			$this->last_error = '';
			return null;
		}
		if ( 'first_error' === $this->index_mode ) {
			$this->last_error = 'forced first index page SQL failure';
			return null;
		}
		if ( 'second_error' === $this->index_mode ) {
			if ( 1 === $this->index_calls ) {
				$this->last_error = '';
				return $this->first_page_rows;
			}
			$this->last_error = 'forced second index page SQL failure';
			return null;
		}

		$this->last_error = '';
		return array();
	}
}

function current_time( string $type ): string {
	static $tick = 0;
	++$tick;

	return '2026-06-16 12:10:' . str_pad( (string) $tick, 2, '0', STR_PAD_LEFT );
}

function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_import_options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value, bool $autoload = true ): bool { $GLOBALS['wdc_dpd_import_options'][ $name ] = $value; return true; }
function add_option( string $name, mixed $value = '', string $deprecated = '', string|bool $autoload = 'yes' ): bool {
	unset( $deprecated, $autoload );
	if ( array_key_exists( $name, $GLOBALS['wdc_dpd_import_options'] ?? array() ) ) {
		return false;
	}
	$GLOBALS['wdc_dpd_import_options'][ $name ] = $value;
	return true;
}
function delete_option( string $name ): bool {
	$exists = array_key_exists( $name, $GLOBALS['wdc_dpd_import_options'] ?? array() );
	unset( $GLOBALS['wdc_dpd_import_options'][ $name ] );
	return $exists;
}
function wp_generate_uuid4(): string {
	static $seq = 0;
	++$seq;
	return sprintf( '00000000-0000-4000-8000-%012d', $seq );
}
function maybe_serialize( mixed $data ): mixed {
	return is_array( $data ) || is_object( $data ) ? serialize( $data ) : $data;
}
function wp_cache_delete( string $key, string $group = '' ): bool {
	$GLOBALS['wdc_dpd_import_cache_deleted'][] = array( $key, $group );
	return true;
}
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags | JSON_UNESCAPED_UNICODE ); }
function wp_salt( string $scheme = '' ): string { return 'wdc-test-salt-' . $scheme; }
function esc_sql( string $value ): string { return addslashes( $value ); }
function wp_tempnam( string $filename = '' ): string|false {
	unset( $filename );
	$queued = $GLOBALS['wdc_dpd_import_tempnam_queue'] ?? array();
	if ( is_array( $queued ) && array() !== $queued ) {
		$next = array_shift( $queued );
		$GLOBALS['wdc_dpd_import_tempnam_queue'] = $queued;
		return is_string( $next ) ? $next : false;
	}

	return tempnam( sys_get_temp_dir(), 'wdc-dpd-geography-' );
}

require_once __DIR__ . '/../../src/Domain/Status/DeliveryStatus.php';
require_once __DIR__ . '/../../src/Shipments/Cdek/CdekStatusMappingService.php';
require_once __DIR__ . '/../../src/Shipments/Dpd/DpdStatusMapping.php';
require_once __DIR__ . '/../../src/Shipments/YandexDelivery/YandexStatusMapping.php';
require_once __DIR__ . '/../../src/Carriers/Cdek/CdekSettings.php';
require_once __DIR__ . '/../../src/Infrastructure/Settings/SettingsRepository.php';
require_once __DIR__ . '/../../src/Infrastructure/Security/EncryptionService.php';
require_once __DIR__ . '/../../src/Locations/ValueObjects/Location.php';
require_once __DIR__ . '/../../src/Locations/Storage/LocationRepository.php';
require_once __DIR__ . '/../../src/Locations/Storage/LocationDeliveryCodeRepository.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/DpdSettings.php';
require_once __DIR__ . '/../../src/Carriers/YandexDelivery/YandexDeliverySettings.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyImportReport.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyCsvParser.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdLocationIndex.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyMatcher.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyImportStateService.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyImportLockService.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyStageRepository.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyFtpClient.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyImportService.php';

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyCsvParser;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyImportService;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyImportStateService;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyImportLockService;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyFtpClient;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyMatcher;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyStageRepository;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdLocationIndex;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

function dpd_import_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

/**
 * @param array<string,mixed> $state
 */
function dpd_import_assert_public_state_redacted( array $state, string $message ): void {
	foreach ( array( 'file_path', 'index_path', 'stage_table', 'delete_file_on_finish', 'columns', 'index_sha256', 'index_size', 'index_stats', 'index_format_version' ) as $key ) {
		dpd_import_assert( ! array_key_exists( $key, $state ), $message . ': public state contains internal key ' . $key );
	}
}

/**
 * @return array{0:array<string,mixed>,1:DpdGeographyImportStateService}
 */
function dpd_run_lookup_import( DpdForeignIdentityLookupWpdb $db, string $csv, DpdSettings $settings, string $source_file ): array {
	$locations = new LocationRepository( $db );
	$index = new DpdLocationIndex( $locations );
	$state = new DpdGeographyImportStateService();
	$importer = new DpdGeographyImportService(
		new DpdGeographyCsvParser(),
		new DpdGeographyMatcher( $index ),
		$index,
		$state,
		new DpdGeographyStageRepository( $db ),
		$locations,
		new LocationDeliveryCodeRepository( $db ),
		$settings
	);
	$path = tempnam( sys_get_temp_dir(), 'wdc-dpd-lookup-' );
	file_put_contents( $path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
	$report = $importer->import_file( $path, 'cli', $source_file );
	@unlink( $path );

	return array( $report, $state );
}

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wdc_dpd_import_options'] = array();
$GLOBALS['wpdb']->locations = array(
	array(
		'id' => 1,
		'fias_id' => '8DEA00E3-9AAB-4D8E-887C-EF2AAA546456',
		'city_fias_id' => '',
		'gar_id' => '1',
		'gar_object_id' => 1,
		'kladr_id' => '5400000100000',
		'country_code' => 'RU',
		'region_code' => '54',
		'region_name' => 'Новосибирская',
		'district_name' => '',
		'city_name' => 'Новосибирск',
		'city_type' => 'г',
		'settlement_name' => 'Новосибирск',
		'place_name' => 'Новосибирск',
		'place_type' => 'г',
		'display_name' => 'Новосибирск',
		'active' => 1,
	),
	array(
		'id' => 2,
		'fias_id' => '11111111-2222-3333-4444-555555555555',
		'city_fias_id' => '',
		'gar_id' => '2',
		'gar_object_id' => 2,
		'kladr_id' => '5400000200000',
		'country_code' => 'RU',
		'region_code' => '54',
		'region_name' => 'Новосибирская',
		'district_name' => '',
		'city_name' => 'Бердск',
		'city_type' => 'г',
		'settlement_name' => 'Бердск',
		'place_name' => 'Бердск',
		'place_type' => 'г',
		'display_name' => 'Бердск',
		'active' => 1,
	),
	array(
		'id' => 3,
		'fias_id' => '22222222-2222-3333-4444-555555555555',
		'gar_id' => '3',
		'gar_object_id' => 3,
		'kladr_id' => '5400000300000',
		'country_code' => 'RU',
		'region_code' => '54',
		'region_name' => 'Новосибирская',
		'district_name' => '',
		'city_name' => 'Конфликт',
		'city_type' => 'г',
		'settlement_name' => 'Конфликт',
		'place_name' => 'Конфликт',
		'place_type' => 'г',
		'display_name' => 'Конфликт',
		'active' => 1,
	),
	array(
		'id' => 4,
		'fias_id' => '33333333-2222-3333-4444-555555555555',
		'gar_id' => '4',
		'gar_object_id' => 4,
		'kladr_id' => '5400000400000',
		'country_code' => 'RU',
		'region_code' => '54',
		'region_name' => 'Новосибирская',
		'district_name' => 'Один',
		'city_name' => 'Дубль',
		'city_type' => 'с',
		'settlement_name' => 'Дубль',
		'place_name' => 'Дубль',
		'place_type' => 'с',
		'display_name' => 'Дубль',
		'active' => 1,
	),
	array(
		'id' => 5,
		'fias_id' => '44444444-2222-3333-4444-555555555555',
		'gar_id' => '5',
		'gar_object_id' => 5,
		'kladr_id' => '5400000500000',
		'country_code' => 'RU',
		'region_code' => '54',
		'region_name' => 'Новосибирская',
		'district_name' => 'Один',
		'city_name' => 'Дубль',
		'city_type' => 'с',
		'settlement_name' => 'Дубль',
		'place_name' => 'Дубль',
		'place_type' => 'с',
		'display_name' => 'Дубль',
		'active' => 1,
	),
	array(
		'id' => 161634,
		'fias_id' => '',
		'gar_id' => '0',
		'gar_object_id' => 0,
		'kladr_id' => '',
		'country_code' => 'BY',
		'region_code' => '',
		'region_name' => 'Минская',
		'district_name' => '',
		'city_name' => 'Минск2',
		'city_type' => 'г',
		'settlement_name' => 'Минск',
		'settlement_type' => 'г',
		'place_name' => 'Минск',
		'place_type' => 'г',
		'display_name' => 'BY, Минская, Минск2, Минск, 119049',
		'postal_code' => '119049',
		'active' => 1,
	),
);

$csv = implode(
	"\n",
	array(
		'ID НП;Код страны;Регион;Район;Основной город;Населённый пункт;Тип НП;Индекс НП;ФИАС;Код КЛАДР',
		'49455627;RU;Новосибирская;;Новосибирск;Новосибирск;г;630023;8DEA00E3-9AAB-4D8E-887C-EF2AAA546456;RU54000001000',
		'49455627;RU;Новосибирская;;Новосибирск;Новосибирск;г;630024;8DEA00E3-9AAB-4D8E-887C-EF2AAA546456;RU54000001000',
		'70000001;RU;Новосибирская;;Бердск;Бердск;г;633010;;RU54000002000',
		'80000001;RU;Новосибирская;;Конфликт;Конфликт;г;633020;22222222-2222-3333-4444-555555555555;RU54000003000',
		'80000002;RU;Новосибирская;;Конфликт;Конфликт;г;633021;22222222-2222-3333-4444-555555555555;RU54000003000',
		'90000001;RU;Новосибирская;Один;Дубль;Дубль;с;633030;;',
		'196058326;BY;Минская;Минский;Минск2;Минск;г;119049;;BY60011001000',
		'10000001;KZ;Алматы;;Алматы;Алматы;г;050000;;KZ75000000000',
		'10000003;AM;Ереван;;Ереван;Ереван;г;0010;;AM00000000000',
		'10000004;KG;Бишкек;;Бишкек;Бишкек;г;720000;;KG00000000000',
		'196058326;BY;Минская;Минский;Минск2;Минск;г;220000;;BY60011001000',
		'196058326;BY;  Минская  ;Минский;Минск2;Минск;г.;220002;;BY60011001000',
		'196058327;BY;Минская;Другой;Минск2;Минск;г;220001;;BY60011001000',
		'196058328;BY;Берёзовская;Берёзовский;Берёзовка;Берёзовка;с;225210;;BY60011002000',
		'196058328;BY;Березовская;Березовский;Березовка;Березовка;с;225211;;BY60011002000',
		'10000005;UZ;Tashkent;;Tashkent;Tashkent;g;100000;;',
		';RU;Новосибирская;;Пусто;Пусто;г;633040;;',
	)
);
$path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-' );
file_put_contents( $path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );

$settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$repository = new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] );
$location_repository = new LocationRepository( $GLOBALS['wpdb'] );
$index = new DpdLocationIndex( $location_repository );
$state = new DpdGeographyImportStateService();
$stage = new DpdGeographyStageRepository( $GLOBALS['wpdb'] );
$importer = new DpdGeographyImportService(
	new DpdGeographyCsvParser(),
	new DpdGeographyMatcher( $index ),
	$index,
	$state,
	$stage,
	$location_repository,
	$repository,
	$settings
);
$parser = new DpdGeographyCsvParser();

delete_option( DpdGeographyImportLockService::OPTION_NAME );
$lock_service = new DpdGeographyImportLockService();
$lock_token = $lock_service->acquire( 'job-lock-test', 600 );
dpd_import_assert( is_string( $lock_token ) && '' !== $lock_token, 'DPD geography import lock can be acquired.' );
dpd_import_assert( null === $lock_service->acquire( 'job-lock-test', 600 ), 'DPD geography import lock rejects concurrent acquire while lease is active.' );
$lock_service->release( 'wrong-token' );
dpd_import_assert( null === $lock_service->acquire( 'job-lock-test', 600 ), 'DPD geography import lock is not released by a foreign token.' );
$lock_service->release( $lock_token );
dpd_import_assert( is_string( $lock_service->acquire( 'job-lock-test', 600 ) ), 'DPD geography import lock releases with the owner token.' );
delete_option( DpdGeographyImportLockService::OPTION_NAME );
update_option(
	DpdGeographyImportLockService::OPTION_NAME,
	array(
		'job_id' => 'expired-job',
		'token' => 'expired-token',
		'acquired_at' => time() - 700,
		'expires_at' => time() - 1,
	)
);
$expired_takeover_token = $lock_service->acquire( 'job-lock-test', 600 );
dpd_import_assert( is_string( $expired_takeover_token ) && '' !== $expired_takeover_token, 'DPD geography import lock can take over an expired lease.' );
$lock_service->release( $expired_takeover_token );

$compare_and_delete = new ReflectionMethod( DpdGeographyImportLockService::class, 'compare_and_delete' );
$compare_and_delete->setAccessible( true );
$old_payload = array( 'job_id' => 'old', 'token' => 'old-token', 'acquired_at' => time() - 700, 'expires_at' => time() - 1 );
$new_payload = array( 'job_id' => 'new', 'token' => 'new-token', 'acquired_at' => time(), 'expires_at' => time() + 600 );
update_option( DpdGeographyImportLockService::OPTION_NAME, $old_payload );
update_option( DpdGeographyImportLockService::OPTION_NAME, $new_payload );
dpd_import_assert( false === $compare_and_delete->invoke( $lock_service, $old_payload ), 'atomic lock compare-delete does not remove a newer owner payload during expired takeover race.' );
dpd_import_assert( $new_payload === get_option( DpdGeographyImportLockService::OPTION_NAME ), 'new owner lock survives stale expired takeover compare-delete.' );
$lock_service->release( 'old-token' );
dpd_import_assert( $new_payload === get_option( DpdGeographyImportLockService::OPTION_NAME ), 'stale release token does not delete a newer lock owner.' );
$lock_service->release( 'new-token' );
dpd_import_assert( array() === get_option( DpdGeographyImportLockService::OPTION_NAME, array() ), 'owner release deletes the exact current lock payload.' );
update_option( DpdGeographyImportLockService::OPTION_NAME, $new_payload );
$GLOBALS['wpdb']->next_option_delete_result = false;
$delete_failed_closed = false;
try {
	$compare_and_delete->invoke( $lock_service, $new_payload );
} catch ( RuntimeException $exception ) {
	$delete_failed_closed = str_contains( $exception->getMessage(), 'compare-delete failed' );
}
dpd_import_assert( $delete_failed_closed && $new_payload === get_option( DpdGeographyImportLockService::OPTION_NAME ), 'lock compare-delete SQL error fails closed and preserves existing lock.' );
delete_option( DpdGeographyImportLockService::OPTION_NAME );
dpd_import_assert( in_array( array( DpdGeographyImportLockService::OPTION_NAME, 'options' ), $GLOBALS['wdc_dpd_import_cache_deleted'] ?? array(), true ), 'lock compare-delete invalidates the option cache after a successful owner release.' );

$index_sql_db = new DpdIndexQueryFailureWpdb();
$index_sql_repository = new LocationRepository( $index_sql_db );
$index_sql_db->index_mode = 'non_array';
$non_array_failed = false;
try {
	$index_sql_repository->dpd_location_index_rows();
} catch ( RuntimeException $exception ) {
	$non_array_failed = str_contains( $exception->getMessage(), 'invalid SQL result' );
}
dpd_import_assert( $non_array_failed, 'production DPD location index rows reject non-array SQL result without last_error.' );

$index_sql_db->index_mode = 'empty';
$empty_index_rows = $index_sql_repository->dpd_location_index_rows();
dpd_import_assert( array() === $empty_index_rows, 'production DPD location index rows allow a successful empty SQL page.' );
$empty_sql_index = new DpdLocationIndex( $index_sql_repository );
$empty_sql_index->build( 100 );
dpd_import_assert( array( 'fias' => array(), 'kladr' => array(), 'name' => array() ) === DpdLocationIndex::validate_export( $empty_sql_index->export() ), 'legitimate empty DPD location index export remains structurally valid.' );

$first_page_db = new DpdIndexQueryFailureWpdb();
$first_page_db->delivery_codes = $first_page_snapshot = array(
	array( 'location_id' => 1, 'dpd_city_id' => '49455627', 'updated_at' => '2026-06-16 03:00:00' ),
	array( 'location_id' => 2, 'dpd_city_id' => '70000001', 'updated_at' => '2026-06-16 03:00:00' ),
);
$first_page_state = new DpdGeographyImportStateService();
$first_page_stage = new DpdGeographyStageRepository( $first_page_db );
$first_page_locations = new LocationRepository( $first_page_db );
$first_page_codes = new LocationDeliveryCodeRepository( $first_page_db );
$first_page_index = new DpdLocationIndex( $first_page_locations );
$first_page_importer = new DpdGeographyImportService( new DpdGeographyCsvParser(), new DpdGeographyMatcher( $first_page_index ), $first_page_index, $first_page_state, $first_page_stage, $first_page_locations, $first_page_codes, $settings );
$first_page_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-index-page-fail-' );
file_put_contents( $first_page_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$first_page_failed = $first_page_importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $first_page_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$first_page_internal = $first_page_state->current();
dpd_import_assert( 'failed' === (string) ( $first_page_failed['phase'] ?? '' ) && 'error' === (string) ( $first_page_failed['status'] ?? '' ), 'first DPD location index SQL page failure returns failed/error state.' );
dpd_import_assert( str_contains( (string) ( $first_page_failed['last_message'] ?? '' ), 'DPD location index page query failed' ), 'first DPD location index SQL page failure is reported.' );
dpd_import_assert_public_state_redacted( $first_page_failed, 'first DPD location index page failure response is redacted' );
dpd_import_assert( $first_page_snapshot === $first_page_db->delivery_codes, 'first DPD location index page failure leaves working mappings unchanged.' );
dpd_import_assert( 0 === (int) ( $first_page_internal['rows_read'] ?? -1 ) && 0 === (int) ( $first_page_internal['finalized_mappings'] ?? -1 ), 'first DPD location index page failure does not read CSV rows or finalize mappings.' );
dpd_import_assert( '' !== (string) ( $first_page_internal['file_path'] ?? '' ) && file_exists( (string) $first_page_internal['file_path'] ) && ! array_key_exists( (string) ( $first_page_internal['stage_table'] ?? '' ), $first_page_db->dpd_geography_stage_tables ), 'first DPD location index page failure stores copied CSV for reset and creates no stage table.' );
dpd_import_assert( '' === (string) ( $first_page_internal['index_path'] ?? '' ), 'first DPD location index page failure creates no index artifact.' );
$first_page_report = $settings->last_geography_import_report();
dpd_import_assert( 'failed' === (string) ( $first_page_report['phase'] ?? '' ) && 'error' === (string) ( $first_page_report['status'] ?? '' ) && (string) ( $first_page_report['last_message'] ?? '' ) === (string) ( $first_page_failed['last_message'] ?? '' ), 'first DPD location index page failure replaces last report.' );
$first_page_copied_path = (string) $first_page_internal['file_path'];
$first_page_importer->reset();
dpd_import_assert( ! file_exists( $first_page_copied_path ), 'reset removes copied CSV after first index page failure.' );

$second_page_db = new DpdIndexQueryFailureWpdb();
$second_page_db->index_mode = 'second_error';
for ( $i = 1; $i <= 100; ++$i ) {
	$second_page_db->first_page_rows[] = array(
		'id' => $i,
		'country_code' => 'RU',
		'active' => 1,
		'fias_id' => sprintf( 'aaaaaaaa-bbbb-cccc-dddd-%012d', $i ),
		'city_fias_id' => '',
		'kladr_id' => str_pad( (string) $i, 13, '0', STR_PAD_LEFT ),
		'city_kladr_id' => '',
		'region_name' => 'РўРµСЃС‚',
		'district_name' => '',
		'place_name' => 'РўРµСЃС‚ ' . $i,
		'settlement_name' => 'РўРµСЃС‚ ' . $i,
		'city_name' => 'РўРµСЃС‚ ' . $i,
		'place_type' => 'Рі',
		'settlement_type' => 'Рі',
		'city_type' => 'Рі',
	);
}
$second_page_index = new DpdLocationIndex( new LocationRepository( $second_page_db ) );
$second_page_failed = false;
try {
	$second_page_index->build( 100 );
} catch ( RuntimeException $exception ) {
	$second_page_failed = str_contains( $exception->getMessage(), 'DPD location index page query failed' );
}
dpd_import_assert( $second_page_failed && 2 === $second_page_db->index_calls, 'second DPD location index SQL page failure interrupts partial index build.' );

foreach ( array( 'LF' => "\n", 'CRLF' => "\r\n", 'CR' => "\r" ) as $ending_name => $line_ending ) {
	$ending_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-ending-' );
	file_put_contents(
		$ending_path,
		mb_convert_encoding(
			implode(
				$line_ending,
				array(
					'ID НП;Код страны;Регион;Район;Основной город;Населённый пункт;Тип НП;Индекс НП;ФИАС;Код КЛАДР',
					'111;RU;Новосибирская;;Новосибирск;Новосибирск;г;630023;8DEA00E3-9AAB-4D8E-887C-EF2AAA546456;RU54000001000',
					'222;RU;Новосибирская;;Бердск;Бердск;г;633010;;RU54000002000',
				)
			),
			'Windows-1251',
			'UTF-8'
		)
	);
	$ending_header = $parser->inspect_header( $ending_path );
	dpd_import_assert( 'dpd_city_id' === ( $ending_header['columns'][0] ?? '' ), "Windows-1251 {$ending_name} header without BOM is detected correctly" );
	$first_step = $parser->read_step( $ending_path, (int) $ending_header['data_offset'], $ending_header['columns'], 1 );
	$second_step = $parser->read_step( $ending_path, (int) $first_step['new_byte_offset'], $ending_header['columns'], 1 );
	dpd_import_assert( '111' === (string) ( $first_step['rows'][0]['dpd_city_id'] ?? '' ), "read_step reads first {$ending_name} data row" );
	dpd_import_assert( '222' === (string) ( $second_step['rows'][0]['dpd_city_id'] ?? '' ), "read_step resumes from byte_offset for {$ending_name} data row" );
	@unlink( $ending_path );
}

$oversized_header_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-oversized-header-' );
file_put_contents( $oversized_header_path, str_repeat( 'A', 270000 ) );
$oversized_header_failed = false;
try {
	$parser->inspect_header( $oversized_header_path );
} catch ( RuntimeException $exception ) {
	$oversized_header_failed = str_contains( $exception->getMessage(), 'row length' );
}
@unlink( $oversized_header_path );
dpd_import_assert( $oversized_header_failed, 'oversized CSV header without line ending fails safely without memory exhaustion' );

$oversized_row_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-oversized-row-' );
file_put_contents( $oversized_row_path, mb_convert_encoding( "ID НП;Код страны;Регион\n", 'Windows-1251', 'UTF-8' ) . str_repeat( '1', 270000 ) . "\n" );
$oversized_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $oversized_row_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
dpd_import_assert_public_state_redacted( $oversized_job, 'oversized parser job start response is redacted' );
$oversized_step = $importer->step( (string) $oversized_job['job_id'], 1 );
dpd_import_assert( 'failed' === (string) $oversized_step['phase'], 'read_step parser exception becomes failed import state' );
dpd_import_assert( str_contains( (string) $oversized_step['last_message'], 'DPD geography CSV parse failed' ), 'read_step parser exception is reported as diagnostic message: ' . (string) ( $oversized_step['last_message'] ?? '' ) );
dpd_import_assert_public_state_redacted( $oversized_step, 'parser failure response is redacted' );
$oversized_report = $settings->last_geography_import_report();
dpd_import_assert( 'failed' === (string) ( $oversized_report['phase'] ?? '' ) && 'error' === (string) ( $oversized_report['status'] ?? '' ), 'parser failure is saved as terminal failed/error report.' );
dpd_import_assert( (string) ( $oversized_report['last_message'] ?? '' ) === (string) ( $oversized_step['last_message'] ?? '' ), 'parser failure report message matches terminal state.' );
dpd_import_assert( 1 === (int) ( $oversized_step['errors_total'] ?? 0 ) && 1 === (int) ( $oversized_report['errors_total'] ?? 0 ), 'parser fatal failure increments errors_total.' );
$importer->reset();

$index_failure_snapshot = array(
	array( 'location_id' => 1, 'dpd_city_id' => '49455627', 'updated_at' => '2026-06-16 02:00:00' ),
	array( 'location_id' => 2, 'dpd_city_id' => '70000001', 'updated_at' => '2026-06-16 02:00:00' ),
);

$empty_index_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-empty-index-' );
file_put_contents( $empty_index_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$GLOBALS['wpdb']->delivery_codes = $index_failure_snapshot;
$empty_index_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $empty_index_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$empty_index_internal = $state->current();
$empty_index_stage = (string) $empty_index_internal['stage_table'];
$empty_index_import_path = (string) $empty_index_internal['file_path'];
$empty_index_file = (string) $empty_index_internal['index_path'];
file_put_contents( $empty_index_file, '' );
$empty_index_failed = $importer->step( (string) $empty_index_job['job_id'], 1 );
dpd_import_assert( 'failed' === (string) ( $empty_index_failed['phase'] ?? '' ) && 'error' === (string) ( $empty_index_failed['status'] ?? '' ), 'empty serialized index fails import job.' );
dpd_import_assert( str_contains( (string) ( $empty_index_failed['last_message'] ?? '' ), 'location index validation failed' ), 'empty serialized index reports validation failure.' );
dpd_import_assert_public_state_redacted( $empty_index_failed, 'empty index failure response is redacted' );
$empty_index_state = $state->current();
dpd_import_assert( 0 === (int) ( $empty_index_state['rows_read'] ?? -1 ), 'empty index failure does not process CSV rows.' );
dpd_import_assert( 1 === (int) ( $empty_index_state['errors_total'] ?? 0 ) && in_array( (string) ( $empty_index_failed['last_message'] ?? '' ), (array) ( $empty_index_state['errors'] ?? array() ), true ), 'empty index fatal failure increments errors_total and stores diagnostic error.' );
dpd_import_assert( $index_failure_snapshot === $GLOBALS['wpdb']->delivery_codes, 'empty index failure leaves working mappings unchanged.' );
dpd_import_assert( isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $empty_index_stage ] ), 'empty index failure keeps staging table until reset.' );
dpd_import_assert( file_exists( $empty_index_import_path ) && file_exists( $empty_index_file ), 'empty index failure keeps source and index artifacts until reset.' );
$empty_index_report = $settings->last_geography_import_report();
dpd_import_assert( 'failed' === (string) ( $empty_index_report['phase'] ?? '' ) && 'error' === (string) ( $empty_index_report['status'] ?? '' ) && (string) ( $empty_index_report['last_message'] ?? '' ) === (string) ( $empty_index_failed['last_message'] ?? '' ), 'empty index failure is saved as terminal report.' );
dpd_import_assert( 1 === (int) ( $empty_index_report['errors_total'] ?? 0 ), 'empty index failed report contains fatal errors_total.' );
$importer->reset();
dpd_import_assert( ! file_exists( $empty_index_import_path ) && ! file_exists( $empty_index_file ) && ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $empty_index_stage ] ), 'reset removes artifacts after empty index failure.' );

$checksum_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-checksum-index-' );
file_put_contents( $checksum_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$GLOBALS['wpdb']->delivery_codes = $index_failure_snapshot;
$checksum_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $checksum_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$checksum_internal = $state->current();
$checksum_raw = (string) file_get_contents( (string) $checksum_internal['index_path'] );
$checksum_raw[0] = 'a' === $checksum_raw[0] ? 'b' : 'a';
file_put_contents( (string) $checksum_internal['index_path'], $checksum_raw );
$checksum_failed = $importer->step( (string) $checksum_job['job_id'], 1 );
dpd_import_assert( 'failed' === (string) ( $checksum_failed['phase'] ?? '' ) && str_contains( (string) ( $checksum_failed['last_message'] ?? '' ), 'checksum mismatch' ), 'checksum mismatch fails import before CSV processing.' );
dpd_import_assert_public_state_redacted( $checksum_failed, 'checksum failure response is redacted' );
dpd_import_assert( $index_failure_snapshot === $GLOBALS['wpdb']->delivery_codes && 0 === (int) ( $state->current()['rows_read'] ?? -1 ), 'checksum mismatch leaves rows and mappings untouched.' );
$importer->reset();

$invalid_structure_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-invalid-index-' );
file_put_contents( $invalid_structure_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$GLOBALS['wpdb']->delivery_codes = $index_failure_snapshot;
$invalid_structure_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $invalid_structure_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$invalid_structure_internal = $state->current();
$invalid_payload = serialize( array( 'fias' => array(), 'kladr' => 'not-an-array', 'name' => array() ) );
file_put_contents( (string) $invalid_structure_internal['index_path'], $invalid_payload );
$state->update(
	array(
		'index_size' => strlen( $invalid_payload ),
		'index_sha256' => hash( 'sha256', $invalid_payload ),
		'index_stats' => array( 'fias_keys' => 0, 'kladr_keys' => 0, 'name_keys' => 0 ),
	)
);
$invalid_structure_failed = $importer->step( (string) $invalid_structure_job['job_id'], 1 );
dpd_import_assert( 'failed' === (string) ( $invalid_structure_failed['phase'] ?? '' ) && str_contains( (string) ( $invalid_structure_failed['last_message'] ?? '' ), 'invalid' ), 'invalid serialized index structure fails import before CSV processing.' );
dpd_import_assert_public_state_redacted( $invalid_structure_failed, 'invalid structure failure response is redacted' );
dpd_import_assert( $index_failure_snapshot === $GLOBALS['wpdb']->delivery_codes && 0 === (int) ( $state->current()['rows_read'] ?? -1 ), 'invalid index structure leaves rows and mappings untouched.' );
$importer->reset();
$GLOBALS['wpdb']->delivery_codes = array();

$header = $parser->inspect_header( $path );
dpd_import_assert( ! array_key_exists( 'total_rows', $header ), 'inspect_header does not perform a full-row count' );
dpd_import_assert( (int) $header['data_offset'] > 0, 'inspect_header reads only header and returns data offset' );
dpd_import_assert( 'dpd_city_id' === ( $header['columns'][0] ?? '' ) && 'country_code' === ( $header['columns'][1] ?? '' ), 'Windows-1251 header without BOM is detected correctly' );

$busy_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-busy-' );
file_put_contents( $busy_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$busy_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $busy_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$busy_internal = $state->current();
$busy_token = $lock_service->acquire( (string) $busy_internal['job_id'], 600 );
$busy_response = $importer->step( (string) $busy_job['job_id'], 1, (int) $busy_internal['byte_offset'] );
dpd_import_assert( 'busy' === (string) ( $busy_response['step_control']['outcome'] ?? '' ), 'busy DPD geography import lock returns transient busy step control.' );
dpd_import_assert( 0 === (int) ( $state->current()['rows_read'] ?? -1 ) && (int) $busy_internal['byte_offset'] === (int) ( $state->current()['byte_offset'] ?? -1 ), 'busy DPD geography step does not parse rows or advance byte offset.' );
dpd_import_assert( isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ (string) $busy_internal['stage_table'] ] ) && array() === $GLOBALS['wpdb']->dpd_geography_stage_tables[ (string) $busy_internal['stage_table'] ], 'busy DPD geography step does not write staging rows.' );
$lock_service->release( (string) $busy_token );
$importer->reset();

$stale_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-stale-' );
file_put_contents( $stale_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$stale_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $stale_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$stale_internal = $state->current();
$stale_offset = (int) $stale_internal['byte_offset'];
$stale_response = $importer->step( (string) $stale_job['job_id'], 1, max( 0, $stale_offset - 1 ) );
dpd_import_assert( 'stale' === (string) ( $stale_response['step_control']['outcome'] ?? '' ), 'stale expected_byte_offset returns transient stale step control.' );
dpd_import_assert( 0 === (int) ( $state->current()['rows_read'] ?? -1 ) && $stale_offset === (int) ( $state->current()['byte_offset'] ?? -1 ), 'stale expected_byte_offset does not process CSV rows.' );
$first_range = $importer->step( (string) $stale_job['job_id'], 1, $stale_offset );
$after_first_range = $state->current();
dpd_import_assert( 1 === (int) ( $after_first_range['rows_read'] ?? 0 ) && (int) ( $after_first_range['byte_offset'] ?? 0 ) > $stale_offset, 'valid expected_byte_offset processes exactly one range.' );
$duplicate_range = $importer->step( (string) $stale_job['job_id'], 1, $stale_offset );
dpd_import_assert( 'stale' === (string) ( $duplicate_range['step_control']['outcome'] ?? '' ) && 1 === (int) ( $state->current()['rows_read'] ?? 0 ), 'replayed old expected_byte_offset cannot process the same range twice.' );
$importer->reset();

$reset_busy_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-reset-busy-' );
file_put_contents( $reset_busy_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$reset_busy_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $reset_busy_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$reset_busy_internal = $state->current();
$reset_busy_stage = (string) $reset_busy_internal['stage_table'];
$reset_busy_import_path = (string) $reset_busy_internal['file_path'];
$reset_busy_index_path = (string) $reset_busy_internal['index_path'];
$reset_busy_token = $lock_service->acquire( (string) $reset_busy_internal['job_id'], 600 );
$reset_busy_response = $importer->reset();
dpd_import_assert( 'busy' === (string) ( $reset_busy_response['operation_control']['outcome'] ?? '' ), 'reset returns busy while an import step owns the lock.' );
dpd_import_assert( isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $reset_busy_stage ] ) && file_exists( $reset_busy_import_path ) && file_exists( $reset_busy_index_path ), 'busy reset leaves stage, CSV, and index artifacts in place.' );
$lock_service->release( (string) $reset_busy_token );
$reset_after_release = $importer->reset();
dpd_import_assert( 'cancelled' === (string) ( $reset_after_release['phase'] ?? '' ) && ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $reset_busy_stage ] ) && ! file_exists( $reset_busy_import_path ) && ! file_exists( $reset_busy_index_path ), 'reset removes artifacts after import lock is released.' );

$active_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-active-' );
file_put_contents( $active_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$active_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $active_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$active_internal = $state->current();
$active_second_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-active-second-' );
file_put_contents( $active_second_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$active_rejected = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $active_second_path, 'name' => 'SecondGeographyNewDPD.csv' ) );
dpd_import_assert( 'busy' === (string) ( $active_rejected['operation_control']['outcome'] ?? '' ) && (string) $active_internal['job_id'] === (string) $state->current()['job_id'], 'new DPD geography import start is rejected while an active job exists.' );
dpd_import_assert( (string) $active_internal['stage_table'] === (string) $state->current()['stage_table'] && file_exists( (string) $active_internal['file_path'] ), 'rejected active start preserves existing job artifacts.' );
@unlink( $active_second_path );
$importer->reset();

$start_busy_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-start-busy-' );
file_put_contents( $start_busy_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$stage_count_before_start_busy = count( $GLOBALS['wpdb']->dpd_geography_stage_tables );
$start_busy_token = $lock_service->acquire( 'dpd-geography-start', 600 );
$start_busy_response = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $start_busy_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
dpd_import_assert( 'busy' === (string) ( $start_busy_response['operation_control']['outcome'] ?? '' ), 'busy DPD geography start lock returns operation_control busy.' );
dpd_import_assert( file_exists( $start_busy_path ) && $stage_count_before_start_busy === count( $GLOBALS['wpdb']->dpd_geography_stage_tables ), 'busy start does not copy/unlink upload or create a staging table.' );
$lock_service->release( (string) $start_busy_token );
@unlink( $start_busy_path );

$failed_artifact_file = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-failed-artifact-' );
$failed_artifact_index = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-failed-index-' );
file_put_contents( $failed_artifact_file, 'csv' );
file_put_contents( $failed_artifact_index, 'index' );
$failed_artifact_stage = 'wp_wdc_dpd_stage_failed_artifacts';
$GLOBALS['wpdb']->dpd_geography_stage_tables[ $failed_artifact_stage ] = array();
$failed_artifact_state = $state->fail_new(
	'Synthetic failed import with artifacts.',
	array(
		'source' => 'manual',
		'source_file' => 'failed.csv',
		'file_path' => $failed_artifact_file,
		'index_path' => $failed_artifact_index,
		'stage_table' => $failed_artifact_stage,
	)
);
$failed_artifact_start_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-failed-artifact-new-' );
file_put_contents( $failed_artifact_start_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$failed_artifact_rejected = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $failed_artifact_start_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
dpd_import_assert( 'reset_required' === (string) ( $failed_artifact_rejected['operation_control']['outcome'] ?? '' ) && (string) $failed_artifact_state['job_id'] === (string) $state->current()['job_id'], 'failed DPD geography job with internal artifacts requires reset before a new start.' );
dpd_import_assert( file_exists( $failed_artifact_file ) && file_exists( $failed_artifact_index ) && isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $failed_artifact_stage ] ) && file_exists( $failed_artifact_start_path ), 'reset-required start preserves old artifacts and does not consume the new upload.' );
@unlink( $failed_artifact_start_path );
$importer->reset();
dpd_import_assert( ! file_exists( $failed_artifact_file ) && ! file_exists( $failed_artifact_index ) && ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $failed_artifact_stage ] ), 'reset removes failed job artifacts before a new DPD geography start.' );

$state->fail_new( 'Synthetic failed import without artifacts.', array( 'source' => 'manual', 'source_file' => 'no-artifacts.csv', 'delete_file_on_finish' => false ) );
$failed_without_artifacts_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-failed-no-artifacts-' );
file_put_contents( $failed_without_artifacts_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$failed_without_artifacts_start = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $failed_without_artifacts_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
dpd_import_assert( 'ready' === (string) ( $failed_without_artifacts_start['phase'] ?? '' ) && 1 === (int) ( $failed_without_artifacts_start['runner_protocol_version'] ?? 0 ), 'failed DPD geography job without artifacts allows a new start.' );
$importer->reset();

$legacy_file = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-legacy-' );
$legacy_index = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-legacy-index-' );
file_put_contents( $legacy_file, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
file_put_contents( $legacy_index, 'legacy-index' );
$legacy_stage = 'wp_wdc_dpd_stage_legacy';
$GLOBALS['wpdb']->dpd_geography_stage_tables[ $legacy_stage ] = array();
$legacy_state = $state->start(
	array(
		'job_id' => 'legacy-runner-job',
		'phase' => 'importing',
		'source' => 'manual',
		'source_file' => 'legacy.csv',
		'file_path' => $legacy_file,
		'index_path' => $legacy_index,
		'stage_table' => $legacy_stage,
		'delete_file_on_finish' => true,
		'byte_offset' => 45527215,
	)
);
$state->update( array( 'rows_read' => 240000 ) );
$legacy_public = $importer->current_state();
dpd_import_assert( 'reset_required' === (string) ( $legacy_public['operation_control']['outcome'] ?? '' ) && str_contains( (string) ( $legacy_public['last_message'] ?? '' ), 'runner' ), 'legacy DPD geography runner job requires reset in current_state.' );
$legacy_step = $importer->step( 'legacy-runner-job', 1, 45527215 );
dpd_import_assert( 'reset_required' === (string) ( $legacy_step['operation_control']['outcome'] ?? '' ) && 240000 === (int) ( $state->current()['rows_read'] ?? 0 ), 'legacy DPD geography runner job does not process CSV rows on step.' );
$importer->reset();
dpd_import_assert( ! file_exists( $legacy_file ) && ! file_exists( $legacy_index ) && ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $legacy_stage ] ), 'reset removes legacy runner artifacts without migrating progress.' );

$revision_a = $state->start( array( 'job_id' => 'revision-a', 'phase' => 'ready', 'source' => 'manual', 'source_file' => 'a.csv', 'runner_protocol_version' => 1 ) );
$revision_reset = $state->reset();
$revision_b = $state->start( array( 'job_id' => 'revision-b', 'phase' => 'ready', 'source' => 'manual', 'source_file' => 'b.csv', 'runner_protocol_version' => 1 ) );
$revision_failed = $state->fail_new( 'revision failure', array( 'source' => 'manual' ) );
$revision_update = $state->update( array( 'last_message' => 'revision update' ) );
dpd_import_assert( (int) $revision_a['state_revision'] < (int) $revision_reset['state_revision'] && (int) $revision_reset['state_revision'] < (int) $revision_b['state_revision'] && (int) $revision_b['state_revision'] < (int) $revision_failed['state_revision'] && (int) $revision_failed['state_revision'] < (int) $revision_update['state_revision'], 'DPD geography state_revision stays globally monotonic across start, reset, fail_new, and update.' );
$importer->reset();

$job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$internal = $state->current();
$stage_table = (string) $internal['stage_table'];
$import_path = (string) $internal['file_path'];
$upload_index_path = (string) $internal['index_path'];
dpd_import_assert( 'ready' === (string) $job['phase'], 'start creates ready import job' );
dpd_import_assert( '' !== $stage_table && isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ] ), 'start creates staging table' );
dpd_import_assert_public_state_redacted( $job, 'start public state hides internal paths and index metadata' );
dpd_import_assert( true === (bool) $internal['delete_file_on_finish'], 'manual upload marks imported temp file for deletion' );
dpd_import_assert( 1 === (int) ( $internal['index_format_version'] ?? 0 ) && (int) ( $internal['index_size'] ?? 0 ) > 0 && preg_match( '/^[a-f0-9]{64}$/', (string) ( $internal['index_sha256'] ?? '' ) ), 'internal state stores serialized index integrity metadata' );
dpd_import_assert( is_array( $internal['index_stats'] ?? null ) && (int) ( $internal['index_stats']['fias_keys'] ?? 0 ) > 0 && (int) ( $internal['index_stats']['kladr_keys'] ?? 0 ) > 0 && (int) ( $internal['index_stats']['name_keys'] ?? 0 ) > 0, 'internal state stores serialized index stats' );
dpd_import_assert( (int) $internal['file_size'] > 0, 'start stores file_size for progress' );
dpd_import_assert( 0 === (int) $internal['total_rows'], 'start does not pre-count total CSV rows' );
dpd_import_assert( (float) $job['percent_complete'] > 0, 'start progress is calculated from byte_offset and file_size' );
dpd_import_assert( ! array_key_exists( 'seen_mappings', $internal ) && ! array_key_exists( 'saved_by_job', $internal ) && ! array_key_exists( 'blocked_locations', $internal ), 'state does not contain large in-memory mapping arrays' );

$job = $importer->step( (string) $job['job_id'], 1 );
dpd_import_assert( array() === $GLOBALS['wpdb']->delivery_codes, 'step import does not write directly to working delivery codes table' );
dpd_import_assert( isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ][1] ), 'candidate is saved in staging table' );
dpd_import_assert( 'candidate' === $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ][1]['status'], 'staged candidate has candidate status' );

$job = $importer->step( (string) $job['job_id'], 4 );
dpd_import_assert( isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ][3] ), 'conflicted location exists in staging table' );
dpd_import_assert( 'conflict' === $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ][3]['status'], 'different DPD city IDs mark staged row as conflict' );
dpd_import_assert( null === $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ][3]['dpd_city_id'], 'conflict clears staged dpd_city_id' );
dpd_import_assert( array() === $GLOBALS['wpdb']->delivery_codes, 'working delivery codes table remains unchanged before finalization' );

while ( in_array( (string) ( $job['phase'] ?? '' ), array( 'ready', 'importing' ), true ) ) {
	$job = $importer->step( (string) $job['job_id'], 10000 );
}
$report = $settings->last_geography_import_report();

dpd_import_assert( 0 === (int) $report['total_rows'], 'import does not pre-count data rows' );
dpd_import_assert( (int) $report['file_size'] > 0, 'report stores source file size' );
dpd_import_assert( 7 === (int) $report['ru_rows'], 'import processes RU rows only' );
dpd_import_assert( 9 === (int) ( $report['foreign_rows'] ?? 0 ) && 6 === (int) ( $report['foreign_by_rows'] ?? 0 ) && 1 === (int) $report['skipped_non_ru'], 'import counts supported AM/BY/KZ/KG foreign rows and skips unsupported UZ' );
dpd_import_assert( 5 === (int) ( $report['foreign_locations_inserted'] ?? 0 ) && 4 === (int) ( $report['foreign_locations_updated'] ?? 0 ), 'foreign DPD import inserts distinct locations and updates repeated or legacy same-place rows; inserted=' . (string) ( $report['foreign_locations_inserted'] ?? '' ) . ' updated=' . (string) ( $report['foreign_locations_updated'] ?? '' ) );
dpd_import_assert( 0 === (int) ( $report['foreign_save_failed'] ?? 0 ) && 0 === (int) ( $report['errors_total'] ?? 0 ), 'foreign DPD imports do not hit unique GAR/FIAS SQL failures' );
dpd_import_assert( 1 === (int) $report['skipped_invalid'], 'import skips rows without DPD city ID' );
dpd_import_assert( 4 === (int) $report['matched_by_fias'], 'FIAS exact matches are counted before staging conflict filtering' );
dpd_import_assert( 1 === (int) $report['matched_by_kladr'], 'KLADR normalized match is saved' );
dpd_import_assert( 9 === (int) $report['saved_candidates'], 'non-conflicting RU and foreign rows are staged as candidates before finalization' );
dpd_import_assert( 8 === (int) $report['finalized_mappings'] && 8 === (int) ( $report['finalized_changes'] ?? 0 ), 'RU candidates and foreign imports are finalized into working delivery codes table with candidate and change counters' );
dpd_import_assert( 4 === (int) $report['unchanged_mappings'], 'duplicate same DPD city ID is idempotent for RU and foreign rows' );
dpd_import_assert( 1 === (int) $report['conflicts'], 'different DPD city IDs for one location are treated as conflict' );
dpd_import_assert( 1 === (int) $report['ambiguous'], 'ambiguous name match is not saved' );
dpd_import_assert( '49455627' === $repository->get_dpd_city_id( 1 ), 'FIAS match writes dpd_city_id' );
dpd_import_assert( '70000001' === $repository->get_dpd_city_id( 2 ), 'KLADR normalized match writes dpd_city_id' );
dpd_import_assert( null === $repository->get_dpd_city_id( 3 ), 'conflicted mapping is not saved' );
dpd_import_assert( null === $repository->get_dpd_city_id( 4 ) && null === $repository->get_dpd_city_id( 5 ), 'ambiguous name mapping is not saved' );
$foreign_locations = array_values( array_filter( $GLOBALS['wpdb']->locations, static fn( array $row ): bool => in_array( (string) ( $row['country_code'] ?? '' ), array( 'AM', 'BY', 'KZ', 'KG' ), true ) ) );
dpd_import_assert( 6 === count( $foreign_locations ), 'AM/BY/KZ/KG foreign locations are created in canonical locations table and same names in different districts stay separate.' );
foreach ( $foreign_locations as $foreign_row ) {
	dpd_import_assert( null === ( $foreign_row['gar_object_id'] ?? null ) && null === ( $foreign_row['fias_id'] ?? null ), 'foreign locations persist missing GAR/FIAS as SQL NULL-compatible values.' );
	dpd_import_assert( '' === (string) ( $foreign_row['gar_id'] ?? '' ) && '' === (string) ( $foreign_row['kladr_id'] ?? '' ), 'foreign locations do not persist fake GAR/KLADR values.' );
	dpd_import_assert( '' === (string) ( $foreign_row['postal_code'] ?? '' ), 'foreign locations do not persist DPD postal_code as canonical postcode.' );
}
$minsk_rows = array_values( array_filter( $foreign_locations, static fn( array $row ): bool => 'BY' === (string) ( $row['country_code'] ?? '' ) && 'Минск' === (string) ( $row['place_name'] ?? '' ) ) );
dpd_import_assert( 2 === count( $minsk_rows ), 'same foreign place name in different districts creates two canonical locations.' );
$minsk_main = null;
foreach ( $minsk_rows as $row ) {
	if ( 'Минский' === (string) ( $row['district_name'] ?? '' ) ) {
		$minsk_main = $row;
		break;
	}
}
dpd_import_assert( is_array( $minsk_main ), 'production BY Minsk fixture is imported.' );
dpd_import_assert( 161634 === (int) ( $minsk_main['id'] ?? 0 ), 'production malformed BY Minsk row is updated instead of duplicated.' );
dpd_import_assert( 'Минская' === (string) ( $minsk_main['region_name'] ?? '' ) && 'обл.' === (string) ( $minsk_main['region_type'] ?? '' ), 'production BY Minsk region and region_type are mapped.' );
dpd_import_assert( 'Минский' === (string) ( $minsk_main['district_name'] ?? '' ) && 'р-н' === (string) ( $minsk_main['district_type'] ?? '' ), 'production BY Minsk district and district_type are mapped.' );
dpd_import_assert( 'Минск' === (string) ( $minsk_main['city_name'] ?? '' ) && 'г' === (string) ( $minsk_main['city_type'] ?? '' ), 'production BY Minsk uses settlement as city instead of main_city=Минск2.' );
dpd_import_assert( 'Минск' === (string) ( $minsk_main['settlement_name'] ?? '' ) && 'г' === (string) ( $minsk_main['settlement_type'] ?? '' ), 'production BY Minsk settlement fields are canonical.' );
dpd_import_assert( 'Минск' === (string) ( $minsk_main['place_name'] ?? '' ) && 'г' === (string) ( $minsk_main['place_type'] ?? '' ), 'production BY Minsk place fields are canonical.' );
dpd_import_assert( 'Минская обл., Минский р-н, г Минск' === (string) ( $minsk_main['display_name'] ?? '' ), 'production BY Minsk display_name omits country prefix and keeps region/district/type.' );
dpd_import_assert( false === str_contains( (string) ( $minsk_main['display_name'] ?? '' ), 'Минск2' ) && false === str_contains( (string) ( $minsk_main['display_name'] ?? '' ), '119049' ), 'production BY Minsk display_name excludes DPD main_city and postal_code.' );
foreach ( array( '10000001', '196058326', '10000003', '10000004', '196058327' ) as $foreign_dpd_id ) {
	$foreign_location_id = $repository->find_location_id_by_dpd_city_id( $foreign_dpd_id );
	dpd_import_assert( null !== $foreign_location_id && $foreign_dpd_id === $repository->get_dpd_city_id( $foreign_location_id ), 'foreign DPD mapping survives finalization for ' . $foreign_dpd_id );
}
$berezovka_rows = array_values( array_filter( $foreign_locations, static fn( array $row ): bool => 'BY' === (string) ( $row['country_code'] ?? '' ) && in_array( (string) ( $row['place_name'] ?? '' ), array( 'Берёзовка', 'Березовка' ), true ) ) );
dpd_import_assert( 1 === count( $berezovka_rows ), 'Ё/Е foreign identity variants do not create duplicate locations.' );
dpd_import_assert( null !== $repository->find_location_id_by_dpd_city_id( '196058328' ), 'Ё/Е foreign identity mapping survives finalization.' );
dpd_import_assert( null === $repository->find_location_id_by_dpd_city_id( '10000005' ), 'unsupported UZ foreign row does not create a DPD mapping.' );
dpd_import_assert( array() !== $settings->last_geography_import_report(), 'last import report is stored in settings' );
$success_state = $state->current();
$success_report = $settings->last_geography_import_report();
dpd_import_assert( 'finished' === $success_state['phase'] && 'success' === (string) ( $success_state['status'] ?? '' ), 'step import finishes job state with success status' );
dpd_import_assert( 'finished' === (string) ( $success_report['phase'] ?? '' ) && 'success' === (string) ( $success_report['status'] ?? '' ), 'success last report is saved from terminal state' );
dpd_import_assert( (string) ( $success_state['finished_at'] ?? '' ) === (string) ( $success_report['finished_at'] ?? '' ) && (string) ( $success_state['last_message'] ?? '' ) === (string) ( $success_report['last_message'] ?? '' ), 'success report terminal timestamps and message match state' );
dpd_import_assert( empty( $success_report['stale_cleanup_skipped'] ) && 0 === (int) ( $success_report['stale_cleared'] ?? -1 ), 'clean import records stale cleanup as enabled with no stale rows in the empty working table.' );
$working_after_success = $GLOBALS['wpdb']->delivery_codes;
dpd_import_assert( ! file_exists( $import_path ), 'import temp file is deleted on finish' );
dpd_import_assert( ! file_exists( $upload_index_path ), 'serialized index file is deleted on finish' );
dpd_import_assert( ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $stage_table ] ), 'staging table is deleted on finish' );

$invalid_upload = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'name' => 'broken.csv' ) );
$invalid_upload_report = $settings->last_geography_import_report();
dpd_import_assert( 'failed' === (string) ( $invalid_upload['phase'] ?? '' ) && 'error' === (string) ( $invalid_upload['status'] ?? '' ), 'invalid manual upload returns failed/error state.' );
dpd_import_assert_public_state_redacted( $invalid_upload, 'invalid upload failure response is redacted' );
dpd_import_assert( 'manual' === (string) ( $invalid_upload_report['source'] ?? '' ) && 0 === (int) ( $invalid_upload_report['ru_rows'] ?? -1 ) && 0 === (int) ( $invalid_upload_report['finalized_mappings'] ?? -1 ), 'invalid upload failed report starts from zero counters instead of previous success counters.' );
dpd_import_assert( 'failed' === (string) ( $invalid_upload_report['phase'] ?? '' ) && 'error' === (string) ( $invalid_upload_report['status'] ?? '' ), 'invalid upload replaces previous success report with terminal failed/error report.' );
dpd_import_assert( 1 === (int) ( $invalid_upload_report['errors_total'] ?? 0 ), 'fail_new keeps errors_total=1 for invalid upload.' );

$copy_failure_target = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-copy-target-' );
file_put_contents( $copy_failure_target, 'partial target' );
$GLOBALS['wdc_dpd_import_tempnam_queue'] = array( $copy_failure_target );
$copy_failure_source = sys_get_temp_dir();
$copy_failure = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $copy_failure_source, 'name' => 'broken-copy.csv' ) );
$copy_failure_report = $settings->last_geography_import_report();
dpd_import_assert( 'failed' === (string) ( $copy_failure['phase'] ?? '' ) && 'error' === (string) ( $copy_failure['status'] ?? '' ), 'manual upload copy failure returns failed/error state.' );
dpd_import_assert( str_contains( (string) ( $copy_failure['last_message'] ?? '' ), 'unable to copy uploaded CSV' ), 'manual upload copy failure uses sanitized public message.' );
dpd_import_assert_public_state_redacted( $copy_failure, 'copy failure response is redacted' );
dpd_import_assert( 'failed' === (string) ( $copy_failure_report['phase'] ?? '' ) && 'error' === (string) ( $copy_failure_report['status'] ?? '' ) && 0 === (int) ( $copy_failure_report['finalized_mappings'] ?? -1 ), 'copy failure replaces previous report with zero-counter failed/error report.' );
dpd_import_assert( ! file_exists( $copy_failure_target ) && is_dir( $copy_failure_source ), 'copy failure removes created target temp file and leaves original source untouched.' );

$warning_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-warning-' );
file_put_contents( $warning_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$GLOBALS['wpdb']->delivery_codes = array(
	array( 'location_id' => 161634, 'dpd_city_id' => '196058326', 'updated_at' => '2026-06-16 00:00:00' ),
	array( 'location_id' => 777, 'dpd_city_id' => '77777777', 'updated_at' => '2026-06-16 00:00:00' ),
);
$GLOBALS['wpdb']->fail_location_update_ids[161634] = true;
$warning_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $warning_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
while ( in_array( (string) ( $warning_job['phase'] ?? '' ), array( 'ready', 'importing' ), true ) ) {
	$warning_job = $importer->step( (string) $warning_job['job_id'], 10000 );
}
unset( $GLOBALS['wpdb']->fail_location_update_ids[161634] );
$warning_report = $settings->last_geography_import_report();
dpd_import_assert( 'finished' === (string) ( $warning_job['phase'] ?? '' ) && 'warning' === (string) ( $warning_job['status'] ?? '' ), 'row-level foreign save errors finish import as warning.' );
dpd_import_assert( ! empty( $warning_report['stale_cleanup_skipped'] ) && 0 === (int) ( $warning_report['stale_cleared'] ?? -1 ), 'warning import skips stale cleanup and reports zero stale clears.' );
dpd_import_assert( '196058326' === $repository->get_dpd_city_id( 161634 ) && '77777777' === $repository->get_dpd_city_id( 777 ), 'warning import preserves prior working mappings missing from staging.' );
dpd_import_assert( '49455627' === $repository->get_dpd_city_id( 1 ), 'warning import still applies successful candidate mappings.' );
dpd_import_assert( (int) ( $warning_report['errors_total'] ?? 0 ) > 0 && (int) ( $warning_report['foreign_save_failed'] ?? 0 ) > 0, 'warning report includes row-level error counters.' );
dpd_import_assert( (string) ( $warning_report['phase'] ?? '' ) === (string) ( $warning_job['phase'] ?? '' ) && (string) ( $warning_report['status'] ?? '' ) === (string) ( $warning_job['status'] ?? '' ), 'warning report matches terminal state.' );

$conflict_stage = $stage->table_name_for_job( 'conflict-preservation' );
$stage->create( $conflict_stage );
$GLOBALS['wpdb']->delivery_codes = array(
	array( 'location_id' => 3, 'dpd_city_id' => '80000001', 'updated_at' => '2026-06-16 00:00:00' ),
	array( 'location_id' => 999, 'dpd_city_id' => '99999999', 'updated_at' => '2026-06-16 00:00:00' ),
);
dpd_import_assert( 'inserted' === $stage->upsert_candidate( $conflict_stage, 3, '80000001', 'fias' ), 'conflict preservation setup inserts first candidate.' );
dpd_import_assert( 'conflict' === $stage->upsert_candidate( $conflict_stage, 3, '80000002', 'fias' ), 'conflict preservation setup creates conflict row.' );
$conflict_finalize = $stage->finalize_into_delivery_codes( $conflict_stage );
dpd_import_assert( 0 === (int) $conflict_finalize['mappings'] && 1 === (int) $conflict_finalize['changes'] && 1 === (int) ( $conflict_finalize['stale_cleared'] ?? 0 ) && empty( $conflict_finalize['stale_cleanup_skipped'] ), 'conflict rows are excluded from finalized mappings and changes while clean stale rows are cleared.' );
dpd_import_assert( '80000001' === $repository->get_dpd_city_id( 3 ), 'conflict stage row preserves existing working mapping.' );
dpd_import_assert( null === $repository->get_dpd_city_id( 999 ), 'stale mapping is cleared only when location_id is absent from stage.' );
$stage->drop( $conflict_stage );

$failure_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-finalize-failure-' );
file_put_contents( $failure_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$failure_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $failure_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$failure_state = $state->current();
$failure_stage = (string) $failure_state['stage_table'];
$GLOBALS['wpdb']->fail_dpd_stage_finalize_clear = true;
while ( in_array( (string) ( $failure_job['phase'] ?? '' ), array( 'ready', 'importing' ), true ) ) {
	$failure_job = $importer->step( (string) $failure_job['job_id'], 10000 );
}
$GLOBALS['wpdb']->fail_dpd_stage_finalize_clear = false;
dpd_import_assert( 'failed' === (string) $state->current()['phase'], 'finalization SQL failure marks import job failed' );
dpd_import_assert( str_contains( (string) $state->current()['last_message'], 'DPD geography finalization failed' ), 'finalization SQL failure reports diagnostic message' );
dpd_import_assert_public_state_redacted( $failure_job, 'clear finalization failure response is redacted' );
dpd_import_assert( isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $failure_stage ] ), 'failed finalization keeps staging table for reset/retry diagnostics' );
$failure_report = $settings->last_geography_import_report();
dpd_import_assert( 'error' === (string) ( $state->current()['status'] ?? '' ) && 'error' === (string) ( $failure_report['status'] ?? '' ), 'clear failure state and report use error status.' );
$importer->reset();
dpd_import_assert( ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $failure_stage ] ), 'reset clears failed finalization staging table' );

$GLOBALS['wpdb']->delivery_codes = $success_snapshot = array(
	array( 'location_id' => 1, 'dpd_city_id' => '49455627', 'updated_at' => '2026-06-16 01:00:00' ),
	array( 'location_id' => 777, 'dpd_city_id' => '77777777', 'updated_at' => '2026-06-16 01:00:00' ),
);
$upsert_failure_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-upsert-failure-' );
file_put_contents( $upsert_failure_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$upsert_failure_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $upsert_failure_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$upsert_failure_stage = (string) $state->current()['stage_table'];
$GLOBALS['wpdb']->fail_dpd_stage_finalize_upsert = true;
while ( in_array( (string) ( $upsert_failure_job['phase'] ?? '' ), array( 'ready', 'importing' ), true ) ) {
	$upsert_failure_job = $importer->step( (string) $upsert_failure_job['job_id'], 10000 );
}
$GLOBALS['wpdb']->fail_dpd_stage_finalize_upsert = false;
dpd_import_assert( 'failed' === (string) $state->current()['phase'] && 'error' === (string) ( $state->current()['status'] ?? '' ), 'upsert failure after clear marks import failed/error.' );
dpd_import_assert_public_state_redacted( $upsert_failure_job, 'upsert finalization failure response is redacted' );
dpd_import_assert( $success_snapshot === $GLOBALS['wpdb']->delivery_codes, 'upsert failure rolls back clear and preserves working delivery_codes snapshot.' );
dpd_import_assert( isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $upsert_failure_stage ] ), 'upsert failure keeps staging table for diagnostics.' );
dpd_import_assert( 'failed' === (string) ( $settings->last_geography_import_report()['phase'] ?? '' ) && 'error' === (string) ( $settings->last_geography_import_report()['status'] ?? '' ), 'upsert failure report is terminal failed/error.' );
$importer->reset();

$GLOBALS['wpdb']->delivery_codes = $count_failure_snapshot = array(
	array( 'location_id' => 1, 'dpd_city_id' => '49455627', 'updated_at' => '2026-06-16 01:10:00' ),
	array( 'location_id' => 777, 'dpd_city_id' => '77777777', 'updated_at' => '2026-06-16 01:10:00' ),
);
$count_failure_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-count-failure-' );
file_put_contents( $count_failure_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$count_failure_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $count_failure_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$count_failure_stage = (string) $state->current()['stage_table'];
$GLOBALS['wpdb']->fail_dpd_stage_candidate_change_count = true;
while ( in_array( (string) ( $count_failure_job['phase'] ?? '' ), array( 'ready', 'importing' ), true ) ) {
	$count_failure_job = $importer->step( (string) $count_failure_job['job_id'], 10000 );
}
$GLOBALS['wpdb']->fail_dpd_stage_candidate_change_count = false;
dpd_import_assert( 'failed' === (string) ( $count_failure_job['phase'] ?? '' ) && 'error' === (string) ( $count_failure_job['status'] ?? '' ), 'candidate-change count SQL failure marks import failed/error.' );
dpd_import_assert( str_contains( (string) ( $count_failure_job['last_message'] ?? '' ), 'candidate change count failed' ), 'candidate-change count SQL failure is reported.' );
dpd_import_assert_public_state_redacted( $count_failure_job, 'count finalization failure response is redacted' );
dpd_import_assert( $count_failure_snapshot === $GLOBALS['wpdb']->delivery_codes, 'count SQL failure leaves working mappings unchanged.' );
dpd_import_assert( isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $count_failure_stage ] ), 'count SQL failure keeps staging table for diagnostics.' );
dpd_import_assert( 'failed' === (string) ( $settings->last_geography_import_report()['phase'] ?? '' ) && 'error' === (string) ( $settings->last_geography_import_report()['status'] ?? '' ), 'count SQL failure report is terminal failed/error.' );
$importer->reset();

$commit_stage = $stage->table_name_for_job( 'commit-failure' );
$stage->create( $commit_stage );
$GLOBALS['wpdb']->delivery_codes = $commit_snapshot = array( array( 'location_id' => 1, 'dpd_city_id' => '49455627', 'updated_at' => '2026-06-16 02:00:00' ) );
$stage->upsert_candidate( $commit_stage, 2, '70000001', 'kladr' );
$GLOBALS['wpdb']->fail_dpd_stage_finalize_commit = true;
$commit_failed = false;
try {
	$stage->finalize_into_delivery_codes( $commit_stage );
} catch ( RuntimeException $exception ) {
	$commit_failed = str_contains( $exception->getMessage(), 'commit failed' );
}
$GLOBALS['wpdb']->fail_dpd_stage_finalize_commit = false;
dpd_import_assert( $commit_failed && $commit_snapshot === $GLOBALS['wpdb']->delivery_codes, 'commit failure rolls back delivery_codes snapshot.' );
$stage->drop( $commit_stage );

$production_stage_rows = array(
	array( 'location_id' => 1, 'dpd_city_id' => '100', 'status' => 'candidate' ),
	array( 'location_id' => 2, 'dpd_city_id' => '222', 'status' => 'candidate' ),
	array( 'location_id' => 3, 'dpd_city_id' => '333', 'status' => 'candidate' ),
	array( 'location_id' => 4, 'dpd_city_id' => '444', 'status' => 'candidate' ),
	array( 'location_id' => 5, 'dpd_city_id' => null, 'status' => 'conflict' ),
);
$production_delivery_snapshot = array(
	array( 'location_id' => 1, 'dpd_city_id' => '100', 'updated_at' => 'old-1' ),
	array( 'location_id' => 2, 'dpd_city_id' => '200', 'updated_at' => 'old-2' ),
	array( 'location_id' => 3, 'dpd_city_id' => null, 'updated_at' => 'old-3' ),
	array( 'location_id' => 5, 'dpd_city_id' => '500', 'updated_at' => 'old-5' ),
	array( 'location_id' => 9, 'dpd_city_id' => '900', 'updated_at' => 'old-9' ),
);
$production_db = new DpdProductionFinalizationWpdb();
$production_db->delivery_codes = $production_delivery_snapshot;
$production_db->stage_rows = $production_stage_rows;
$production_stage_repository = new DpdGeographyStageRepository( $production_db );
$production_clean = $production_stage_repository->finalize_into_delivery_codes( 'wp_wdc_dpd_stage_production', true );
$production_by_location = array_column( $production_db->delivery_codes, null, 'location_id' );
dpd_import_assert( 4 === (int) $production_clean['mappings'] && 4 === (int) $production_clean['changes'] && 1 === (int) $production_clean['stale_cleared'] && empty( $production_clean['stale_cleanup_skipped'] ), 'production-path clean finalization preserves logical counters for candidates, changes, and stale clears.' );
dpd_import_assert( '100' === (string) $production_by_location[1]['dpd_city_id'] && 'old-1' === (string) $production_by_location[1]['updated_at'], 'production-path unchanged mapping keeps old updated_at.' );
dpd_import_assert( '222' === (string) $production_by_location[2]['dpd_city_id'] && 'old-2' !== (string) $production_by_location[2]['updated_at'], 'production-path existing changed mapping is updated.' );
dpd_import_assert( '333' === (string) $production_by_location[3]['dpd_city_id'] && 'old-3' !== (string) $production_by_location[3]['updated_at'], 'production-path NULL working mapping changes to staged DPD ID.' );
dpd_import_assert( '444' === (string) $production_by_location[4]['dpd_city_id'], 'production-path missing candidate mapping is inserted.' );
dpd_import_assert( '500' === (string) $production_by_location[5]['dpd_city_id'] && 'old-5' === (string) $production_by_location[5]['updated_at'], 'production-path conflict row preserves existing working mapping.' );
dpd_import_assert( null === $production_by_location[9]['dpd_city_id'] && 'old-9' !== (string) $production_by_location[9]['updated_at'], 'production-path clean finalization clears stale mapping.' );
$production_sql = implode( "\n", $production_db->queries );
dpd_import_assert( ! str_contains( $production_sql, 'ON DUPLICATE KEY UPDATE' ) && ! str_contains( $production_sql, 'VALUES(dpd_city_id)' ) && ! str_contains( $production_sql, 'VALUES(updated_at)' ), 'production-path finalization SQL no longer uses ON DUPLICATE or VALUES().' );
dpd_import_assert( str_contains( $production_sql, 'UPDATE wp_wdc_location_delivery_codes AS dc' ) && str_contains( $production_sql, 'INNER JOIN wp_wdc_dpd_stage_production AS stage ON stage.location_id = dc.location_id' ) && str_contains( $production_sql, 'dc.dpd_city_id = stage.dpd_city_id' ) && str_contains( $production_sql, "stage.status = 'candidate'" ), 'production-path finalization uses qualified UPDATE for existing changed mappings.' );
dpd_import_assert( str_contains( $production_sql, 'INSERT INTO wp_wdc_location_delivery_codes' ) && str_contains( $production_sql, 'SELECT stage.location_id, stage.dpd_city_id' ) && str_contains( $production_sql, 'LEFT JOIN wp_wdc_location_delivery_codes AS dc ON dc.location_id = stage.location_id' ) && str_contains( $production_sql, 'dc.location_id IS NULL' ), 'production-path finalization uses qualified INSERT SELECT for missing mappings.' );
dpd_import_assert( ! str_contains( $production_sql, 'SELECT location_id, dpd_city_id' ) && ! preg_match( '/[^.]dpd_city_id\s*<=>/', $production_sql ), 'production-path finalization SQL avoids unqualified selected columns and unqualified dpd_city_id null-safe comparisons.' );

$production_warning_db = new DpdProductionFinalizationWpdb();
$production_warning_db->delivery_codes = $production_delivery_snapshot;
$production_warning_db->stage_rows = $production_stage_rows;
$production_warning = ( new DpdGeographyStageRepository( $production_warning_db ) )->finalize_into_delivery_codes( 'wp_wdc_dpd_stage_production', false );
$warning_by_location = array_column( $production_warning_db->delivery_codes, null, 'location_id' );
dpd_import_assert( 4 === (int) $production_warning['mappings'] && 3 === (int) $production_warning['changes'] && 0 === (int) $production_warning['stale_cleared'] && ! empty( $production_warning['stale_cleanup_skipped'] ), 'production-path warning finalization skips stale cleanup while applying successful candidates.' );
dpd_import_assert( '222' === (string) $warning_by_location[2]['dpd_city_id'] && '333' === (string) $warning_by_location[3]['dpd_city_id'] && '444' === (string) $warning_by_location[4]['dpd_city_id'] && '500' === (string) $warning_by_location[5]['dpd_city_id'] && '900' === (string) $warning_by_location[9]['dpd_city_id'], 'production-path warning finalization updates/inserts candidates, preserves conflicts, and keeps stale mappings.' );

$production_update_failure_db = new DpdProductionFinalizationWpdb();
$production_update_failure_db->delivery_codes = $production_delivery_snapshot;
$production_update_failure_db->stage_rows = $production_stage_rows;
$production_update_failure_db->fail_dpd_stage_finalize_update_existing = true;
$production_update_failed = false;
try {
	( new DpdGeographyStageRepository( $production_update_failure_db ) )->finalize_into_delivery_codes( 'wp_wdc_dpd_stage_production', true );
} catch ( RuntimeException $exception ) {
	$production_update_failed = str_contains( $exception->getMessage(), 'existing mappings update failed' );
}
dpd_import_assert( $production_update_failed && $production_delivery_snapshot === $production_update_failure_db->delivery_codes && $production_stage_rows === $production_update_failure_db->stage_rows, 'production-path existing UPDATE failure rolls back working mappings and keeps stage rows.' );

$production_insert_failure_db = new DpdProductionFinalizationWpdb();
$production_insert_failure_db->delivery_codes = $production_delivery_snapshot;
$production_insert_failure_db->stage_rows = $production_stage_rows;
$production_insert_failure_db->fail_dpd_stage_finalize_insert_missing = true;
$production_insert_failed = false;
try {
	( new DpdGeographyStageRepository( $production_insert_failure_db ) )->finalize_into_delivery_codes( 'wp_wdc_dpd_stage_production', true );
} catch ( RuntimeException $exception ) {
	$production_insert_failed = str_contains( $exception->getMessage(), 'missing mappings insert failed' );
}
dpd_import_assert( $production_insert_failed && $production_delivery_snapshot === $production_insert_failure_db->delivery_codes && $production_stage_rows === $production_insert_failure_db->stage_rows, 'production-path missing INSERT failure rolls back working mappings and keeps stage rows.' );

$production_commit_failure_db = new DpdProductionFinalizationWpdb();
$production_commit_failure_db->delivery_codes = $production_delivery_snapshot;
$production_commit_failure_db->stage_rows = $production_stage_rows;
$production_commit_failure_db->fail_dpd_stage_finalize_commit = true;
$production_commit_failed = false;
try {
	( new DpdGeographyStageRepository( $production_commit_failure_db ) )->finalize_into_delivery_codes( 'wp_wdc_dpd_stage_production', true );
} catch ( RuntimeException $exception ) {
	$production_commit_failed = str_contains( $exception->getMessage(), 'commit failed' );
}
dpd_import_assert( $production_commit_failed && $production_delivery_snapshot === $production_commit_failure_db->delivery_codes && $production_stage_rows === $production_commit_failure_db->stage_rows, 'production-path commit failure rolls back transaction-local UPDATE/INSERT changes and keeps stage rows.' );

$missing_stage_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-missing-stage-' );
file_put_contents( $missing_stage_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$missing_stage_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $missing_stage_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$missing_stage_internal = $state->current();
unset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ (string) $missing_stage_internal['stage_table'] ] );
$missing_stage_failed = $importer->step( (string) $missing_stage_job['job_id'], 1 );
$missing_stage_report = $settings->last_geography_import_report();
dpd_import_assert( 'failed' === (string) ( $missing_stage_failed['phase'] ?? '' ) && 'error' === (string) ( $missing_stage_failed['status'] ?? '' ), 'missing staging table fails active import job.' );
dpd_import_assert_public_state_redacted( $missing_stage_failed, 'missing stage failure response is redacted' );
dpd_import_assert( 'failed' === (string) ( $missing_stage_report['phase'] ?? '' ) && 'error' === (string) ( $missing_stage_report['status'] ?? '' ) && (string) ( $missing_stage_report['last_message'] ?? '' ) === (string) ( $missing_stage_failed['last_message'] ?? '' ), 'missing stage failure is saved as current terminal report.' );
dpd_import_assert( 1 === (int) ( $missing_stage_failed['errors_total'] ?? 0 ) && 1 === (int) ( $missing_stage_report['errors_total'] ?? 0 ), 'missing stage fatal failure increments errors_total.' );
$importer->reset();

$active_error_state = $state->start( array( 'job_id' => 'active-errors-total', 'phase' => 'importing', 'source' => 'manual', 'source_file' => 'synthetic.csv', 'last_message' => 'Synthetic active job.' ) );
$state->update( array( 'errors_total' => 2, 'errors' => array( 'row error 1', 'row error 2' ), 'foreign_save_failed' => 2, 'rows_read' => 10 ) );
$active_failed = $state->fail( 'Synthetic fatal failure.' );
dpd_import_assert( 3 === (int) ( $active_failed['errors_total'] ?? 0 ) && 2 === (int) ( $active_failed['foreign_save_failed'] ?? 0 ) && 10 === (int) ( $active_failed['rows_read'] ?? 0 ), 'active fatal fail increments errors_total without resetting existing row counters.' );
dpd_import_assert( in_array( 'row error 1', (array) ( $active_failed['errors'] ?? array() ), true ) && in_array( 'Synthetic fatal failure.', (array) ( $active_failed['errors'] ?? array() ), true ), 'active fatal fail preserves existing errors and appends fatal message.' );
$importer->reset();

$warning_state = $state->finish( 'Synthetic warning terminal state.', 'warning' );
$report_from_state = new ReflectionMethod( $importer, 'report_from_state' );
$report_from_state->setAccessible( true );
$warning_report = $report_from_state->invoke( $importer, $warning_state );
dpd_import_assert( 'finished' === (string) ( $warning_state['phase'] ?? '' ) && 'warning' === (string) ( $warning_state['status'] ?? '' ), 'warning terminal state uses finished/warning lifecycle status.' );
dpd_import_assert( 'finished' === (string) ( $warning_report['phase'] ?? '' ) && 'warning' === (string) ( $warning_report['status'] ?? '' ) && (string) ( $warning_report['last_message'] ?? '' ) === (string) ( $warning_state['last_message'] ?? '' ), 'warning report is built from the terminal warning state.' );

$phase_before_ftp_warning = (string) $state->current()['phase'];
$ftp_warning = $importer->start_from_ftp( new DpdGeographyFtpClient( $settings ) );
if ( ! extension_loaded( 'ssh2' ) || ! function_exists( 'ssh2_connect' ) ) {
	dpd_import_assert( 'warning' === (string) ( $ftp_warning['status'] ?? '' ), 'missing ssh2 returns FTP warning instead of failed import' );
	dpd_import_assert( str_contains( strtolower( (string) ( $ftp_warning['last_message'] ?? '' ) ), 'manual csv upload' ), 'missing ssh2 warning points to manual CSV upload' );
	dpd_import_assert( $phase_before_ftp_warning === (string) $state->current()['phase'], 'missing ssh2 does not change current import state phase' );
	dpd_import_assert( array() === (array) ( $state->current()['errors'] ?? array() ), 'missing ssh2 warning does not pollute import errors' );
}

$cli_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-cli-' );
file_put_contents( $cli_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$GLOBALS['wpdb']->delivery_codes = $working_after_success;
$delivery_codes_before_repeat = $GLOBALS['wpdb']->delivery_codes;
$report = $importer->import_file( $cli_path, 'cli', 'GeographyNewDPD_2026_06_16.csv' );
$cli_state = $state->current();
dpd_import_assert( 0 === (int) $report['total_rows'], 'CLI wrapper imports existing file without pre-counting rows' );
dpd_import_assert( 6 === count( array_values( array_filter( $GLOBALS['wpdb']->locations, static fn( array $row ): bool => in_array( (string) ( $row['country_code'] ?? '' ), array( 'AM', 'BY', 'KZ', 'KG' ), true ) ) ) ), 'repeat import reuses foreign locations without creating duplicates' );
dpd_import_assert( 8 === (int) $report['finalized_mappings'] && 0 === (int) ( $report['finalized_changes'] ?? -1 ), 'repeat import reports candidate mappings separately from idempotent finalized changes' );
dpd_import_assert( $delivery_codes_before_repeat === $GLOBALS['wpdb']->delivery_codes, 'repeat import does not update unchanged working mappings or updated_at values' );
dpd_import_assert( 'finished' === (string) ( $settings->last_geography_import_report()['phase'] ?? '' ) && 'success' === (string) ( $settings->last_geography_import_report()['status'] ?? '' ), 'repeat import report remains terminal success.' );
dpd_import_assert( false === (bool) $cli_state['delete_file_on_finish'], 'CLI wrapper stores delete_file_on_finish=false' );
dpd_import_assert( file_exists( $cli_path ), 'CLI wrapper keeps existing CSV on finish' );
dpd_import_assert( ! file_exists( (string) $cli_state['index_path'] ), 'CLI wrapper deletes serialized index on finish' );
dpd_import_assert( ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ (string) $cli_state['stage_table'] ] ), 'CLI wrapper deletes staging table on finish' );
@unlink( $cli_path );

$repeat_locations_snapshot = $GLOBALS['wpdb']->locations;
$repeat_delivery_snapshot = $GLOBALS['wpdb']->delivery_codes;
$foreign_header = 'ID НП;Код страны;Регион;Район;Основной город;Населённый пункт;Тип НП;Индекс НП;ФИАС;Код КЛАДР';

$lookup_settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$canonical_alexandrovo_row = array(
	'id' => 228315,
	'country_code' => 'BY',
	'region_name' => 'Минская',
	'region_type' => 'обл.',
	'district_name' => 'Минский',
	'district_type' => 'р-н',
	'city_name' => '',
	'city_type' => '',
	'settlement_name' => 'Александрово',
	'settlement_type' => 'д',
	'place_name' => 'Александрово',
	'place_type' => 'д',
	'display_name' => 'Минская обл., Минский р-н, д Александрово',
	'searchable_text' => 'минская обл минский р-н д александрово',
	'gar_object_id' => null,
	'fias_id' => null,
	'gar_id' => '',
	'kladr_id' => '',
	'active' => 1,
);
$lookup_failure_csv = $foreign_header . "\n" . '30000001;BY;Минская;Минский;Минск2;Александрово;д;220010;;BY60011003000';

$canonical_error_db = new DpdForeignIdentityLookupWpdb();
$canonical_error_db->foreign_rows = array( $canonical_alexandrovo_row );
$canonical_error_db->delivery_codes = array( array( 'location_id' => 228315, 'dpd_city_id' => '30000001', 'updated_at' => 'old' ) );
$canonical_error_db->identity_mode = 'sql_error';
list( $canonical_error_report ) = dpd_run_lookup_import( $canonical_error_db, $lookup_failure_csv, $lookup_settings, 'LookupCanonicalSqlError.csv' );
dpd_import_assert( 1 === count( $canonical_error_db->foreign_rows ), 'canonical identity SQL error does not create a duplicate foreign location.' );
dpd_import_assert( 1 === (int) ( $canonical_error_report['foreign_save_failed'] ?? 0 ) && 1 === (int) ( $canonical_error_report['errors_total'] ?? 0 ), 'canonical identity SQL error is reported as one row-level foreign save failure.' );
dpd_import_assert( str_contains( implode( "\n", (array) ( $canonical_error_report['errors'] ?? array() ) ), 'Foreign location identity lookup failed' ), 'canonical identity SQL error is visible in warning diagnostics.' );
dpd_import_assert( 'finished' === (string) ( $canonical_error_report['phase'] ?? '' ) && 'warning' === (string) ( $canonical_error_report['status'] ?? '' ) && true === (bool) ( $canonical_error_report['stale_cleanup_skipped'] ?? false ), 'canonical identity SQL error finishes as warning with stale cleanup skipped.' );
dpd_import_assert( array( array( 'location_id' => 228315, 'dpd_city_id' => '30000001', 'updated_at' => 'old' ) ) === $canonical_error_db->delivery_codes, 'canonical identity SQL error preserves existing working mapping.' );

foreach ( array( 'non_array' => 'invalid SQL result', 'invalid_row' => 'invalid row structure' ) as $mode => $expected_message ) {
	$invalid_lookup_db = new DpdForeignIdentityLookupWpdb();
	$invalid_lookup_db->foreign_rows = array( $canonical_alexandrovo_row );
	$invalid_lookup_db->identity_mode = $mode;
	list( $invalid_lookup_report ) = dpd_run_lookup_import( $invalid_lookup_db, $lookup_failure_csv, $lookup_settings, 'Lookup' . $mode . '.csv' );
	dpd_import_assert( 1 === count( $invalid_lookup_db->foreign_rows ), $mode . ' foreign identity lookup does not create a duplicate location.' );
	dpd_import_assert( 1 === (int) ( $invalid_lookup_report['foreign_save_failed'] ?? 0 ) && str_contains( implode( "\n", (array) ( $invalid_lookup_report['errors'] ?? array() ) ), $expected_message ), $mode . ' foreign identity lookup is a row-level warning diagnostic.' );
}

$empty_lookup_db = new DpdForeignIdentityLookupWpdb();
$empty_lookup_db->identity_mode = 'empty';
$empty_lookup_db->legacy_mode = 'empty';
list( $empty_lookup_report ) = dpd_run_lookup_import( $empty_lookup_db, $lookup_failure_csv, $lookup_settings, 'LookupEmpty.csv' );
dpd_import_assert( 1 === count( $empty_lookup_db->foreign_rows ) && 1 === (int) ( $empty_lookup_report['foreign_locations_inserted'] ?? 0 ) && 0 === (int) ( $empty_lookup_report['errors_total'] ?? -1 ), 'successful empty foreign identity lookup creates one new canonical location without row errors.' );

$legacy_error_db = new DpdForeignIdentityLookupWpdb();
$legacy_error_db->identity_mode = 'empty';
$legacy_error_db->legacy_mode = 'sql_error';
list( $legacy_error_report ) = dpd_run_lookup_import( $legacy_error_db, $lookup_failure_csv, $lookup_settings, 'LookupLegacySqlError.csv' );
dpd_import_assert( array() === $legacy_error_db->foreign_rows, 'legacy foreign identity SQL error does not create a new foreign location.' );
dpd_import_assert( 1 === (int) ( $legacy_error_report['foreign_save_failed'] ?? 0 ) && 1 === (int) ( $legacy_error_report['errors_total'] ?? 0 ) && str_contains( implode( "\n", (array) ( $legacy_error_report['errors'] ?? array() ) ), 'Legacy foreign location identity lookup failed' ), 'legacy foreign identity SQL error is a row-level warning diagnostic.' );

$duplicate_mapping_db = new wpdb();
$duplicate_mapping_db->delivery_codes = array(
	array( 'location_id' => 231660, 'dpd_city_id' => '30000021', 'updated_at' => 'later' ),
	array( 'location_id' => 228315, 'dpd_city_id' => '30000021', 'updated_at' => 'earlier' ),
);
dpd_import_assert( 228315 === ( new LocationDeliveryCodeRepository( $duplicate_mapping_db ) )->find_location_id_by_dpd_city_id( '30000021' ), 'in-memory duplicate DPD mappings resolve to the lowest location_id.' );
$production_mapping_db = new DpdDeliveryCodeProductionWpdb();
$production_mapping_db->mapping_rows = $duplicate_mapping_db->delivery_codes;
dpd_import_assert( 228315 === ( new LocationDeliveryCodeRepository( $production_mapping_db ) )->find_location_id_by_dpd_city_id( '30000021' ), 'production duplicate DPD mappings resolve to the lowest location_id.' );
dpd_import_assert( str_contains( $production_mapping_db->last_sql, 'ORDER BY location_id ASC LIMIT 1' ), 'production DPD mapping lookup orders duplicate rows by location_id.' );
$production_mapping_db->fail_mapping_lookup = true;
$mapping_failed = false;
try {
	( new LocationDeliveryCodeRepository( $production_mapping_db ) )->find_location_id_by_dpd_city_id( '30000021' );
} catch ( RuntimeException $exception ) {
	$mapping_failed = str_contains( $exception->getMessage(), 'DPD delivery code lookup failed' );
}
dpd_import_assert( $mapping_failed, 'production DPD mapping lookup SQL error fails closed instead of returning not_found.' );

$GLOBALS['wpdb']->locations = array();
$GLOBALS['wpdb']->delivery_codes = array();
$alexandrovo_rows = array_fill( 0, 7, '30000001;BY;Минская;Минский;Минск2;Александрово;д;220010;;BY60011003000' );
$alexandrovo_csv = $foreign_header . "\n" . implode( "\n", $alexandrovo_rows );
$alexandrovo_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-alex-clean-' );
file_put_contents( $alexandrovo_path, mb_convert_encoding( $alexandrovo_csv, 'Windows-1251', 'UTF-8' ) );
$alexandrovo_report = $importer->import_file( $alexandrovo_path, 'cli', 'Alexandrovo.csv' );
$alexandrovo_locations = array_values( array_filter( $GLOBALS['wpdb']->locations, static fn( array $row ): bool => 'BY' === (string) ( $row['country_code'] ?? '' ) && 'Минская' === (string) ( $row['region_name'] ?? '' ) && 'Минский' === (string) ( $row['district_name'] ?? '' ) && 'Александрово' === (string) ( $row['place_name'] ?? '' ) && 'д' === (string) ( $row['place_type'] ?? '' ) ) );
dpd_import_assert( 1 === count( $alexandrovo_locations ), 'seven identical clean DPD rows for BY Alexandrovo create one canonical foreign location.' );
dpd_import_assert( 1 === (int) ( $alexandrovo_report['foreign_locations_inserted'] ?? 0 ) && 0 === (int) ( $alexandrovo_report['foreign_mapping_conflicts'] ?? 0 ), 'identical Alexandrovo rows insert one location and do not create mapping conflict.' );
dpd_import_assert( 'Минская обл., Минский р-н, д Александрово' === (string) ( $alexandrovo_locations[0]['display_name'] ?? '' ), 'BY Alexandrovo display_name omits country prefix and keeps region/district/type.' );
$alexandrovo_id = (int) ( $alexandrovo_locations[0]['id'] ?? 0 );
$alexandrovo_repeat_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-alex-repeat-' );
file_put_contents( $alexandrovo_repeat_path, mb_convert_encoding( $alexandrovo_csv, 'Windows-1251', 'UTF-8' ) );
$alexandrovo_repeat_report = $importer->import_file( $alexandrovo_repeat_path, 'cli', 'Alexandrovo.csv' );
$alexandrovo_repeat_locations = array_values( array_filter( $GLOBALS['wpdb']->locations, static fn( array $row ): bool => 'BY' === (string) ( $row['country_code'] ?? '' ) && 'Минская' === (string) ( $row['region_name'] ?? '' ) && 'Минский' === (string) ( $row['district_name'] ?? '' ) && 'Александрово' === (string) ( $row['place_name'] ?? '' ) && 'д' === (string) ( $row['place_type'] ?? '' ) ) );
dpd_import_assert( 1 === count( $alexandrovo_repeat_locations ) && $alexandrovo_id === (int) ( $alexandrovo_repeat_locations[0]['id'] ?? 0 ) && 0 === (int) ( $alexandrovo_repeat_report['foreign_locations_inserted'] ?? -1 ), 'repeat Alexandrovo import reuses the same canonical location id without inserting duplicates.' );
@unlink( $alexandrovo_path );
@unlink( $alexandrovo_repeat_path );

$GLOBALS['wpdb']->locations = array();
$GLOBALS['wpdb']->delivery_codes = array();
$alexandrovo_conflict_csv = $foreign_header . "\n"
	. '30000011;BY;Минская;Минский;Минск2;Александрово;д;220010;;BY60011003000' . "\n"
	. '30000012;BY;Минская;Минский;Минск2;Александрово;д;220011;;BY60011003000';
$alexandrovo_conflict_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-alex-conflict-' );
file_put_contents( $alexandrovo_conflict_path, mb_convert_encoding( $alexandrovo_conflict_csv, 'Windows-1251', 'UTF-8' ) );
$alexandrovo_conflict_report = $importer->import_file( $alexandrovo_conflict_path, 'cli', 'AlexandrovoConflict.csv' );
$alexandrovo_conflict_locations = array_values( array_filter( $GLOBALS['wpdb']->locations, static fn( array $row ): bool => 'BY' === (string) ( $row['country_code'] ?? '' ) && 'Минская' === (string) ( $row['region_name'] ?? '' ) && 'Минский' === (string) ( $row['district_name'] ?? '' ) && 'Александрово' === (string) ( $row['place_name'] ?? '' ) && 'д' === (string) ( $row['place_type'] ?? '' ) ) );
dpd_import_assert( 1 === count( $alexandrovo_conflict_locations ) && 1 === (int) ( $alexandrovo_conflict_report['foreign_mapping_conflicts'] ?? 0 ), 'same Alexandrovo identity with different DPD IDs creates a staging conflict without duplicate locations.' );
@unlink( $alexandrovo_conflict_path );

$GLOBALS['wpdb']->locations = array();
foreach ( array( 228315, 228316, 231660, 231670, 238125, 247802, 252230 ) as $duplicate_id ) {
	$GLOBALS['wpdb']->locations[] = array(
		'id' => $duplicate_id,
		'country_code' => 'BY',
		'region_name' => 'Минская',
		'region_type' => 'обл.',
		'district_name' => 'Минский',
		'district_type' => 'р-н',
		'city_name' => '',
		'city_type' => '',
		'settlement_name' => 'Александрово',
		'settlement_type' => 'д',
		'place_name' => 'Александрово',
		'place_type' => 'д',
		'display_name' => 'BY, Минская обл., Минский р-н, д Александрово',
		'searchable_text' => 'by минская обл минский р-н д александрово',
		'gar_object_id' => null,
		'fias_id' => null,
		'gar_id' => '',
		'kladr_id' => '',
		'active' => 1,
	);
}
$GLOBALS['wpdb']->delivery_codes = array( array( 'location_id' => 231660, 'dpd_city_id' => '30000021', 'updated_at' => 'old-mapped' ) );
$existing_duplicates_csv = $foreign_header . "\n" . '30000021;BY;Минская;Минский;Минск2;Александрово;д;220010;;BY60011003000';
$existing_duplicates_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-alex-existing-' );
file_put_contents( $existing_duplicates_path, mb_convert_encoding( $existing_duplicates_csv, 'Windows-1251', 'UTF-8' ) );
$existing_duplicates_report = $importer->import_file( $existing_duplicates_path, 'cli', 'AlexandrovoExistingDuplicates.csv' );
$existing_duplicate_ids = array_values( array_filter( array_map( static fn( array $row ): int => 'BY' === (string) ( $row['country_code'] ?? '' ) && 'Александрово' === (string) ( $row['place_name'] ?? '' ) ? (int) ( $row['id'] ?? 0 ) : 0, $GLOBALS['wpdb']->locations ) ) );
dpd_import_assert( 7 === count( $existing_duplicate_ids ) && in_array( 231660, $existing_duplicate_ids, true ), 'existing seven Alexandrovo duplicates are not destructively merged or expanded.' );
dpd_import_assert( 1 === (int) ( $existing_duplicates_report['foreign_duplicate_identity_rows'] ?? 0 ) && 0 === (int) ( $existing_duplicates_report['errors_total'] ?? -1 ), 'existing duplicate foreign identity rows increment diagnostic counter without becoming row errors.' );
$mapped_duplicate_row = array_values( array_filter( $GLOBALS['wpdb']->locations, static fn( array $row ): bool => 231660 === (int) ( $row['id'] ?? 0 ) ) )[0] ?? array();
dpd_import_assert( 'Минская обл., Минский р-н, д Александрово' === (string) ( $mapped_duplicate_row['display_name'] ?? '' ), 'existing mapped duplicate canonical row is updated with display_name without country prefix.' );
@unlink( $existing_duplicates_path );

$GLOBALS['wpdb']->delivery_codes = array();
$lowest_duplicates_csv = $foreign_header . "\n" . '30000022;BY;Минская;Минский;Минск2;Александрово;д.;220010;;BY60011003000';
$lowest_duplicates_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-alex-lowest-' );
file_put_contents( $lowest_duplicates_path, mb_convert_encoding( $lowest_duplicates_csv, 'Windows-1251', 'UTF-8' ) );
$lowest_duplicates_report = $importer->import_file( $lowest_duplicates_path, 'cli', 'AlexandrovoLowestDuplicate.csv' );
dpd_import_assert( 7 === count( array_values( array_filter( $GLOBALS['wpdb']->locations, static fn( array $row ): bool => 'BY' === (string) ( $row['country_code'] ?? '' ) && 'Александрово' === (string) ( $row['place_name'] ?? '' ) ) ) ) && '30000022' === $repository->get_dpd_city_id( 228315 ), 'existing duplicate resolver falls back to the lowest positive location id and treats д. as д.' );
dpd_import_assert( 1 === (int) ( $lowest_duplicates_report['foreign_duplicate_identity_rows'] ?? 0 ), 'lowest-id duplicate resolution increments duplicate identity diagnostics.' );
@unlink( $lowest_duplicates_path );

$GLOBALS['wpdb']->locations = array();
$GLOBALS['wpdb']->delivery_codes = array();
$identity_distinct_csv = $foreign_header . "\n"
	. '30000031;BY;Минская;Минский;Минск2;Александрово;деревня;220010;;BY60011003000' . "\n"
	. '30000032;BY;Минская;Дзержинский;Минск2;Александрово;д;220011;;BY60011004000' . "\n"
	. '30000033;BY;Минская;Минский;Минск2;Александрово;п;220012;;BY60011005000';
$identity_distinct_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-alex-distinct-' );
file_put_contents( $identity_distinct_path, mb_convert_encoding( $identity_distinct_csv, 'Windows-1251', 'UTF-8' ) );
$identity_distinct_report = $importer->import_file( $identity_distinct_path, 'cli', 'AlexandrovoDistinct.csv' );
$identity_distinct_locations = array_values( array_filter( $GLOBALS['wpdb']->locations, static fn( array $row ): bool => 'BY' === (string) ( $row['country_code'] ?? '' ) && 'Александрово' === (string) ( $row['place_name'] ?? '' ) ) );
dpd_import_assert( 3 === count( $identity_distinct_locations ) && 3 === (int) ( $identity_distinct_report['foreign_locations_inserted'] ?? 0 ), 'different districts and different place types remain distinct foreign identities while деревня normalizes to д.' );
@unlink( $identity_distinct_path );

$GLOBALS['wpdb']->locations = $repeat_locations_snapshot;
$GLOBALS['wpdb']->delivery_codes = $repeat_delivery_snapshot;

$reset_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-reset-' );
file_put_contents( $reset_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$reset_job = $importer->start_from_uploaded_file( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $reset_path, 'name' => 'GeographyNewDPD_2026_06_16.csv' ) );
$reset_state = $state->current();
$reset_stage = (string) $reset_state['stage_table'];
$reset_import_path = (string) $reset_state['file_path'];
$reset_index_path = (string) $reset_state['index_path'];
dpd_import_assert( isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $reset_stage ] ), 'reset scenario creates staging table' );
$reset_public = $importer->reset();
dpd_import_assert( 'cancelled' === (string) $reset_public['phase'], 'reset marks import as cancelled' );
dpd_import_assert( ! file_exists( $reset_import_path ), 'reset deletes uploaded import temp file when delete_file_on_finish=true' );
dpd_import_assert( ! file_exists( $reset_index_path ), 'reset deletes serialized index when delete_file_on_finish=true' );
dpd_import_assert( ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $reset_stage ] ), 'reset deletes staging table' );

$existing_reset_path = tempnam( sys_get_temp_dir(), 'wdc-dpd-import-existing-reset-' );
file_put_contents( $existing_reset_path, mb_convert_encoding( $csv, 'Windows-1251', 'UTF-8' ) );
$starter = new ReflectionMethod( $importer, 'start_from_existing_file' );
$starter->setAccessible( true );
$existing_reset_job = $starter->invoke( $importer, $existing_reset_path, 'cli', 'GeographyNewDPD_2026_06_16.csv', false );
$existing_reset_state = $state->current();
$existing_reset_stage = (string) $existing_reset_state['stage_table'];
$existing_reset_index_path = (string) $existing_reset_state['index_path'];
dpd_import_assert( 'ready' === (string) $existing_reset_job['phase'], 'existing-file reset scenario creates ready job' );
dpd_import_assert( false === (bool) $existing_reset_state['delete_file_on_finish'], 'existing-file reset scenario stores delete_file_on_finish=false' );
$importer->reset();
dpd_import_assert( file_exists( $existing_reset_path ), 'reset keeps existing CSV when delete_file_on_finish=false' );
dpd_import_assert( ! file_exists( $existing_reset_index_path ), 'reset deletes serialized index when delete_file_on_finish=false' );
dpd_import_assert( ! isset( $GLOBALS['wpdb']->dpd_geography_stage_tables[ $existing_reset_stage ] ), 'reset deletes staging table when delete_file_on_finish=false' );
@unlink( $existing_reset_path );

$production_db = new DpdProductionPathWpdb();
$production_db->candidate_rows = array(
	array(
		'id' => 9001,
		'gar_object_id' => null,
		'fias_id' => null,
		'gar_id' => '',
		'kladr_id' => '',
		'country_code' => 'BY',
		'region_name' => 'Минская',
		'district_name' => 'Ленинский',
		'city_name' => '',
		'settlement_name' => 'им. Ленина',
		'settlement_type' => 'п',
		'place_name' => 'им. Ленина',
		'place_type' => 'п',
		'display_name' => 'BY, Минская, Ленинский, п им. Ленина',
		'searchable_text' => 'by минская ленинский п им. ленина',
		'active' => 1,
	),
	array(
		'id' => 9002,
		'gar_object_id' => null,
		'fias_id' => null,
		'gar_id' => '',
		'kladr_id' => '',
		'country_code' => 'BY',
		'region_name' => 'Берёзовская',
		'district_name' => 'Берёзовский',
		'settlement_name' => 'Берёзовка',
		'settlement_type' => 'с',
		'place_name' => 'Берёзовка',
		'place_type' => 'с',
		'display_name' => 'BY, Берёзовская, Берёзовский, с Берёзовка',
		'searchable_text' => 'by берёзовская берёзовский с берёзовка',
		'active' => 1,
	),
	array(
		'id' => 9003,
		'gar_object_id' => null,
		'fias_id' => null,
		'gar_id' => '',
		'kladr_id' => '',
		'country_code' => 'BY',
		'region_name' => 'Минская',
		'district_name' => 'Минский',
		'settlement_name' => 'Новая   Жизнь',
		'settlement_type' => 'г',
		'place_name' => 'Новая   Жизнь',
		'place_type' => 'г',
		'display_name' => 'BY, Минская, Минский, г Новая Жизнь',
		'searchable_text' => 'by минская минский г новая   жизнь',
		'active' => 1,
	),
);
$production_repository = new LocationRepository( $production_db );
$punctuation_location = $production_repository->find_foreign_by_place_identity( 'BY', 'им Ленина', 'Минская', 'Ленинский', 'п.' );
dpd_import_assert( $punctuation_location instanceof Location && 9001 === (int) $punctuation_location->id, 'production-path foreign identity finds im. Lenina by punctuation-free request.' );
dpd_import_assert( ! str_contains( $production_db->last_sql, 'LIMIT' ), 'production foreign identity prefilter does not hide exact candidates behind arbitrary LIMIT.' );
dpd_import_assert( in_array( '%им%', $production_db->last_prepare_args, true ) && in_array( '%ленина%', $production_db->last_prepare_args, true ) && ! in_array( '%им ленина%', $production_db->last_prepare_args, true ), 'production prefilter uses token LIKE patterns instead of one contiguous normalized phrase.' );
$yo_location = $production_repository->find_foreign_by_place_identity( 'BY', 'Березовка', 'Березовская', 'Березовский', 'с' );
dpd_import_assert( $yo_location instanceof Location && 9002 === (int) $yo_location->id, 'production-path foreign identity applies Ё/Е normalization.' );
$space_location = $production_repository->find_foreign_by_place_identity( 'BY', 'Новая Жизнь', 'Минская', 'Минский', 'г.' );
dpd_import_assert( $space_location instanceof Location && 9003 === (int) $space_location->id, 'production-path foreign identity matches whitespace and type punctuation variants.' );

$bulk_db = new DpdProductionPathWpdb();
$bulk_repository = new LocationRepository( $bulk_db );
$bulk_repository->bulk_upsert_locations(
	array(
		Location::from_array( array( 'country_code' => 'BY', 'region_name' => 'Минская', 'district_name' => 'Минский', 'place_name' => 'Минск', 'place_type' => 'г', 'settlement_name' => 'Минск', 'settlement_type' => 'г', 'display_name' => 'BY, Минская, Минский, г Минск', 'gar_object_id' => 0, 'fias_id' => '', 'gar_id' => '', 'active' => true ) ),
		Location::from_array( array( 'country_code' => 'KZ', 'region_name' => 'Алматы', 'place_name' => 'Алматы', 'place_type' => 'г', 'settlement_name' => 'Алматы', 'settlement_type' => 'г', 'display_name' => 'KZ, г Алматы', 'gar_object_id' => 0, 'fias_id' => '', 'gar_id' => '', 'active' => true ) ),
		Location::from_array( array( 'country_code' => 'RU', 'region_name' => 'Новосибирская', 'place_name' => 'Тест', 'place_type' => 'г', 'settlement_name' => 'Тест', 'settlement_type' => 'г', 'display_name' => 'Тест', 'gar_object_id' => 12345, 'fias_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'gar_id' => '12345', 'active' => true ) ),
	)
);
dpd_import_assert( substr_count( $bulk_db->last_insert_query, 'NULL' ) >= 4, 'bulk location upsert emits SQL NULL literals for missing foreign GAR/FIAS.' );
dpd_import_assert( str_contains( $bulk_db->last_insert_query, '(NULL, NULL' ), 'bulk location upsert does not bind missing GAR/FIAS through %d/%s placeholders.' );
dpd_import_assert( in_array( 12345, $bulk_db->last_insert_args, true ) && in_array( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $bulk_db->last_insert_args, true ), 'bulk location upsert still binds real positive GAR and non-empty FIAS values.' );

$plugin_source = file_get_contents( __DIR__ . '/../../src/Core/Plugin.php' );
dpd_import_assert( is_string( $plugin_source ) && str_contains( $plugin_source, 'DpdShipmentAdapter' ), 'DPD dry-run shipment adapter is registered outside geography import.' );
dpd_import_assert( is_string( $plugin_source ) && ! str_contains( $plugin_source, 'DpdCarrier' ), 'DPD runtime carrier is not registered by geography import' );

$migration_0043_source = file_get_contents( __DIR__ . '/../../database/migrations/0043_allow_external_locations_without_gar_fias.php' );
dpd_import_assert( is_string( $migration_0043_source ) && str_contains( $migration_0043_source, 'gar_object_id BIGINT(20) UNSIGNED NULL' ) && str_contains( $migration_0043_source, 'fias_id CHAR(36) NULL' ), 'migration 0043 keeps GAR/FIAS nullable for external locations.' );
dpd_import_assert( is_string( $migration_0043_source ) && str_contains( $migration_0043_source, 'WHERE gar_object_id = 0' ) && str_contains( $migration_0043_source, "TRIM(fias_id) = ''" ) && str_contains( $migration_0043_source, "gar_id = ''" ), 'migration 0043 normalizes placeholder GAR/FIAS values idempotently.' );
$import_service_source = file_get_contents( __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyImportService.php' );
dpd_import_assert( is_string( $import_service_source ) && str_contains( $import_service_source, 'LOCK_EX' ) && str_contains( $import_service_source, 'hash_file( \'sha256\'' ) && str_contains( $import_service_source, 'allowed_classes\' => false' ), 'DPD geography serialized location index is persisted and loaded with integrity checks.' );
dpd_import_assert( is_string( $import_service_source ) && ! str_contains( $import_service_source, 'is_array( $loaded ) ? $loaded : array()' ), 'DPD geography index load no longer falls back to an empty index on corrupt payloads.' );
$stage_source = file_get_contents( __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyStageRepository.php' );
dpd_import_assert( is_string( $stage_source ) && str_contains( $stage_source, 'get_count_or_throw' ) && ! str_contains( $stage_source, '(int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$safe_stage}' ), 'DPD geography finalization count queries fail closed instead of coercing SQL errors to zero.' );
dpd_import_assert( is_string( $stage_source ) && ! str_contains( $stage_source, 'ON DUPLICATE KEY UPDATE' ) && ! str_contains( $stage_source, 'VALUES(dpd_city_id)' ) && ! str_contains( $stage_source, 'VALUES(updated_at)' ), 'DPD geography production finalization does not use ambiguous ON DUPLICATE/VALUES SQL.' );
dpd_import_assert( is_string( $stage_source ) && str_contains( $stage_source, 'UPDATE {$delivery_table} AS dc' ) && str_contains( $stage_source, 'INNER JOIN {$safe_stage} AS stage ON stage.location_id = dc.location_id' ) && str_contains( $stage_source, 'INSERT INTO {$delivery_table} (location_id, dpd_city_id, updated_at)' ) && str_contains( $stage_source, 'SELECT stage.location_id, stage.dpd_city_id' ), 'DPD geography production finalization applies candidates through qualified UPDATE and INSERT SELECT statements.' );
$location_repository_source = file_get_contents( __DIR__ . '/../../src/Locations/Storage/LocationRepository.php' );
dpd_import_assert( is_string( $location_repository_source ) && str_contains( $location_repository_source, 'DPD location index page query failed' ) && str_contains( $location_repository_source, '$this->wpdb->last_error = \'\'' ) && str_contains( $location_repository_source, 'invalid SQL result' ), 'DPD location index page queries fail closed on SQL and non-array result errors.' );
dpd_import_assert( is_string( $import_service_source ) && strpos( $import_service_source, '$this->index->build()' ) < strpos( $import_service_source, 'DpdLocationIndex::validate_export' ) && strpos( $import_service_source, 'DpdLocationIndex::validate_export' ) < strpos( $import_service_source, '$index_path = $this->temp_path' ) && strpos( $import_service_source, 'persist_location_index' ) < strpos( $import_service_source, '$this->stage->create' ), 'DPD geography import start builds and validates the index before persisting artifacts or creating staging tables.' );
$admin_source = file_get_contents( __DIR__ . '/../../src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$status_start = is_string( $admin_source ) ? strpos( $admin_source, 'public function ajax_dpd_geography_import_status' ) : false;
$step_start = is_string( $admin_source ) ? strpos( $admin_source, 'public function ajax_dpd_geography_import_step' ) : false;
$status_segment = false !== $status_start && false !== $step_start ? substr( $admin_source, $status_start, $step_start - $status_start ) : '';
$step_segment = false !== $step_start ? substr( $admin_source, $step_start, 1200 ) : '';
dpd_import_assert( is_string( $admin_source ) && str_contains( $admin_source, "wp_ajax_wdc_dpd_geography_import_step" ), 'DPD geography import AJAX step action is registered separately.' );
dpd_import_assert( is_string( $status_segment ) && str_contains( $status_segment, '->current_state()' ) && ! str_contains( $status_segment, '->step(' ), 'DPD geography import status AJAX handler is read-only.' );
dpd_import_assert( is_string( $step_segment ) && str_contains( $step_segment, 'job_id' ) && str_contains( $step_segment, 'expected_byte_offset' ) && str_contains( $step_segment, 'DPD_GEOGRAPHY_AJAX_STEP_LIMIT' ), 'DPD geography import step AJAX handler passes job_id, expected byte offset, and server step limit.' );
dpd_import_assert( is_string( $admin_source ) && str_contains( $admin_source, 'private const DPD_GEOGRAPHY_AJAX_STEP_LIMIT = 500' ), 'browser DPD geography import step limit is capped at 500 rows.' );
$runner_source = file_get_contents( __DIR__ . '/../../assets/admin/dpd-geography-import.js' );
dpd_import_assert( is_string( $runner_source ) && ! str_contains( $runner_source, 'setInterval' ) && str_contains( $runner_source, 'wdc_dpd_geography_import_step' ) && str_contains( $runner_source, 'expected_byte_offset' ), 'DPD geography browser runner uses separate sequential step requests without setInterval.' );
dpd_import_assert( is_string( $runner_source ) && str_contains( $runner_source, 'operationControl.outcome === \'reset_required\'' ) && str_contains( $runner_source, 'Этот импорт создан предыдущей версией runner' ), 'DPD geography browser runner stops legacy protocol jobs until reset.' );
$lock_source = file_get_contents( __DIR__ . '/../../src/Carriers/Dpd/Geography/DpdGeographyImportLockService.php' );
dpd_import_assert( is_string( $lock_source ) && str_contains( $lock_source, 'DELETE FROM {$this->wpdb->options}' ) && str_contains( $lock_source, 'option_value = %s' ) && ! str_contains( $lock_source, 'delete_option( self::OPTION_NAME' ), 'DPD geography import lock release/takeover uses atomic SQL compare-delete instead of unconditional delete_option.' );
dpd_import_assert( is_string( $import_service_source ) && str_contains( $import_service_source, 'START_LOCK_TTL_SECONDS' ) && str_contains( $import_service_source, 'run_locked_start' ) && str_contains( $import_service_source, 'start_from_existing_file_unlocked' ), 'DPD geography import start lifecycle runs under a single start lock before creating job artifacts.' );
dpd_import_assert( is_string( $import_service_source ) && ! str_contains( $import_service_source, "'phase' => 'downloading'" ) && str_contains( $import_service_source, 'runner_protocol_version' ), 'DPD geography SFTP start no longer self-blocks with a downloading phase and new jobs carry runner protocol version.' );
dpd_import_assert( is_string( $admin_source ) && str_contains( $admin_source, 'dpd_import_action_succeeded' ) && str_contains( $admin_source, "'reset_required'" ) && str_contains( $admin_source, '$reset_busy ?' ), 'DPD geography admin notices classify busy/reset-required start and reset responses as warnings instead of success.' );

echo "DPD geography import smoke OK\n";

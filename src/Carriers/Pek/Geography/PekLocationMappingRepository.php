<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Geography;

defined( 'ABSPATH' ) || exit;

final class PekLocationMappingRepository {
	private object $wpdb;

	public function __construct( ?object $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
	}

	public function schema(): string {
		$charset = method_exists( $this->wpdb, 'get_charset_collate' ) ? $this->wpdb->get_charset_collate() : '';
		$table = $this->table_name();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			location_id bigint(20) unsigned NOT NULL,
			country_code char(2) NOT NULL,
			address_fingerprint char(64) NOT NULL,
			resolution_method varchar(32) NOT NULL,
			zone_id varchar(64) NULL,
			zone_name varchar(191) NULL,
			branch_id varchar(64) NULL,
			branch_title varchar(191) NULL,
			main_warehouse_id varchar(64) NULL,
			normalized_address text NULL,
			latitude decimal(10,7) NULL,
			longitude decimal(10,7) NULL,
			precision varchar(16) NULL,
			mapping_state varchar(32) NOT NULL,
			safe_diagnostic_json longtext NULL,
			checked_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY location_id (location_id),
			KEY country_code (country_code),
			KEY branch_id (branch_id),
			KEY main_warehouse_id (main_warehouse_id),
			KEY mapping_state (mapping_state),
			KEY checked_at (checked_at)
		) {$charset};";
	}

	public function install_schema(): void {
		if ( $this->has_test_rows() ) {
			return;
		}
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $this->schema() );
		}
	}

	/** @return array<string,mixed> */
	public function find_by_location_id( int $location_id ): array {
		$location_id = max( 0, $location_id );
		if ( $location_id <= 0 ) {
			return array();
		}
		if ( $this->has_test_rows() ) {
			$this->throw_if_test_failure( 'read' );
			foreach ( $this->wpdb->pek_location_mappings as $row ) {
				if ( (int) ( $row['location_id'] ?? 0 ) === $location_id ) {
					return $row;
				}
			}
			return array();
		}
		$this->clear_last_error();
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE location_id = %d LIMIT 1', $location_id ), ARRAY_A );
		$this->throw_on_sql_error( 'PEK location mapping lookup failed.' );

		return is_array( $row ) ? $row : array();
	}

	/** @param array<string,mixed> $mapping */
	public function upsert( array $mapping ): void {
		$row = $this->normalize_row( $mapping );
		if ( array() === $row ) {
			return;
		}
		$existing = $this->find_by_location_id( (int) $row['location_id'] );
		$row['created_at'] = (string) ( $existing['created_at'] ?? $row['created_at'] );
		if ( $this->has_test_rows() ) {
			$this->throw_if_test_failure( array() === $existing ? 'insert' : 'update' );
			$rows = array_values( array_filter( $this->wpdb->pek_location_mappings, static fn( array $item ): bool => (int) ( $item['location_id'] ?? 0 ) !== (int) $row['location_id'] ) );
			$row['id'] = (int) ( $existing['id'] ?? count( $rows ) + 1 );
			$rows[] = $row;
			$this->wpdb->pek_location_mappings = $rows;
			return;
		}
		$this->clear_last_error();
		if ( array() === $existing ) {
			$result = $this->wpdb->insert( $this->table_name(), $row );
		} else {
			unset( $row['id'], $row['created_at'] );
			$result = $this->wpdb->update( $this->table_name(), $row, array( 'location_id' => (int) $mapping['location_id'] ) );
		}
		if ( false === $result ) {
			throw new \RuntimeException( 'PEK location mapping persistence failed.' );
		}
		$this->throw_on_sql_error( 'PEK location mapping persistence failed.' );
	}

	public function delete_for_location( int $location_id ): void {
		if ( $this->has_test_rows() ) {
			$this->throw_if_test_failure( 'delete' );
			$this->wpdb->pek_location_mappings = array_values( array_filter( $this->wpdb->pek_location_mappings, static fn( array $row ): bool => (int) ( $row['location_id'] ?? 0 ) !== $location_id ) );
			return;
		}
		$this->clear_last_error();
		$result = $this->wpdb->delete( $this->table_name(), array( 'location_id' => $location_id ), array( '%d' ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'PEK location mapping delete failed.' );
		}
		$this->throw_on_sql_error( 'PEK location mapping delete failed.' );
	}

	/** @param array<string,mixed> $mapping */
	public function is_fresh( array $mapping, string $fingerprint, int $ttl_days = 30 ): bool {
		if ( array() === $mapping || $fingerprint !== (string) ( $mapping['address_fingerprint'] ?? '' ) ) {
			return false;
		}
		$checked_at = trim( (string) ( $mapping['checked_at'] ?? '' ) );
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$checked = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $checked_at, $timezone );
		$errors = \DateTimeImmutable::getLastErrors();
		if (
			false === $checked
			|| ( is_array( $errors ) && ( (int) ( $errors['warning_count'] ?? 0 ) > 0 || (int) ( $errors['error_count'] ?? 0 ) > 0 ) )
			|| $checked->format( 'Y-m-d H:i:s' ) !== $checked_at
		) {
			return false;
		}

		$day = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
		$now = function_exists( 'current_datetime' ) ? current_datetime()->getTimestamp() : time();
		$checked_timestamp = $checked->getTimestamp();
		if ( $checked_timestamp > $now ) {
			return false;
		}

		return $now - $checked_timestamp <= max( 1, $ttl_days ) * $day;
	}

	/** @return array<string,int> */
	public function statistics(): array {
		$rows = $this->has_test_rows() ? $this->wpdb->pek_location_mappings : array();
		if ( ! $this->has_test_rows() ) {
			$this->clear_last_error();
			$total = $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table_name() );
			$this->throw_on_sql_error( 'PEK location mapping statistics failed.' );
			return array( 'total' => (int) $total );
		}
		$this->throw_if_test_failure( 'statistics' );
		$stats = array( 'total' => count( $rows ), 'resolved' => 0, 'near' => 0, 'unsupported' => 0 );
		foreach ( $rows as $row ) {
			$state = (string) ( $row['mapping_state'] ?? '' );
			if ( isset( $stats[ $state ] ) ) {
				++$stats[ $state ];
			}
		}

		return $stats;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function normalize_row( array $row ): array {
		$location_id = (int) ( $row['location_id'] ?? 0 );
		$country = strtoupper( trim( (string) ( $row['country_code'] ?? '' ) ) );
		if ( $location_id <= 0 || ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return array();
		}
		$now = $this->now();
		$method = in_array( (string) ( $row['resolution_method'] ?? '' ), array( 'coordinates', 'address' ), true ) ? (string) $row['resolution_method'] : 'address';
		$state = in_array( (string) ( $row['mapping_state'] ?? '' ), array( 'resolved', 'near', 'unsupported' ), true ) ? (string) $row['mapping_state'] : 'unsupported';

		return array(
			'location_id' => $location_id,
			'country_code' => $country,
			'address_fingerprint' => substr( preg_replace( '/[^a-f0-9]/i', '', (string) ( $row['address_fingerprint'] ?? '' ) ) ?? '', 0, 64 ),
			'resolution_method' => $method,
			'zone_id' => $this->text( $row['zone_id'] ?? '' ),
			'zone_name' => $this->text( $row['zone_name'] ?? '' ),
			'branch_id' => $this->text( $row['branch_id'] ?? '' ),
			'branch_title' => $this->text( $row['branch_title'] ?? '' ),
			'main_warehouse_id' => $this->text( $row['main_warehouse_id'] ?? '' ),
			'normalized_address' => $this->text( $row['normalized_address'] ?? '' ),
			'latitude' => is_numeric( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'longitude' => is_numeric( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			'precision' => $this->text( $row['precision'] ?? '' ),
			'mapping_state' => $state,
			'safe_diagnostic_json' => (string) ( $row['safe_diagnostic_json'] ?? '' ),
			'checked_at' => $this->text( $row['checked_at'] ?? $now ),
			'created_at' => $this->text( $row['created_at'] ?? $now ),
			'updated_at' => $this->text( $row['updated_at'] ?? $now ),
		);
	}

	private function text( mixed $value ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $value ) ?? (string) $value;

		return trim( substr( $value, 0, 1000 ) );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function has_test_rows(): bool {
		return is_array( $this->wpdb->pek_location_mappings ?? null );
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_pek_location_mappings';
	}

	private function clear_last_error(): void {
		if ( property_exists( $this->wpdb, 'last_error' ) ) {
			$this->wpdb->last_error = '';
		}
	}

	private function throw_on_sql_error( string $message ): void {
		if ( '' !== trim( (string) ( $this->wpdb->last_error ?? '' ) ) ) {
			throw new \RuntimeException( $message );
		}
	}

	private function throw_if_test_failure( string $operation ): void {
		$flag = 'pek_location_mapping_' . $operation . '_fails';
		if ( ! empty( $this->wpdb->{$flag} ) ) {
			throw new \RuntimeException( 'PEK location mapping storage failed.' );
		}
	}
}

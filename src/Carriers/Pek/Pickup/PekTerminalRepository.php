<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Pickup;

defined( 'ABSPATH' ) || exit;

final class PekTerminalRepository {
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
			warehouse_id varchar(64) NOT NULL,
			branch_id varchar(64) NULL,
			branch_name varchar(191) NULL,
			division_name varchar(191) NULL,
			department_type_id int NULL,
			department_type varchar(191) NULL,
			source varchar(16) NOT NULL,
			country_code char(2) NOT NULL,
			address text NULL,
			latitude decimal(10,7) NULL,
			longitude decimal(10,7) NULL,
			timezone varchar(32) NULL,
			priority int NULL,
			max_weight decimal(12,3) NULL,
			max_volume decimal(12,6) NULL,
			max_dimension decimal(12,3) NULL,
			max_weight_one_place decimal(12,3) NULL,
			max_count int NULL,
			work_time text NULL,
			availability_json longtext NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			fetched_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY warehouse_id (warehouse_id),
			KEY branch_id (branch_id),
			KEY country_code (country_code),
			KEY source (source),
			KEY active (active),
			KEY fetched_at (fetched_at)
		) {$charset};";
	}

	public function install_schema(): void {
		if ( $this->has_test_rows() ) {
			return;
		}
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			throw new \RuntimeException( 'PEK terminal schema installation failed: dbDelta unavailable.' );
		}
		$this->clear_last_error();
		dbDelta( $this->schema() );
		$this->throw_on_sql_error( 'PEK terminal schema installation failed.' );
	}

	/** @param array<int,array<string,mixed>> $rows @return array{received:int,saved:int,skipped_invalid:int} */
	public function upsert_many( array $rows ): array {
		$report = array( 'received' => count( $rows ), 'saved' => 0, 'skipped_invalid' => 0 );
		foreach ( $rows as $row ) {
			$normalized = is_array( $row ) ? $this->normalize_row( $row ) : array();
			if ( array() === $normalized ) {
				++$report['skipped_invalid'];
				continue;
			}
			if ( $this->has_test_rows() ) {
				$this->throw_if_test_failure( $this->find_by_warehouse_id( (string) $normalized['warehouse_id'] ) === array() ? 'insert' : 'update' );
				$items = array_values( array_filter( $this->wpdb->pek_terminals, static fn( array $item ): bool => (string) ( $item['warehouse_id'] ?? '' ) !== (string) $normalized['warehouse_id'] ) );
				$existing = $this->find_by_warehouse_id( (string) $normalized['warehouse_id'] );
				if ( array() !== $existing ) {
					$normalized['created_at'] = (string) ( $existing['created_at'] ?? $normalized['created_at'] );
				}
				$items[] = $normalized;
				$this->wpdb->pek_terminals = $items;
			} else {
				$existing = $this->find_by_warehouse_id( (string) $normalized['warehouse_id'] );
				$this->clear_last_error();
				if ( array() === $existing ) {
					$result = $this->wpdb->insert( $this->table_name(), $normalized );
				} else {
					unset( $normalized['created_at'] );
					$result = $this->wpdb->update( $this->table_name(), $normalized, array( 'warehouse_id' => $normalized['warehouse_id'] ) );
				}
				if ( false === $result ) {
					throw new \RuntimeException( 'PEK terminal persistence failed.' );
				}
				$this->throw_on_sql_error( 'PEK terminal persistence failed.' );
			}
			++$report['saved'];
		}

		return $report;
	}

	/** @return array<string,mixed> */
	public function find_by_warehouse_id( string $warehouse_id ): array {
		$warehouse_id = trim( $warehouse_id );
		if ( '' === $warehouse_id ) {
			return array();
		}
		if ( $this->has_test_rows() ) {
			$this->throw_if_test_failure( 'read' );
			foreach ( $this->wpdb->pek_terminals as $row ) {
				if ( $warehouse_id === (string) ( $row['warehouse_id'] ?? '' ) ) {
					return $row;
				}
			}
			return array();
		}
		$this->clear_last_error();
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE warehouse_id = %s LIMIT 1', $warehouse_id ), ARRAY_A );
		$this->throw_on_sql_error( 'PEK terminal lookup failed.' );

		return is_array( $row ) ? $row : array();
	}

	/** @param array<int,string> $warehouse_ids @return array<string,array<string,mixed>> */
	public function find_by_ids( array $warehouse_ids ): array {
		$result = array();
		foreach ( $warehouse_ids as $id ) {
			$row = $this->find_by_warehouse_id( (string) $id );
			if ( array() !== $row ) {
				$result[ (string) $row['warehouse_id'] ] = $row;
			}
		}

		return $result;
	}

	/** @return array<string,int> */
	public function statistics(): array {
		if ( $this->has_test_rows() ) {
			$this->throw_if_test_failure( 'statistics' );
			return array( 'total' => count( $this->wpdb->pek_terminals ) );
		}
		$this->clear_last_error();
		$total = $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table_name() );
		$this->throw_on_sql_error( 'PEK terminal statistics failed.' );

		return array( 'total' => (int) $total );
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function normalize_row( array $row ): array {
		$warehouse_id = trim( (string) ( $row['warehouse_id'] ?? $row['warehouseId'] ?? '' ) );
		$source = (string) ( $row['source'] ?? '' );
		if ( '' === $warehouse_id || ! in_array( $source, array( 'free', 'paid' ), true ) ) {
			return array();
		}
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

		return array(
			'warehouse_id' => $warehouse_id,
			'branch_id' => $this->text( $row['branch_id'] ?? $row['branchId'] ?? '' ),
			'branch_name' => $this->text( $row['branch_name'] ?? $row['branchName'] ?? '' ),
			'division_name' => $this->text( $row['division_name'] ?? $row['divisionName'] ?? '' ),
			'department_type_id' => (int) ( $row['department_type_id'] ?? $row['departmentTypeId'] ?? 0 ),
			'department_type' => $this->text( $row['department_type'] ?? $row['departmentType'] ?? '' ),
			'source' => $source,
			'country_code' => strtoupper( $this->text( $row['country_code'] ?? 'RU' ) ),
			'address' => $this->text( $row['address'] ?? '' ),
			'latitude' => is_numeric( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'longitude' => is_numeric( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			'timezone' => $this->text( $row['timezone'] ?? $row['branchTimezone'] ?? '' ),
			'priority' => (int) ( $row['priority'] ?? 0 ),
			'max_weight' => $this->number_or_null( $row['max_weight'] ?? $row['maxWeight'] ?? null ),
			'max_volume' => $this->number_or_null( $row['max_volume'] ?? $row['maxVolume'] ?? null ),
			'max_dimension' => $this->number_or_null( $row['max_dimension'] ?? $row['maxDimension'] ?? null ),
			'max_weight_one_place' => $this->number_or_null( $row['max_weight_one_place'] ?? $row['maxWeightOnePlace'] ?? null ),
			'max_count' => is_numeric( $row['max_count'] ?? $row['maxCount'] ?? null ) ? (int) ( $row['max_count'] ?? $row['maxCount'] ) : null,
			'work_time' => $this->text( $row['work_time'] ?? $row['workTime'] ?? '' ),
			'availability_json' => is_array( $row['availability'] ?? null ) ? ( wp_json_encode( $row['availability'] ) ?: '{}' ) : '{}',
			'active' => 1,
			'fetched_at' => $this->text( $row['fetched_at'] ?? $now ),
			'created_at' => $this->text( $row['created_at'] ?? $now ),
			'updated_at' => $this->text( $row['updated_at'] ?? $now ),
		);
	}

	private function text( mixed $value ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $value ) ?? (string) $value;

		return trim( substr( $value, 0, 1000 ) );
	}

	private function number_or_null( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}

	private function has_test_rows(): bool {
		return is_array( $this->wpdb->pek_terminals ?? null );
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_pek_terminals';
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
		$flag = 'pek_terminal_' . $operation . '_fails';
		if ( ! empty( $this->wpdb->{$flag} ) ) {
			throw new \RuntimeException( 'PEK terminal storage failed.' );
		}
	}
}

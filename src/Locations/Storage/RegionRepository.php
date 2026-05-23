<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Storage;

use WallsShop\WDC\Locations\ValueObjects\Region;

defined( 'ABSPATH' ) || exit;

final class RegionRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	public function save( Region $region ): string {
		$now = current_time( 'mysql' );
		$row = array_merge(
			$region->to_array(),
			array(
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$existing = $this->find_by_code( $region->region_code );
		if ( $existing instanceof Region ) {
			unset( $row['created_at'] );
			$this->wpdb->update( $this->table_name(), $row, array( 'region_code' => $region->region_code ), array( '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%s' ) );
			return $region->region_code;
		}

		$this->wpdb->insert( $this->table_name(), $row, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		return $region->region_code;
	}

	/**
	 * @param array<int,Region> $regions
	 */
	public function bulk_upsert( array $regions ): int {
		$count = 0;
		foreach ( $regions as $region ) {
			if ( $region instanceof Region ) {
				$this->save( $region );
				++$count;
			}
		}

		return $count;
	}

	public function find_by_code( string $code ): ?Region {
		$code = trim( $code );
		if ( '' === $code ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table_name()} WHERE region_code = %s LIMIT 1", $code ),
			ARRAY_A
		);

		return is_array( $row ) ? Region::from_array( $row ) : null;
	}

	/**
	 * @return array<int,Region>
	 */
	public function all(): array {
		$rows = $this->wpdb->get_results( "SELECT * FROM {$this->table_name()} ORDER BY region_name ASC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( static fn( array $row ): Region => Region::from_array( $row ), $rows );
	}

	public function count_all(): int {
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name()}" );
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_regions';
	}
}

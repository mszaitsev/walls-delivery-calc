<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
function current_time( string $type, bool $gmt = false ): string { return '2026-09-13 00:00:00'; }
function oz_bulk_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

require_once dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupRepository.php';
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupRepository;

final class OzonBulkWpdb {
	public string $prefix = 'wp_';
	/** @var array<string,mixed> */ public array $generation;
	/** @var array<int,array{status:string,reject_code:?string}> */ public array $ids = array();
	/** @var array<int,true> */ public array $points = array();
	/** @var list<string> */ public array $writes = array();
	public int $fail_write = 0;
	private int $write_number = 0;
	/** @var array<string,mixed>|null */ private ?array $backup = null;

	public function __construct( string $phase = 'discovery' ) {
		$this->generation = array( 'id' => 7, 'state' => 'building', 'phase' => $phase, 'accepted_count' => 0, 'rejected_count' => 0, 'enrichment_processed_count' => 0 );
	}
	public function prepare( string $query, mixed ...$args ): string {
		$i = 0;
		return (string) preg_replace_callback( '/%[dfs]/', static function ( array $match ) use ( &$i, $args ): string { $value = $args[ $i++ ]; return match ( $match[0] ) { '%d' => (string) (int) $value, '%f' => sprintf( '%.7F', (float) $value ), default => "'" . str_replace( "'", "''", (string) $value ) . "'" }; }, $query );
	}
	public function get_row( string $query, mixed $output = null ): ?array { return str_contains( $query, 'WHERE id=7' ) ? $this->generation : null; }
	public function get_var( string $query ): int|string {
		if ( str_contains( $query, "status='pending'" ) || str_contains( $query, "status = 'pending'" ) ) { return count( array_filter( $this->ids, static fn( array $row ): bool => 'pending' === $row['status'] ) ); }
		return count( $this->ids );
	}
	/** @param array<string,mixed> $data @param array<string,mixed> $where */
	public function update( string $table, array $data, array $where ): int|false {
		if ( $this->should_fail( 'UPDATE generation' ) ) { return false; }
		$this->generation = array_merge( $this->generation, $data );
		return 1;
	}
	public function query( string $sql ): int|false {
		if ( 'START TRANSACTION' === $sql ) { $this->backup = array( 'generation' => $this->generation, 'ids' => $this->ids, 'points' => $this->points ); return 1; }
		if ( 'ROLLBACK' === $sql ) { if ( is_array( $this->backup ) ) { $this->generation = $this->backup['generation']; $this->ids = $this->backup['ids']; $this->points = $this->backup['points']; } $this->backup = null; return 1; }
		if ( 'COMMIT' === $sql ) { $this->backup = null; return 1; }
		if ( $this->should_fail( $sql ) ) { return false; }
		if ( str_starts_with( $sql, 'INSERT IGNORE INTO wp_wdc_ozon_delivery_pickup_ids' ) ) {
			preg_match_all( "/\(7,(\d+),'pending'/", $sql, $matches ); $affected = 0;
			foreach ( $matches[1] as $id ) { $id = (int) $id; if ( ! isset( $this->ids[ $id ] ) ) { $this->ids[ $id ] = array( 'status' => 'pending', 'reject_code' => null ); ++$affected; } }
			return $affected;
		}
		if ( str_starts_with( $sql, 'INSERT INTO wp_wdc_ozon_delivery_pickup_points' ) ) {
			preg_match_all( '/\(7,(\d+),/', $sql, $matches );
			foreach ( $matches[1] as $id ) { if ( isset( $this->points[(int) $id] ) ) { return false; } }
			foreach ( $matches[1] as $id ) { $this->points[(int) $id] = true; }
			return count( $matches[1] );
		}
		if ( str_starts_with( $sql, 'UPDATE wp_wdc_ozon_delivery_pickup_ids' ) ) {
			preg_match( '/point_id IN \(([^)]+)\)/', $sql, $in ); $ids = array_map( 'intval', explode( ',', $in[1] ?? '' ) ); $affected = 0;
			$rejected = str_contains( $sql, "status='rejected'" );
			$codes = array(); preg_match_all( "/WHEN (\d+) THEN '([^']*)'/", $sql, $case ); foreach ( $case[1] as $index => $id ) { $codes[(int) $id] = $case[2][$index]; }
			foreach ( $ids as $id ) { if ( isset( $this->ids[$id] ) && 'pending' === $this->ids[$id]['status'] ) { $this->ids[$id] = array( 'status' => $rejected ? 'rejected' : 'enriched', 'reject_code' => $rejected ? ( $codes[$id] ?? '' ) : null ); ++$affected; } }
			return $affected;
		}
		return 1;
	}
	private function should_fail( string $sql ): bool { ++$this->write_number; $this->writes[] = $sql; return $this->fail_write > 0 && $this->write_number === $this->fail_write; }
}

/** @return array<string,mixed> */
function oz_bulk_point( int $id ): array { return array( 'point_id' => $id, 'name' => 'Point ' . $id, 'point_number' => 'N' . $id, 'type' => 'pvz', 'full_address' => 'Address ' . $id, 'latitude' => 55.0, 'longitude' => 82.0, 'schedule' => '', 'is_active' => 1, 'is_bulky' => 0, 'storage_period_days' => null, 'fitting_rooms_count' => null, 'min_weight_g' => null, 'max_weight_g' => 1000, 'max_width_mm' => null, 'max_length_mm' => null, 'max_height_mm' => null, 'fingerprint' => str_repeat( 'a', 64 ) ); }

$db = new OzonBulkWpdb();
$repository = new OzonDeliveryPickupRepository( $db );
$ids = range( 1, 600 );
oz_bulk_assert( $repository->commit_discovery_page( 7, $ids, array( 'cursor_value' => 'next', 'page_count' => 1 ) ), '600-ID discovery page must commit.' );
$discovery_inserts = array_filter( $db->writes, static fn( string $sql ): bool => str_starts_with( $sql, 'INSERT IGNORE' ) );
oz_bulk_assert( 3 === count( $discovery_inserts ) && 600 === count( $db->ids ) && 600 === (int) $db->generation['discovered_count'], '600 discovery IDs must use three 250-row INSERT IGNORE chunks with parity counters.' );

$duplicate_db = new OzonBulkWpdb(); $duplicate_db->ids[1] = array( 'status' => 'pending', 'reject_code' => null );
$duplicate_repository = new OzonDeliveryPickupRepository( $duplicate_db );
oz_bulk_assert( $duplicate_repository->commit_discovery_page( 7, array( 1, 1, 2 ), array( 'cursor_value' => null, 'phase' => 'enrichment' ) ) && 2 === (int) $duplicate_db->generation['discovered_count'] && 2 === (int) $duplicate_db->generation['downloaded_count'] && 'enrichment' === $duplicate_db->generation['phase'], 'INSERT IGNORE duplicates must preserve unique counts and terminal cursor phase transition.' );

$failed_db = new OzonBulkWpdb(); $failed_db->fail_write = 2; $failed_repository = new OzonDeliveryPickupRepository( $failed_db );
oz_bulk_assert( ! $failed_repository->commit_discovery_page( 7, range( 1, 300 ), array( 'cursor_value' => 'next' ) ) && array() === $failed_db->ids && ! isset( $failed_db->generation['cursor_value'] ), 'Discovery bulk failure must roll back rows and generation patch.' );

$accepted_db = new OzonBulkWpdb( 'enrichment' ); foreach ( range( 1, 100 ) as $id ) { $accepted_db->ids[$id] = array( 'status' => 'pending', 'reject_code' => null ); }
$accepted_repository = new OzonDeliveryPickupRepository( $accepted_db ); $points = array_map( 'oz_bulk_point', range( 1, 100 ) );
oz_bulk_assert( $accepted_repository->commit_enrichment_batch( 7, $points, array() ), '100 accepted points must commit.' );
oz_bulk_assert( 3 === count( $accepted_db->writes ) && 100 === count( $accepted_db->points ) && 100 === (int) $accepted_db->generation['accepted_count'] && 0 === count( array_filter( $accepted_db->ids, static fn( array $row ): bool => 'pending' === $row['status'] ) ), '100 accepted must use one point INSERT, one set-based ID UPDATE and one generation UPDATE.' );

$mixed_db = new OzonBulkWpdb( 'enrichment' ); foreach ( range( 1, 100 ) as $id ) { $mixed_db->ids[$id] = array( 'status' => 'pending', 'reject_code' => null ); }
$mixed_repository = new OzonDeliveryPickupRepository( $mixed_db ); $rejects = array_fill_keys( range( 51, 100 ), 'not_found_404' );
oz_bulk_assert( $mixed_repository->commit_enrichment_batch( 7, array_map( 'oz_bulk_point', range( 1, 50 ) ), $rejects ), 'Mixed enrichment must commit.' );
oz_bulk_assert( 4 === count( $mixed_db->writes ) && 50 === (int) $mixed_db->generation['accepted_count'] && 50 === (int) $mixed_db->generation['rejected_count'] && 'not_found_404' === $mixed_db->ids[100]['reject_code'], '50/50 enrichment must use four bounded writes and preserve reject codes/counters.' );

$rejected_db = new OzonBulkWpdb( 'enrichment' ); foreach ( range( 1, 100 ) as $id ) { $rejected_db->ids[$id] = array( 'status' => 'pending', 'reject_code' => null ); }
oz_bulk_assert( ( new OzonDeliveryPickupRepository( $rejected_db ) )->commit_enrichment_batch( 7, array(), array_fill_keys( range( 1, 100 ), 'info_missing' ) ) && 2 === count( $rejected_db->writes ), 'All-rejected enrichment must use one CASE status UPDATE plus one generation UPDATE.' );

$stale_db = new OzonBulkWpdb( 'enrichment' ); foreach ( range( 1, 100 ) as $id ) { $stale_db->ids[$id] = array( 'status' => 'pending', 'reject_code' => null ); } $stale_db->ids[100]['status'] = 'enriched';
oz_bulk_assert( ! ( new OzonDeliveryPickupRepository( $stale_db ) )->commit_enrichment_batch( 7, $points, array() ) && array() === $stale_db->points && 'enriched' === $stale_db->ids[100]['status'] && 0 === (int) $stale_db->generation['accepted_count'], 'A non-pending ID must fail affected-row integrity and roll back the whole batch.' );

$conflict_db = new OzonBulkWpdb( 'enrichment' ); $conflict_db->ids[1] = array( 'status' => 'pending', 'reject_code' => null ); $conflict_db->points[1] = true;
oz_bulk_assert( ! ( new OzonDeliveryPickupRepository( $conflict_db ) )->commit_enrichment_batch( 7, array( oz_bulk_point( 1 ) ), array() ) && 'pending' === $conflict_db->ids[1]['status'] && 0 === (int) $conflict_db->generation['accepted_count'], 'A conflicting point INSERT must fail and preserve the pre-existing row without advancing ID state.' );

foreach ( array( 1, 2, 3, 4 ) as $failure_write ) { $db = new OzonBulkWpdb( 'enrichment' ); foreach ( range( 1, 2 ) as $id ) { $db->ids[$id] = array( 'status' => 'pending', 'reject_code' => null ); } $db->fail_write = $failure_write; oz_bulk_assert( ! ( new OzonDeliveryPickupRepository( $db ) )->commit_enrichment_batch( 7, array( oz_bulk_point( 1 ) ), array( 2 => 'info_missing' ) ) && array() === $db->points && 'pending' === $db->ids[1]['status'] && 0 === (int) $db->generation['enrichment_processed_count'], 'Each bulk enrichment write failure must roll back all atomic-unit changes.' ); }

echo "Ozon Delivery pickup bulk query-count smoke passed.\n";

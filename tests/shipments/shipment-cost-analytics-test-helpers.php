<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Analytics\CreatedShipmentIdentityResolver;
use WallsShop\WDC\Shipments\Analytics\OrderAnalyticsShipmentSelector;
use WallsShop\WDC\Shipments\Analytics\OrderSelectedDeliveryIdentityResolver;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsIndexer;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsQuery;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsRecordBuilder;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsService;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostThresholdPolicy;
use WallsShop\WDC\Shipments\Analytics\Storage\ShipmentCostAnalyticsRepository;
use WallsShop\WDC\Shipments\Analytics\Storage\ShipmentCostAnalyticsTable;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ?? '' ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed { return $value; }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone(): DateTimeZone { return new DateTimeZone( 'Europe/Moscow' ); }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string { unset( $type ); return $gmt ? '2026-07-21 07:00:00' : '2026-07-21 10:00:00'; }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
}
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( int $order_id ): ?ShipmentCostAnalyticsFakeOrder { return $GLOBALS['shipment_cost_analytics_orders'][ $order_id ] ?? null; }
}
if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger(): object {
		return new class() {
			public function log( string $level, string $message, array $context = array() ): void {
				$GLOBALS['shipment_cost_analytics_logs'][] = compact( 'level', 'message', 'context' );
			}
		};
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		$GLOBALS['shipment_cost_analytics_actions'][] = array( 'hook' => $hook, 'args' => $args );
	}
}

final class ShipmentCostAnalyticsFakeWpdb {
	public string $prefix = 'wp_';
	/** @var array<int,array<string,mixed>> */
	public array $rows = array();
	/** @var array<int,mixed> */
	public array $queries = array();
	public string $last_error = '';
	public bool $fail_next_query = false;
	private int $next_id = 1;

	public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function prepare( string $query, mixed ...$args ): array {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return array( 'sql' => $query, 'args' => array_values( $args ) );
	}
	public function query( mixed $prepared ): bool {
		$this->queries[] = $prepared;
		if ( $this->fail_next_query ) {
			$this->fail_next_query = false;
			$this->last_error = 'simulated analytics SQL failure';
			return false;
		}
		$sql = is_array( $prepared ) ? (string) $prepared['sql'] : (string) $prepared;
		$args = is_array( $prepared ) ? $prepared['args'] : array();
		if ( str_starts_with( $sql, 'INSERT INTO' ) ) {
			$columns = $this->insert_columns( $sql );
			$row = $this->insert_row( $sql, $columns, $args );
			$order_id = (int) ( $row['order_id'] ?? 0 );
			foreach ( $this->rows as $id => $existing ) {
				if ( (int) $existing['order_id'] === $order_id ) {
					$this->rows[ $id ] = array_merge( array( 'id' => $id ), $row );
					return true;
				}
			}
			$id = $this->next_id++;
			$this->rows[ $id ] = array_merge( array( 'id' => $id ), $row );
			return true;
		}
		if ( str_starts_with( $sql, 'DELETE FROM' ) ) {
			$order_id = (int) ( $args[0] ?? 0 );
			foreach ( $this->rows as $id => $row ) {
				if ( (int) $row['order_id'] === $order_id ) {
					unset( $this->rows[ $id ] );
				}
			}
		}
		return true;
	}
	public function get_var( mixed $prepared ): mixed {
		$filtered = $this->filtered( $prepared );
		return count( $filtered );
	}
	public function get_results( mixed $prepared, mixed $output = null ): array {
		unset( $output );
		$sql = is_array( $prepared ) ? (string) $prepared['sql'] : (string) $prepared;
		$filtered = $this->filtered( $prepared );
		if ( str_contains( $sql, 'COUNT(*) AS shipment_count' ) ) {
			return array( $this->summary_row( $filtered ) );
		}
		$ordered = $this->ordered( $filtered, $sql );
		$args = is_array( $prepared ) ? $prepared['args'] : array();
		$limit = (int) ( $args[ count( $args ) - 2 ] ?? count( $ordered ) );
		$offset = (int) ( $args[ count( $args ) - 1 ] ?? 0 );
		return array_values( array_slice( $ordered, $offset, $limit ) );
	}
	private function filtered( mixed $prepared ): array {
		$sql = is_array( $prepared ) ? (string) $prepared['sql'] : (string) $prepared;
		$args = is_array( $prepared ) ? $prepared['args'] : array();
		$args = array_values( array_filter( $args, static fn( mixed $value ): bool => ! is_int( $value ) || $value > 100 || str_contains( $sql, 'order_id = %d' ) ) );
		$from = (string) ( $args[0] ?? '0000-00-00 00:00:00' );
		$to = (string) ( $args[1] ?? '9999-12-31 23:59:59' );
		$index = 2;
		$carrier = str_contains( $sql, 'carrier_key = %s' ) ? (string) ( $args[ $index++ ] ?? '' ) : null;
		$search_id = null;
		$search_number = null;
		if ( str_contains( $sql, 'order_id = %d OR order_number = %s' ) ) {
			$search_id = (int) ( $args[ $index++ ] ?? 0 );
			$search_number = (string) ( $args[ $index++ ] ?? '' );
		} elseif ( str_contains( $sql, 'order_number = %s' ) ) {
			$search_number = (string) ( $args[ $index++ ] ?? '' );
		}
		return array_values( array_filter( $this->rows, static function ( array $row ) use ( $sql, $from, $to, $carrier, $search_id, $search_number ): bool {
			if ( (string) $row['order_created_at'] < $from || (string) $row['order_created_at'] > $to ) { return false; }
			if ( null !== $carrier && (string) $row['carrier_key'] !== $carrier ) { return false; }
			if ( 1 === preg_match( '/WHERE .*actual_cost_kopecks IS NOT NULL.*actual_cost_kopecks > 0/s', $sql ) && ( ! isset( $row['actual_cost_kopecks'] ) || (int) $row['actual_cost_kopecks'] <= 0 ) ) { return false; }
			if ( null !== $search_id && (int) $row['order_id'] !== $search_id && (string) $row['order_number'] !== $search_number ) { return false; }
			if ( null === $search_id && null !== $search_number && (string) $row['order_number'] !== $search_number ) { return false; }
			return true;
		} ) );
	}
	private function ordered( array $rows, string $sql ): array {
		usort( $rows, static function ( array $a, array $b ) use ( $sql ): int {
			$direction = str_contains( $sql, ' ASC' ) && ! str_contains( $sql, 'order_created_at DESC' ) ? 'asc' : 'desc';
			$column = 'order_created_at';
			foreach ( array( 'order_number', 'carrier_key', 'base_api_cost_kopecks', 'actual_cost_kopecks', 'difference_kopecks', 'difference_percent_basis_points' ) as $candidate ) {
				if ( str_contains( $sql, 'ORDER BY ' . $candidate ) || str_contains( $sql, 'ORDER BY ' . $candidate . ' IS NULL' ) ) { $column = $candidate; break; }
			}
			$av = $a[ $column ] ?? null; $bv = $b[ $column ] ?? null;
			if ( null === $av && null !== $bv ) { return 1; }
			if ( null !== $av && null === $bv ) { return -1; }
			$result = is_numeric( $av ) && is_numeric( $bv ) ? (int) $av <=> (int) $bv : strnatcasecmp( (string) $av, (string) $bv );
			if ( 0 === $result ) { $result = (int) $b['order_id'] <=> (int) $a['order_id']; }
			return 'desc' === $direction ? -$result : $result;
		} );
		return $rows;
	}
	private function summary_row( array $rows ): array {
		$summary = array_fill_keys( array( 'shipment_count', 'with_actual_count', 'without_actual_count', 'planned_total_kopecks', 'actual_total_kopecks', 'comparable_planned_total_kopecks', 'difference_total_kopecks', 'average_difference_percent_basis_points', 'over_threshold_count', 'comparable_count' ), 0 );
		$summary['shipment_count'] = count( $rows );
		$percent_total = 0;
		foreach ( $rows as $row ) {
			$base = isset( $row['base_api_cost_kopecks'] ) ? (int) $row['base_api_cost_kopecks'] : null;
			$actual = isset( $row['actual_cost_kopecks'] ) ? (int) $row['actual_cost_kopecks'] : null;
			if ( null !== $actual && $actual > 0 ) { ++$summary['with_actual_count']; $summary['actual_total_kopecks'] += $actual; } else { ++$summary['without_actual_count']; }
			if ( null !== $base && $base > 0 ) { $summary['planned_total_kopecks'] += $base; }
			if ( isset( $row['difference_kopecks'] ) && null !== $row['difference_kopecks'] ) {
				$summary['comparable_planned_total_kopecks'] += (int) $base;
				$summary['difference_total_kopecks'] += (int) $row['difference_kopecks'];
				$percent_total += (int) $row['difference_percent_basis_points'];
				++$summary['comparable_count'];
			}
			if ( 'over_threshold' === (string) ( $row['threshold_status'] ?? '' ) ) { ++$summary['over_threshold_count']; }
		}
		$summary['average_difference_percent_basis_points'] = $summary['comparable_count'] > 0 ? intdiv( $percent_total, $summary['comparable_count'] ) : null;
		return $summary;
	}
	/** @return array<int,string> */
	private function insert_columns( string $sql ): array {
		if ( 1 !== preg_match( '/INSERT INTO\s+\S+\s+\(([^)]+)\)\s+VALUES/s', $sql, $matches ) ) {
			return array();
		}
		return array_map( 'trim', explode( ',', $matches[1] ) );
	}
	/**
	 * @param array<int,string> $columns
	 * @param array<int,mixed> $args
	 * @return array<string,mixed>
	 */
	private function insert_row( string $sql, array $columns, array $args ): array {
		if ( 1 !== preg_match( '/VALUES\s+\((.*?)\)\s+ON DUPLICATE KEY UPDATE/s', $sql, $matches ) ) {
			return array_combine( $columns, $args ) ?: array();
		}
		$tokens = array_map( 'trim', explode( ',', $matches[1] ) );
		$row = array();
		$arg_index = 0;
		foreach ( $columns as $index => $column ) {
			$token = strtoupper( $tokens[ $index ] ?? '' );
			if ( 'NULL' === $token ) {
				$row[ $column ] = null;
				continue;
			}
			$row[ $column ] = $args[ $arg_index++ ] ?? null;
		}
		return $row;
	}
}

final class ShipmentCostAnalyticsFakeCarrier implements CarrierAdapterInterface {
	public function __construct( private string $key, private string $name ) {}
	public function get_identity(): CarrierIdentity { return new CarrierIdentity( $this->key, $this->name ); }
	public function get_capabilities(): CarrierCapabilities { return new CarrierCapabilities(); }
	public function supports_country( string $countryCode ): bool { unset( $countryCode ); return true; }
	public function quote( QuoteRequest $request ): DeliveryQuote { throw new RuntimeException( 'Analytics tests must not call carrier APIs.' ); }
}

final class ShipmentCostAnalyticsFakeDate extends DateTimeImmutable {
	public function date( string $format ): string { return $this->format( $format ); }
}

final class ShipmentCostAnalyticsFakeOrder {
	/** @param array<string,mixed> $shipments */
	public function __construct(
		private int $id,
		private string $number,
		private string $created_at,
		private ?int $base_kopecks,
		public array $shipments,
		private ?string $selected_carrier,
		private string $selected_service = ''
	) {}
	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return $this->number; }
	public function get_date_created(): ShipmentCostAnalyticsFakeDate { return new ShipmentCostAnalyticsFakeDate( $this->created_at ); }
	public function get_edit_order_url(): string { return 'https://example.test/order/' . $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed {
		unset( $single );
		if ( OrderShipmentRepository::META_KEY === $key ) { return $this->shipments; }
		if ( OrderShippingMetaPersister::CALCULATION_META_KEY === $key ) {
			$meta = array( 'api' => array( 'api_base_price_kopecks' => $this->base_kopecks ) );
			if ( null !== $this->selected_carrier ) { $meta['carrier_key'] = $this->selected_carrier; $meta['service_key'] = $this->selected_service; }
			return $meta;
		}
		return '';
	}
	public function update_meta_data( string $key, mixed $value ): void {
		if ( OrderShipmentRepository::META_KEY === $key ) { $this->shipments = is_array( $value ) ? $value : array(); }
	}
	public function save(): void {}
}

function shipment_cost_analytics_test_bootstrap(): array {
	global $wpdb;
	$wpdb = new ShipmentCostAnalyticsFakeWpdb();
	$GLOBALS['shipment_cost_analytics_orders'] = array();
	$GLOBALS['shipment_cost_analytics_actions'] = array();
	$GLOBALS['shipment_cost_analytics_logs'] = array();
	$registry = new CarrierRegistry();
	$registry->register( new ShipmentCostAnalyticsFakeCarrier( 'alpha', 'Alpha Carrier' ) );
	$registry->register( new ShipmentCostAnalyticsFakeCarrier( 'beta', 'Beta Carrier' ) );
	$registry->register( new ShipmentCostAnalyticsFakeCarrier( 'fresh', 'Fresh Dynamic Carrier' ) );
	$table = new ShipmentCostAnalyticsTable();
	$repository = new ShipmentCostAnalyticsRepository( $table );
	$selector = new OrderAnalyticsShipmentSelector( new OrderSelectedDeliveryIdentityResolver(), new CreatedShipmentIdentityResolver() );
	$threshold = new ShipmentCostThresholdPolicy();
	$builder = new ShipmentCostAnalyticsRecordBuilder( new OrderShipmentRepository(), $selector, new ShipmentBaseApiCostResolver(), $threshold );
	$logger = new Logger();
	$indexer = new ShipmentCostAnalyticsIndexer( $builder, $repository, $logger );
	$query = new ShipmentCostAnalyticsQuery( $repository );
	$service = new ShipmentCostAnalyticsService( $query, $registry );

	return compact( 'repository', 'indexer', 'query', 'service', 'registry', 'table', 'wpdb' );
}

function shipment_cost_analytics_register_order( ShipmentCostAnalyticsFakeOrder $order ): ShipmentCostAnalyticsFakeOrder {
	$GLOBALS['shipment_cost_analytics_orders'][ $order->get_id() ] = $order;
	return $order;
}

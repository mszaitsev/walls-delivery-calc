<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

function wp_json_encode( mixed $value ): string|false { return json_encode( $value, JSON_UNESCAPED_UNICODE ); }
function current_time( string $type, bool $gmt = false ): string { return '2026-08-28 00:00:00'; }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['wdc_options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value, mixed $autoload = null ): bool { $GLOBALS['wdc_options'][ $name ] = $value; return true; }
function oz_pickup_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

require_once dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupParser.php';
require_once dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupRepository.php';
require_once dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupScheduleFormatter.php';
require_once dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Api/OzonDeliveryApiClient.php';
require_once dirname( __DIR__, 2 ) . '/src/Infrastructure/Database/MigrationManager.php';

use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupParser;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupRepository;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupScheduleFormatter;
use WallsShop\WDC\Infrastructure\Database\MigrationManager;

final class OzonPickupGeoIndexMigrationWpdb {
	public string $prefix = 'wp_';
	public bool $index_exists;
	public bool $fail_alter;
	public bool $fail_postcondition;
	public int $alter_count = 0;
	/** @var list<string> */
	public array $queries = array();

	public function __construct( bool $index_exists = false, bool $fail_alter = false, bool $fail_postcondition = false ) {
		$this->index_exists = $index_exists;
		$this->fail_alter = $fail_alter;
		$this->fail_postcondition = $fail_postcondition;
	}

	public function prepare( string $query, mixed ...$arguments ): string {
		foreach ( $arguments as $argument ) {
			$query = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $argument ) . "'", $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function get_row( string $query, mixed $output = null ): ?array {
		$this->queries[] = $query;
		if ( str_contains( $query, 'SHOW INDEX' ) && str_contains( $query, '`wp_wdc_ozon_delivery_pickup_points`' ) && str_contains( $query, "'active_geo_lookup'" ) && $this->index_exists ) {
			return array( 'Key_name' => 'active_geo_lookup' );
		}
		return null;
	}

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		if ( str_contains( $query, 'ALTER TABLE' ) && str_contains( $query, 'ADD KEY active_geo_lookup (generation_id,is_active,latitude,longitude)' ) ) {
			++$this->alter_count;
			if ( $this->fail_alter ) {
				return false;
			}
			if ( ! $this->fail_postcondition ) {
				$this->index_exists = true;
			}
			return 1;
		}
		return 1;
	}
}

final class OzonPickupActivationWpdb {
	public string $prefix = 'wp_';
	public int $insert_id = 3;
	/** @var array<int,array<string,mixed>> */
	public array $generations;
	/** @var array<int,int> */
	public array $points_by_generation = array();
	/** @var array<string,mixed> */
	public array $failures;
	/** @var array<int,array<string,mixed>>|null */
	private ?array $transaction_backup = null;
	/** @var list<int> */
	public array $deleted_generation_ids = array();

	/** @param array<int,array<string,mixed>> $generations @param array<string,mixed> $failures */
	public function __construct( array $generations, array $failures = array() ) {
		$this->generations = $generations;
		$this->failures = $failures;
		if ( isset( $failures['insert_id'] ) ) {
			$this->insert_id = (int) $failures['insert_id'];
		}
	}

	public function prepare( string $query, mixed ...$arguments ): string {
		$index = 0;
		return (string) preg_replace_callback(
			'/%[ds]/',
			static function ( array $match ) use ( &$index, $arguments ): string {
				$value = $arguments[ $index++ ];
				return '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
			},
			$query
		);
	}

	public function get_row( string $query, mixed $output = null ): ?array {
		if ( preg_match( '/WHERE id=(\d+)/', $query, $matches ) ) {
			return $this->generations[ (int) $matches[1] ] ?? null;
		}
		if ( str_contains( $query, "state IN ('building','ready','active','failed','cancelled')" ) ) {
			$candidates = array_filter( $this->generations, static fn( array $generation ): bool => in_array( (string) ( $generation['state'] ?? '' ), array( 'building', 'ready', 'active', 'failed', 'cancelled' ), true ) );
			krsort( $candidates );
			return reset( $candidates ) ?: null;
		}
		if ( str_contains( $query, "WHERE state='active'" ) ) {
			foreach ( array_reverse( $this->generations, true ) as $generation ) {
				if ( 'active' === (string) ( $generation['state'] ?? '' ) ) {
					return $generation;
				}
			}
		}
		return null;
	}

	public function get_var( string $query ): int|string {
		if ( preg_match( '/SELECT state FROM .*WHERE id=(\d+)/', $query, $matches ) ) {
			return (string) ( $this->generations[ (int) $matches[1] ]['state'] ?? '' );
		}
		if ( str_contains( $query, 'COUNT(*)' ) && str_contains( $query, 'wdc_ozon_delivery_pickup_generations' ) ) {
			return isset( $this->failures['postcondition_count'] ) ? (int) $this->failures['postcondition_count'] : count( array_filter( $this->generations, static fn( array $generation ): bool => 'active' === $generation['state'] ) );
		}
		if ( isset( $this->failures['postcondition_active_id'] ) ) {
			return (int) $this->failures['postcondition_active_id'];
		}
		foreach ( $this->generations as $id => $generation ) {
			if ( 'active' === $generation['state'] ) {
				return $id;
			}
		}
		return 0;
	}

	public function query( string $query ): int|false {
		if ( 'START TRANSACTION' === $query ) {
			if ( ! empty( $this->failures['start'] ) ) {
				return false;
			}
			$this->transaction_backup = $this->generations;
			return 1;
		}
		if ( 'ROLLBACK' === $query ) {
			if ( null !== $this->transaction_backup ) {
				$this->generations = $this->transaction_backup;
			}
			$this->transaction_backup = null;
			return 1;
		}
		if ( 'COMMIT' === $query ) {
			if ( ! empty( $this->failures['commit'] ) ) {
				return false;
			}
			$this->transaction_backup = null;
			return 1;
		}
		if ( str_contains( $query, "SET state='obsolete'" ) ) {
			if ( ! empty( $this->failures['obsolete'] ) ) {
				return false;
			}
			$affected = 0;
			foreach ( $this->generations as &$generation ) {
				if ( 'active' === $generation['state'] ) {
					$generation['state'] = 'obsolete';
					++$affected;
				}
			}
			unset( $generation );
			return $affected;
		}
		if ( str_contains( $query, "SET state='active'" ) ) {
			if ( ! empty( $this->failures['target'] ) ) {
				return false;
			}
			if ( ! empty( $this->failures['target_zero'] ) ) {
				return 0;
			}
			preg_match( '/WHERE id=(\d+) AND state=\'ready\'/', $query, $matches );
			$id = isset( $matches[1] ) ? (int) $matches[1] : 0;
			if ( ! isset( $this->generations[ $id ] ) || 'ready' !== $this->generations[ $id ]['state'] ) {
				return 0;
			}
			$this->generations[ $id ]['state'] = 'active';
			return 1;
		}
		if ( str_contains( $query, 'wdc_ozon_delivery_pickup_ids' ) && preg_match( '/DELETE FROM .*generation_id=(\d+)/', $query, $matches ) ) {
			$this->deleted_generation_ids[] = (int) $matches[1];
			return 1;
		}
		if ( preg_match( '/DELETE FROM .*generation_id <> (\d+)/', $query, $matches ) ) {
			if ( ! empty( $this->failures['cleanup'] ) ) {
				return false;
			}
			$active_id = (int) $matches[1];
			foreach ( array_keys( $this->points_by_generation ) as $generation_id ) {
				if ( $active_id !== $generation_id ) {
					unset( $this->points_by_generation[ $generation_id ] );
				}
			}
			return 1;
		}
		if ( preg_match( '/DELETE FROM .*generation_id=(\d+)/', $query, $matches ) ) {
			$this->deleted_generation_ids[] = (int) $matches[1];
			unset( $this->points_by_generation[ (int) $matches[1] ] );
			return 1;
		}
		return 1;
	}

	/** @param array<string,mixed> $data */
	public function insert( string $table, array $data ): int|false {
		return ! empty( $this->failures['insert'] ) ? false : 1;
	}

	/** @param array<string,mixed> $data @param array<string,mixed> $where */
	public function update( string $table, array $data, array $where ): int {
		$id = (int) $where['id'];
		if ( ! isset( $this->generations[ $id ] ) ) {
			return 0;
		}
		if ( isset( $where['state'] ) && (string) $this->generations[ $id ]['state'] !== (string) $where['state'] ) {
			return 0;
		}
		$this->generations[ $id ] = array_merge( $this->generations[ $id ], $data );
		return 1;
	}
}

/** @param array<string,mixed> $failures @return array{0:OzonDeliveryPickupRepository,1:OzonPickupActivationWpdb} */
function oz_pickup_activation_repository( array $failures = array(), string $target_state = 'ready' ): array {
	$rows = array(
		1 => array( 'id' => 1, 'state' => 'active', 'accepted_count' => 10, 'conflict_count' => 0 ),
		2 => array( 'id' => 2, 'state' => $target_state, 'accepted_count' => 10, 'conflict_count' => 0 ),
	);
	$wpdb = new OzonPickupActivationWpdb( $rows, $failures );
	return array( new OzonDeliveryPickupRepository( $wpdb ), $wpdb );
}

$parser = new OzonDeliveryPickupParser( new OzonDeliveryPickupScheduleFormatter() );
$page = $parser->list_page( array( 'delivery_points' => array( array( 'delivery_point_id' => 10 ) ), 'next_cursor' => 'cursor-2' ) );
oz_pickup_assert( $page['ids'] === array( 10 ) && $page['next_cursor'] === 'cursor-2', 'list pagination contract must be parsed exactly.' );
try {
	$parser->list_page( array( 'delivery_points' => array(), 'next_cursor' => '' ) );
	throw new RuntimeException( 'invalid cursor accepted' );
} catch ( RuntimeException $exception ) {
	oz_pickup_assert( 'pickup_cursor_invalid' === $exception->getMessage(), 'empty non-null cursor must fail closed.' );
}

$point = array( 'delivery_point_id' => 10, 'name' => 'ПВЗ', 'delivery_point_number' => 'X', 'type' => 'pvz', 'full_address' => 'ул. Тестовая, 1', 'coordinates' => array( 'latitude' => 55.0, 'longitude' => 82.0 ), 'schedule' => array(), 'is_active' => true, 'is_bulky' => false, 'restrictions' => array( 'max_weight_g' => 1000 ) );
$rows = $parser->info_page( array( 'delivery_points' => array( $point ) ) );
oz_pickup_assert( 1 === count( $rows ) && 10 === $rows[0]['point_id'] && '' === $rows[0]['schedule'] && ! isset( $rows[0]['schedule_json'] ) && '' !== $rows[0]['fingerprint'], 'allowlisted pickup point must normalize presentation schedule deterministically.' );
$point['coordinates']['latitude'] = 100;
oz_pickup_assert( array() === $parser->info_page( array( 'delivery_points' => array( $point ) ) ), 'invalid coordinates must be rejected.' );

$pickup_api = ( new ReflectionClass( OzonDeliveryApiClient::class ) )->newInstanceWithoutConstructor();
$pickup_body = new ReflectionMethod( OzonDeliveryApiClient::class, 'pickup_list_body' );
oz_pickup_assert( array( 'pagination' => array( 'limit' => 100 ) ) === $pickup_body->invoke( $pickup_api, null ) && array( 'pagination' => array( 'limit' => 100 ) ) === $pickup_body->invoke( $pickup_api, '' ) && array( 'pagination' => array( 'limit' => 100 ) ) === $pickup_body->invoke( $pickup_api, '   ' ), 'first pickup-list page must omit null, empty and whitespace-only cursors.' );
oz_pickup_assert( array( 'pagination' => array( 'limit' => 100, 'cursor' => 'cursor-2' ) ) === $pickup_body->invoke( $pickup_api, ' cursor-2 ' ), 'next pickup-list page must use only a non-empty normalized cursor with limit 100.' );

$generation_db = new OzonPickupActivationWpdb( array(), array( 'insert' => true ) );
oz_pickup_assert( null === ( new OzonDeliveryPickupRepository( $generation_db ) )->start( 'job' ), 'a failed generation insert must not report a usable generation ID.' );
$generation_db = new OzonPickupActivationWpdb( array(), array( 'insert_id' => 0 ) );
oz_pickup_assert( null === ( new OzonDeliveryPickupRepository( $generation_db ) )->start( 'job' ), 'a zero generation ID must not report a successful start.' );
$generation_db = new OzonPickupActivationWpdb( array(), array( 'insert_id' => 3 ) );
oz_pickup_assert( 3 === ( new OzonDeliveryPickupRepository( $generation_db ) )->start( 'job' ), 'only a positive generation ID reports a successful start.' );

foreach ( array( 'building', 'failed', 'obsolete', 'active', 'cancelled' ) as $invalid_state ) {
	[ $activation_repository, $activation_db ] = oz_pickup_activation_repository( array(), $invalid_state );
	oz_pickup_assert( ! $activation_repository->activate( 2 ) && 'active' === $activation_db->generations[1]['state'] && $invalid_state === $activation_db->generations[2]['state'], "only a ready generation may activate ({$invalid_state})." );
}
[ $activation_repository, $activation_db ] = oz_pickup_activation_repository();
$activation_db->generations[2]['accepted_count'] = 0;
oz_pickup_assert( ! $activation_repository->activate( 2 ) && 'active' === $activation_db->generations[1]['state'], 'an empty ready generation must not activate.' );
[ $activation_repository, $activation_db ] = oz_pickup_activation_repository();
$activation_db->generations[2]['conflict_count'] = 1;
oz_pickup_assert( ! $activation_repository->activate( 2 ) && 'active' === $activation_db->generations[1]['state'], 'a ready generation with conflicts must not activate.' );
[ $activation_repository, $activation_db ] = oz_pickup_activation_repository( array( 'start' => true ) );
oz_pickup_assert( ! $activation_repository->activate( 2 ) && 'active' === $activation_db->generations[1]['state'] && 'ready' === $activation_db->generations[2]['state'], 'a transaction-start failure must not mutate generations.' );
[ $activation_repository, $activation_db ] = oz_pickup_activation_repository( array( 'obsolete' => true ) );
oz_pickup_assert( ! $activation_repository->activate( 2 ) && 'active' === $activation_db->generations[1]['state'] && 'ready' === $activation_db->generations[2]['state'], 'obsolete update failure must preserve the previous active generation.' );
[ $activation_repository, $activation_db ] = oz_pickup_activation_repository( array( 'target' => true ) );
oz_pickup_assert( ! $activation_repository->activate( 2 ) && 'active' === $activation_db->generations[1]['state'] && 'ready' === $activation_db->generations[2]['state'], 'target update failure must roll back the previous active generation.' );
[ $activation_repository, $activation_db ] = oz_pickup_activation_repository( array( 'target_zero' => true ) );
oz_pickup_assert( ! $activation_repository->activate( 2 ) && 'active' === $activation_db->generations[1]['state'] && 'ready' === $activation_db->generations[2]['state'], 'a zero-row target transition must roll back.' );
[ $activation_repository, $activation_db ] = oz_pickup_activation_repository( array( 'postcondition_count' => 0 ) );
oz_pickup_assert( ! $activation_repository->activate( 2 ) && 'active' === $activation_db->generations[1]['state'] && 'ready' === $activation_db->generations[2]['state'], 'zero active postcondition must roll back.' );
[ $activation_repository, $activation_db ] = oz_pickup_activation_repository( array( 'postcondition_count' => 2 ) );
oz_pickup_assert( ! $activation_repository->activate( 2 ) && 'active' === $activation_db->generations[1]['state'] && 'ready' === $activation_db->generations[2]['state'], 'multiple active generations in postcondition must roll back.' );
[ $activation_repository, $activation_db ] = oz_pickup_activation_repository( array( 'postcondition_active_id' => 1 ) );
oz_pickup_assert( ! $activation_repository->activate( 2 ) && 'active' === $activation_db->generations[1]['state'] && 'ready' === $activation_db->generations[2]['state'], 'a mismatched active generation ID must roll back.' );
[ $activation_repository, $activation_db ] = oz_pickup_activation_repository( array( 'commit' => true ) );
oz_pickup_assert( ! $activation_repository->activate( 2 ) && 'active' === $activation_db->generations[1]['state'] && 'ready' === $activation_db->generations[2]['state'], 'commit failure must be treated as an activation failure.' );
[ $activation_repository, $activation_db ] = oz_pickup_activation_repository();
oz_pickup_assert( $activation_repository->activate( 2 ) && 'obsolete' === $activation_db->generations[1]['state'] && 'active' === $activation_db->generations[2]['state'] && 1 === count( array_filter( $activation_db->generations, static fn( array $generation ): bool => 'active' === $generation['state'] ) ), 'successful activation must leave exactly one target active generation.' );
[ $activation_repository, $activation_db ] = oz_pickup_activation_repository();
$activation_db->points_by_generation = array( 1 => 45, 2 => 9 );
oz_pickup_assert( $activation_repository->activate( 2 ) && ! isset( $activation_db->points_by_generation[1] ) && 9 === $activation_db->points_by_generation[2], 'successful activation must remove obsolete generation point rows only after publishing the target.' );
[ $activation_repository, $activation_db ] = oz_pickup_activation_repository( array( 'cleanup' => true ) );
$activation_db->points_by_generation = array( 1 => 45, 2 => 9 );
oz_pickup_assert( $activation_repository->activate( 2 ) && 'active' === $activation_db->generations[2]['state'] && isset( $activation_db->points_by_generation[1] ), 'cleanup failure must not reverse a successful activation.' );
$activation_db->failures['cleanup'] = false;
oz_pickup_assert( $activation_repository->cleanup_obsolete_points( 2 ) && ! isset( $activation_db->points_by_generation[1] ), 'a later cleanup retry must remove retained obsolete rows.' );
[ $activation_repository, $activation_db ] = oz_pickup_activation_repository( array( 'target' => true ) );
oz_pickup_assert( ! $activation_repository->activate( 2 ), 'activation failure fixture must reject the target generation.' );
$activation_repository->fail( 2, 'pickup_activation_rejected', 'safe failure' );
oz_pickup_assert( 'active' === $activation_db->generations[1]['state'] && 'failed' === $activation_db->generations[2]['state'] && array( 2 ) === array_values( array_unique( $activation_db->deleted_generation_ids ) ), 'activation rejection cleanup must affect only the new generation.' );

$diagnostic_db = new OzonPickupActivationWpdb(
	array(
		6 => array( 'id' => 6, 'state' => 'active', 'accepted_count' => 600, 'conflict_count' => 0 ),
		7 => array( 'id' => 7, 'state' => 'building', 'phase' => 'enrichment', 'accepted_count' => 600, 'rejected_count' => 0, 'conflict_count' => 0, 'page_count' => 6, 'discovery_page_count' => 6, 'downloaded_count' => 600, 'discovered_count' => 600, 'enrichment_processed_count' => 600, 'cursor_value' => 'cursor-safe' ),
	)
);
$diagnostic_repository = new OzonDeliveryPickupRepository( $diagnostic_db );
$diagnostic_repository->fail( 7, 'transport_error', 'Не удалось обновить справочник ПВЗ Ozon Delivery.', array( 'operation' => 'v1/delivery-point/info', 'http_status' => 0, 'retryable' => true, 'failed_page' => 7, 'failed_cursor' => 'cursor-safe', 'failed_after_ids' => 100, 'failed_attempt' => 4 ) );
$status = $diagnostic_repository->status();
oz_pickup_assert( 'failed' === $status['state'] && 'transport_error' === $status['safe_error_code'] && 'v1/delivery-point/info' === $status['safe_error_operation'] && 0 === $status['safe_error_http_status'] && true === $status['safe_error_retryable'] && 7 === $status['failed_page'] && 4 === $status['failed_attempt'] && 'cursor-safe' === $status['failed_cursor'], 'final exhausted import diagnostics must expose endpoint-aware safe failure data.' );

$cancel_db = new OzonPickupActivationWpdb( array( 8 => array( 'id' => 8, 'state' => 'cancelled', 'accepted_count' => 10, 'conflict_count' => 0 ) ) );
$cancel_repository = new OzonDeliveryPickupRepository( $cancel_db );
$cancel_repository->fail( 8, 'transport_error', 'late failure after cancellation' );
$cancel_repository->record_retry( 8, 1, array( 'code' => 'transport_error' ) );
oz_pickup_assert( 'cancelled' === $cancel_db->generations[8]['state'], 'late retry/fail attempts must not overwrite a manually cancelled generation.' );

$root = dirname( __DIR__, 2 );
$api = file_get_contents( $root . '/src/Carriers/OzonDelivery/Api/OzonDeliveryApiClient.php' ) ?: '';
$importer = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupImportService.php' ) ?: '';
$repository = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupRepository.php' ) ?: '';
$parser_source = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupParser.php' ) ?: '';
$initial_schema = file_get_contents( $root . '/database/migrations/0001_initial_schema.php' ) ?: '';

oz_pickup_assert( str_contains( $api, "'/v1/delivery-point/list'" ) && str_contains( $api, "'/v1/delivery-point/info'" ) && str_contains( $api, "'limit' => 100" ) && str_contains( $api, 'catch ( OzonDeliveryApiException $exception )' ) && str_contains( $api, "trim( \$path, '/' )" ) && str_contains( $api, '$exception->safe_code' ) && str_contains( $api, '$exception->retryable' ) && str_contains( $api, '$exception->metadata' ), 'official read-only pickup API contract and endpoint-aware HTTP exception wrapping are required.' );
oz_pickup_assert( str_contains( $importer, 'run_discovery_step' ) && str_contains( $importer, 'run_enrichment_step' ) && str_contains( $importer, 'pending_ids' ) && str_contains( $importer, 'mark_ready_if_complete' ) && ! str_contains( $importer, "array( 'state' => 'ready' )" ) && str_contains( $importer, 'pickup_enrichment_incomplete' ), 'importer must use the guarded ready transition before activation.' );
$discovery_source = substr( $importer, strpos( $importer, 'private function run_discovery_step' ), strpos( $importer, 'private function run_enrichment_step' ) - strpos( $importer, 'private function run_discovery_step' ) );
oz_pickup_assert( str_contains( $discovery_source, 'pickup_list' ) && str_contains( $discovery_source, "'cursor_value'" ) && str_contains( $discovery_source, 'commit_discovery_page' ) && ! str_contains( $discovery_source, 'pickup_info' ), 'discovery phase must call only pickup_list and must not call pickup_info.' );
oz_pickup_assert( str_contains( $importer, 'resolve_info_batch' ) && str_contains( $importer, 'MAX_INFO_SALVAGE_REQUESTS = 199' ) && str_contains( $importer, "'not_found_404'" ) && str_contains( $importer, "'info_missing'" ) && str_contains( $importer, "'v1/delivery-point/info'" ) && str_contains( $importer, '404' ), 'enrichment must salvage only /info 404 through a bounded batch resolver and mark disappeared/missing IDs rejected.' );
oz_pickup_assert( str_contains( $importer, 'MAX_RETRIES = 3' ) && str_contains( $importer, 'RETRY_BACKOFF_SECONDS = array( 1 => 2, 2 => 5, 3 => 10 )' ) && str_contains( $importer, '$exception instanceof OzonDeliveryApiException && $exception->retryable' ) && str_contains( $importer, '$attempt <= self::MAX_RETRIES' ) && str_contains( $importer, "'retry' => true" ) && str_contains( $importer, "'retry_after'" ) && ! str_contains( $importer, 'sleep(' ), 'pickup importer must retry only typed retryable Ozon API failures with bounded deterministic Action Scheduler backoff.' );
oz_pickup_assert( str_contains( $repository, 'wdc_ozon_delivery_pickup_ids' ) && str_contains( $repository, 'INSERT IGNORE' ) && str_contains( $repository, 'status=%s' ) && str_contains( $repository, "'pending'" ) && str_contains( $repository, "'enriched'" ) && str_contains( $repository, "'rejected'" ) && str_contains( $repository, 'enrichment_processed_count' ), 'repository must persist frozen IDs relationally and terminalize enrichment rows idempotently.' );
oz_pickup_assert( str_contains( $repository, 'commit_discovery_page' ) && str_contains( $repository, 'commit_enrichment_batch' ) && str_contains( $repository, "'START TRANSACTION'" ) && str_contains( $repository, "'ROLLBACK'" ) && str_contains( $repository, "'COMMIT'" ) && str_contains( $repository, "'building'" ) && str_contains( $repository, "'enrichment'" ), 'discovery/enrichment commits must be transactional and must re-read generation state/phase before committing.' );
oz_pickup_assert( str_contains( $repository, 'cancel_building_generation' ) && str_contains( $repository, "'cancelled'" ) && str_contains( $repository, 'cleanup_generation_rows' ) && str_contains( $repository, 'generation_can_fail' ) && str_contains( $repository, 'generation_is_building' ), 'manual cancellation must become terminal, clean partial rows, and protect against late retry/fail writes.' );
oz_pickup_assert( str_contains( $initial_schema, 'schedule text NOT NULL' ) && ! str_contains( $initial_schema, 'schedule_json' ) && str_contains( $initial_schema, 'progress_updated_at' ) && str_contains( $parser_source, "'schedule' =>" ) && ! str_contains( $parser_source, 'schedule_json' ), 'The initial schema and parser must use only final Ozon schedule storage.' );
oz_pickup_assert( str_contains( $initial_schema, 'active_geo_lookup' ) && str_contains( $initial_schema, 'retry_count' ) && str_contains( $initial_schema, 'safe_error_operation' ) && str_contains( $initial_schema, 'failed_cursor' ), 'The initial schema must include final Ozon geo and resilient failure diagnostics.' );
oz_pickup_assert( str_contains( $initial_schema, 'wdc_ozon_delivery_pickup_ids' ) && str_contains( $initial_schema, 'generation_point' ) && str_contains( $initial_schema, 'generation_status_id' ) && str_contains( $initial_schema, 'discovered_count' ) && str_contains( $initial_schema, 'enrichment_processed_count' ), 'The initial schema must include final two-phase Ozon storage.' );
oz_pickup_assert( ! str_contains( $repository, 'create_schema' ) && ! str_contains( $repository, 'dbDelta' ) && ! str_contains( $repository, 'CREATE TABLE' ) && ! str_contains( $repository, 'ALTER TABLE' ), 'Ozon repository must not contain runtime DDL.' );

$GLOBALS['wdc_options'] = array( 'wdc_db_version' => '1.0.0' );
$migration_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-ozon-pickup-migration-' . uniqid();
mkdir( $migration_dir );
file_put_contents( $migration_dir . DIRECTORY_SEPARATOR . '0002_future_schema.php', "<?php\nreturn static function (): void { if ( '1' === get_option( 'wdc_future_fail_once', '1' ) ) { update_option( 'wdc_future_fail_once', '0', false ); throw new RuntimeException( 'fail once' ); } };\n" );
try {
	( new MigrationManager( '1.0.1-test', $migration_dir ) )->run();
	throw new RuntimeException( 'failed migration was marked applied' );
} catch ( RuntimeException $exception ) {
	oz_pickup_assert( 'fail once' === $exception->getMessage() && ! in_array( '0002_future_schema.php', (array) get_option( 'wdc_applied_migrations', array() ), true ) && '1.0.0' === get_option( 'wdc_db_version', '' ), 'MigrationManager must not mark a failed future migration applied or advance db version.' );
}
( new MigrationManager( '1.0.1-test', $migration_dir ) )->run();
oz_pickup_assert( in_array( '0002_future_schema.php', (array) get_option( 'wdc_applied_migrations', array() ), true ) && '1.0.1-test' === get_option( 'wdc_db_version', '' ), 'MigrationManager must rerun failed future migration and mark it applied only after success.' );
@unlink( $migration_dir . DIRECTORY_SEPARATOR . '0002_future_schema.php' );
@rmdir( $migration_dir );

echo "Ozon Delivery pickup import smoke passed.\n";

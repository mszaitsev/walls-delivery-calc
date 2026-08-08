<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string {
		unset( $type );
		return '2026-08-08 10:00:00';
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ?? '' );
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		unset( $hook, $args );
	}
}
if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( mixed $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return serialize( $value );
		}

		return (string) $value;
	}
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( mixed $value ): mixed {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$result = @unserialize( $value );

		return false !== $result || 'b:0;' === $value ? $result : $value;
	}
}
if ( ! isset( $GLOBALS['attempt_smoke_options_db'] ) ) {
	$GLOBALS['attempt_smoke_options_db'] = array();
}
if ( ! isset( $GLOBALS['attempt_smoke_options_cache'] ) ) {
	$GLOBALS['attempt_smoke_options_cache'] = array( 'options' => array(), 'notoptions' => array() );
}
function attempt_smoke_reset_wp_option_backend(): void {
	$GLOBALS['attempt_smoke_options_db'] = array();
	$GLOBALS['attempt_smoke_options_cache'] = array( 'options' => array(), 'notoptions' => array() );
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( string $key, string $group = '' ): mixed {
		$cache = $GLOBALS['attempt_smoke_options_cache'][ $group ] ?? array();

		return array_key_exists( $key, $cache ) ? $cache[ $key ] : false;
	}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( string $key, mixed $value, string $group = '' ): bool {
		if ( ! isset( $GLOBALS['attempt_smoke_options_cache'][ $group ] ) || ! is_array( $GLOBALS['attempt_smoke_options_cache'][ $group ] ) ) {
			$GLOBALS['attempt_smoke_options_cache'][ $group ] = array();
		}
		$GLOBALS['attempt_smoke_options_cache'][ $group ][ $key ] = $value;

		return true;
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( string $key, string $group = '' ): bool {
		unset( $GLOBALS['attempt_smoke_options_cache'][ $group ][ $key ] );

		return true;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			return $default;
		}
		$cached = wp_cache_get( $option, 'options' );
		if ( false !== $cached ) {
			return maybe_unserialize( $cached );
		}
		if ( array_key_exists( $option, $GLOBALS['attempt_smoke_options_db'] ) ) {
			$serialized = $GLOBALS['attempt_smoke_options_db'][ $option ];
			wp_cache_set( $option, $serialized, 'options' );

			return maybe_unserialize( $serialized );
		}
		if ( ! is_array( $notoptions ) ) {
			$notoptions = array();
		}
		$notoptions[ $option ] = true;
		wp_cache_set( 'notoptions', $notoptions, 'options' );

		return $default;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, mixed $value, string $deprecated = '', string $autoload = 'yes' ): bool {
		unset( $deprecated, $autoload );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( ! is_array( $notoptions ) || ! isset( $notoptions[ $option ] ) ) {
			if ( false !== get_option( $option, false ) ) {
				return false;
			}
		}
		if ( array_key_exists( $option, $GLOBALS['attempt_smoke_options_db'] ) ) {
			return false;
		}
		$serialized = maybe_serialize( $value );
		$GLOBALS['attempt_smoke_options_db'][ $option ] = $serialized;
		wp_cache_set( $option, $serialized, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		if ( ! array_key_exists( $option, $GLOBALS['attempt_smoke_options_db'] ) ) {
			return false;
		}
		unset( $GLOBALS['attempt_smoke_options_db'][ $option ] );
		wp_cache_delete( $option, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( ! is_array( $notoptions ) ) {
			$notoptions = array();
		}
		$notoptions[ $option ] = true;
		wp_cache_set( 'notoptions', $notoptions, 'options' );

		return true;
	}
}
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $options = 'wp_options';
		/** @var array<int,mixed> */
		private array $prepared_args = array();

		public function prepare( string $query, mixed ...$args ): string {
			$this->prepared_args = $args;

			return $query;
		}

		public function query( string $query ): int {
			if ( ! str_starts_with( $query, 'DELETE FROM ' ) || count( $this->prepared_args ) < 2 ) {
				return 0;
			}
			$key = (string) $this->prepared_args[0];
			$value = (string) $this->prepared_args[1];
			if ( array_key_exists( $key, $GLOBALS['attempt_smoke_options_db'] ) && $GLOBALS['attempt_smoke_options_db'][ $key ] === $value ) {
				unset( $GLOBALS['attempt_smoke_options_db'][ $key ] );

				return 1;
			}

			return 0;
		}
	}
}
$GLOBALS['wpdb'] = new wpdb();

use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationAttemptService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentPersistenceMapperInterface;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function attempt_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class AttemptSmokeOrder {
	/** @var array<string,mixed> */
	private array $meta = array();
	public function __construct( private int $id ) {}
	public function get_id(): int { return $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed { unset( $single ); return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void {}
	/** @return array<string,mixed> */
	public function meta_snapshot(): array { return $this->meta; }
}

final class AttemptSmokeAdapter implements CarrierShipmentAdapterInterface {
	public ?ShipmentCreateRequest $preview_request = null;
	public ?ShipmentCreateRequest $create_request = null;
	public int $create_calls = 0;
	public ShipmentCreateResult $next_result;
	public function __construct( private string $carrier_key = 'attempt_carrier' ) {
		$this->next_result = new ShipmentCreateResult( true, external_id: 'EXT', tracking_number: 'TRACK' );
	}
	public function carrier_key(): string { return $this->carrier_key; }
	public function supports( ShipmentCreateRequest $request ): bool { return $request->carrier_key === $this->carrier_key; }
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array {
		$this->preview_request = $request;
		return array(
			'method' => 'POST',
			'path' => '/fake/',
			'body' => array(
				'creation_attempt_present' => isset( $request->meta['creation_attempt_id'] ),
				'creation_attempt_id' => (string) ( $request->meta['creation_attempt_id'] ?? '' ),
				'creation_attempt_generation' => (int) ( $request->meta['creation_attempt_generation'] ?? 0 ),
			),
			'errors' => array(),
		);
	}
	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		$this->create_calls++;
		$this->create_request = $request;
		return $this->next_result;
	}
	public function presentation(): array { return array(); }
	public function status_payload( object $order, array $shipment ): array { unset( $order ); return $shipment; }
	public function update_status( object $order, string $shipment_key = '' ): array { unset( $order, $shipment_key ); return array(); }
	public function attach_manual( object $order, array $payload ): array { unset( $order, $payload ); return array( 'success' => false ); }
	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array { unset( $order, $shipment_key ); return array( 'success' => false ); }
	public function remove_from_order( object $order, string $shipment_key = '' ): array { unset( $order, $shipment_key ); return array( 'success' => false ); }
	public function supports_status_auto_sync(): bool { return false; }
	public function tracking_identifier( array $shipment ): string { return (string) ( $shipment['tracking_number'] ?? '' ); }
	public function auto_sync_throttle_microseconds(): int { return 0; }
}

final class AttemptSmokeMapper implements CarrierShipmentPersistenceMapperInterface {
	public function carrier_key(): string { return 'attempt_carrier'; }
	public function build_created_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): array {
		unset( $request, $result, $preview, $now );
		return array( 'status' => 'created', 'status_title' => 'Created' );
	}
	public function build_failed_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): ?array {
		unset( $request, $preview, $now );
		if ( 'uncertain' !== $result->error_code ) {
			return null;
		}
		return array(
			'status' => 'pending_creation_in_carrier',
			'pending_creation_in_carrier' => true,
			'status_title' => 'Pending',
		);
	}
	public function after_persist( object $order, array $shipment ): void { unset( $order, $shipment ); }
}

function attempt_smoke_request( int $order_id, string $rate_id = 'service-a', array $meta = array(), string $carrier_key = 'attempt_carrier' ): ShipmentCreateRequest {
	return new ShipmentCreateRequest(
		$order_id,
		$carrier_key,
		DeliveryType::COURIER,
		$rate_id,
		new Address( 'RU', 'Россия', 'Москва', '', 'Москва', '', '101000', 'Тестовая', '1', '', 'Россия, Москва, Тестовая, дом 1' ),
		null,
		array( new ShipmentPlace( 1, 1000, 10, 10, 10, Money::from_kopecks( 10000 ) ) ),
		Money::from_kopecks( 10000 ),
		true,
		array(),
		array(),
		array_merge( array( 'service_key' => $rate_id ), $meta )
	);
}

function attempt_smoke_valid_uuid( string $value ): bool {
	return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value );
}

attempt_smoke_reset_wp_option_backend();

$repository = new OrderShipmentRepository();
$uuids = array(
	'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
	'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
	'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
	'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
	'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
	'12121212-1212-4212-8212-121212121212',
	'34343434-3434-4434-8434-343434343434',
	'56565656-5656-4565-8565-565656565656',
	'78787878-7878-4787-8787-787878787878',
	'90909090-9090-4909-8909-909090909090',
	'abababab-abab-4aba-8bab-abababababab',
	'cdcdcdcd-cdcd-4cdc-8dcd-cdcdcdcdcdcd',
	'efefefef-efef-4efe-8fef-efefefefefef',
	'11111111-2222-4333-8444-555555555555',
	'22222222-3333-4444-8555-666666666666',
	'33333333-4444-4555-8666-777777777777',
	'44444444-5555-4666-8777-888888888888',
	'55555555-6666-4777-8888-999999999999',
	'66666666-7777-4888-8999-aaaaaaaaaaaa',
);
$attempt_now = 1000;
$attempts = new ShipmentCreationAttemptService(
	$repository,
	static function () use ( &$uuids ): string {
		$id = array_shift( $uuids );
		return is_string( $id ) ? $id : 'ffffffff-ffff-4fff-8fff-ffffffffffff';
	},
	static function () use ( &$attempt_now ): int {
		return $attempt_now;
	}
);
$adapter = new AttemptSmokeAdapter();
$service = new ShipmentCreationService( $repository, array( $adapter ), new ShipmentActualCostService( $repository ), null, null, array( new AttemptSmokeMapper() ), $attempts );

$same_request_lock_order = new AttemptSmokeOrder( 490 );
$same_request_lock = $attempts->acquire_create_lock( $same_request_lock_order, attempt_smoke_request( 490 ) );
attempt_smoke_assert( is_callable( $same_request_lock ), 'Cache-aware backend must acquire first lock owner.' );
$same_request_lock();
$same_request_reacquire = $attempts->acquire_create_lock( $same_request_lock_order, attempt_smoke_request( 490 ) );
attempt_smoke_assert( is_callable( $same_request_reacquire ), 'Same PHP request must reacquire a lock after owned SQL CAS release invalidates option cache.' );
$same_request_reacquire();
$persistent_cache_reacquire = $attempts->acquire_create_lock( $same_request_lock_order, attempt_smoke_request( 490 ) );
attempt_smoke_assert( is_callable( $persistent_cache_reacquire ), 'Persistent object cache across logical requests must not keep a released lock busy.' );
$persistent_cache_reacquire();

$create_lock_key_method = new ReflectionMethod( ShipmentCreationAttemptService::class, 'create_lock_key' );
$create_lock_key_method->setAccessible( true );
$failed_cas_order = new AttemptSmokeOrder( 491 );
$failed_cas_request = attempt_smoke_request( 491 );
$failed_cas_release = $attempts->acquire_create_lock( $failed_cas_order, $failed_cas_request );
attempt_smoke_assert( is_callable( $failed_cas_release ), 'Failed-CAS regression must start with owner A.' );
$failed_cas_key = (string) $create_lock_key_method->invoke( $attempts, 491, 'attempt_carrier' );
$successor_value = array( 'token' => '99999999-9999-4999-8999-999999999999', 'expires' => $attempt_now + 300 );
$GLOBALS['attempt_smoke_options_db'][ $failed_cas_key ] = maybe_serialize( $successor_value );
$failed_cas_release();
attempt_smoke_assert( ( $GLOBALS['attempt_smoke_options_db'][ $failed_cas_key ] ?? '' ) === maybe_serialize( $successor_value ), 'Failed SQL CAS must not delete successor lock from DB.' );
wp_cache_delete( $failed_cas_key, 'options' );
$failed_cas_current = get_option( $failed_cas_key, array() );
attempt_smoke_assert( $failed_cas_current === $successor_value, 'Failed SQL CAS must leave successor lock authoritative after cache refresh.' );
attempt_smoke_reset_wp_option_backend();

$order = new AttemptSmokeOrder( 501 );
$request = attempt_smoke_request( 501 );
attempt_smoke_assert( ! isset( $order->meta_snapshot()[ ShipmentCreationAttemptService::META_KEY ] ), 'Render/status without explicit preview must not allocate attempt.' );
$preview_a = $service->safe_preview( $request, $order );
$attempt_a = (string) ( $preview_a['body']['creation_attempt_id'] ?? '' );
attempt_smoke_assert( attempt_smoke_valid_uuid( $attempt_a ) && 1 === (int) ( $preview_a['body']['creation_attempt_generation'] ?? 0 ), 'First explicit preview must reserve attempt A generation 1.' );
$preview_a2 = $service->safe_preview( $request, $order );
attempt_smoke_assert( $attempt_a === (string) ( $preview_a2['body']['creation_attempt_id'] ?? '' ), 'Repeated preview must reuse attempt A.' );

$browser_request = attempt_smoke_request( 501, 'service-a', array( 'creation_attempt_id' => '99999999-9999-4999-8999-999999999999' ) );
$browser_preview = $service->safe_preview( $browser_request, $order );
attempt_smoke_assert( $attempt_a === (string) ( $browser_preview['body']['creation_attempt_id'] ?? '' ), 'Browser-supplied creation_attempt_id must be ignored.' );

$different_order = new AttemptSmokeOrder( 502 );
$different_preview = $service->safe_preview( attempt_smoke_request( 502 ), $different_order );
attempt_smoke_assert( $attempt_a !== (string) ( $different_preview['body']['creation_attempt_id'] ?? '' ), 'Different order must receive a different attempt.' );

$different_service_preview = $service->safe_preview( attempt_smoke_request( 501, 'service-b' ), $order );
attempt_smoke_assert( $attempt_a !== (string) ( $different_service_preview['body']['creation_attempt_id'] ?? '' ), 'Different carrier/service scope must receive a different attempt namespace.' );
$scope_records = $order->meta_snapshot()[ ShipmentCreationAttemptService::META_KEY ] ?? array();
attempt_smoke_assert( is_array( $scope_records ) && isset( $scope_records['attempt_carrier|service-a'], $scope_records['attempt_carrier|service-b'] ), 'Attempt meta updates must preserve unrelated carrier/service scopes.' );

$validation_order = new AttemptSmokeOrder( 503 );
$validation_request = attempt_smoke_request( 503 );
$validation_preview = $service->safe_preview( $validation_request, $validation_order );
$validation_attempt = (string) ( $validation_preview['body']['creation_attempt_id'] ?? '' );
$adapter->next_result = new ShipmentCreateResult( false, error_code: 'validation_failed', error_message: 'Validation failed before outbound.' );
$validation_result = $service->create( $validation_order, $validation_request );
attempt_smoke_assert( ! $validation_result->success && array() === $repository->find_by_carrier( $validation_order, 'attempt_carrier' ), 'Pre-submit validation failure must not persist a shipment.' );
$validation_retry = $service->safe_preview( $validation_request, $validation_order );
attempt_smoke_assert( $validation_attempt === (string) ( $validation_retry['body']['creation_attempt_id'] ?? '' ), 'Retry after validation-only failure must reuse attempt A.' );

$adapter->next_result = new ShipmentCreateResult( true, external_id: 'EXT', tracking_number: 'TRACK' );
$created_result = $service->create( $order, $request );
$created = $repository->find_by_carrier( $order, 'attempt_carrier' );
attempt_smoke_assert( $created_result->success && $attempt_a === (string) ( $created['creation_attempt_id'] ?? '' ), 'Successful create must persist attempt A in canonical shipment record.' );
$create_calls_before_duplicate = $adapter->create_calls;
$duplicate = $service->create( $order, $request );
attempt_smoke_assert( ! $duplicate->success && 'shipment_already_created' === $duplicate->error_code && $create_calls_before_duplicate === $adapter->create_calls, 'Active shipment must block duplicate carrier mutation.' );
$active_record = $attempts->current_record_for_request( $order, $request );
attempt_smoke_assert( 'active' === (string) ( $active_record['state'] ?? '' ), 'Successful create must keep attempt active.' );
$failed_cancel_preview = $service->safe_preview( $request, $order );
attempt_smoke_assert( $attempt_a === (string) ( $failed_cancel_preview['body']['creation_attempt_id'] ?? '' ), 'Failed cancellation/no terminal transition must leave attempt A active.' );
$attempts->mark_terminal_for_shipment( $order, 'attempt_carrier', $created, 'local_removed' );
$repository->delete_for_carrier( $order, 'attempt_carrier' );
$after_terminal_preview = $service->safe_preview( $request, $order );
attempt_smoke_assert( $attempt_a !== (string) ( $after_terminal_preview['body']['creation_attempt_id'] ?? '' ), 'Local removal of external shipment must terminalize A and next create must get B.' );

$pending_order = new AttemptSmokeOrder( 504 );
$pending_request = attempt_smoke_request( 504 );
$adapter->next_result = new ShipmentCreateResult( false, error_code: 'uncertain', error_message: 'Uncertain' );
$pending_result = $service->create( $pending_order, $pending_request );
$pending = $repository->find_by_carrier( $pending_order, 'attempt_carrier' );
$pending_record = $attempts->current_record_for_request( $pending_order, $pending_request );
attempt_smoke_assert( ! $pending_result->success && ! empty( $pending['pending_creation_in_carrier'] ) && (string) ( $pending['creation_attempt_id'] ?? '' ) === (string) ( $pending_record['current_attempt_id'] ?? '' ) && 'pending' === (string) ( $pending_record['state'] ?? '' ), 'Uncertain pending must persist and mark attempt pending.' );
$attempts->mark_active_for_shipment( $pending_order, 'attempt_carrier', $pending );
$reconciled_record = $attempts->current_record_for_request( $pending_order, $pending_request );
attempt_smoke_assert( (string) ( $pending['creation_attempt_id'] ?? '' ) === (string) ( $reconciled_record['current_attempt_id'] ?? '' ) && 'active' === (string) ( $reconciled_record['state'] ?? '' ), 'Manual reconciliation must transition pending A to active A.' );
$pending_request_with_attempt = attempt_smoke_request( 504, 'service-a', array( 'creation_attempt_id' => (string) ( $pending['creation_attempt_id'] ?? '' ) ) );
$attempts->mark_pending( $pending_order, $pending_request_with_attempt );
$attempts->mark_terminal_for_shipment( $pending_order, 'attempt_carrier', $pending, 'pending_discarded' );
$repository->delete_for_carrier( $pending_order, 'attempt_carrier' );
$pending_after_remove = $service->safe_preview( $pending_request, $pending_order );
attempt_smoke_assert( (string) ( $pending['creation_attempt_id'] ?? '' ) !== (string) ( $pending_after_remove['body']['creation_attempt_id'] ?? '' ), 'Removing uncertain pending must prevent old attempt reuse.' );

$locked_order = new AttemptSmokeOrder( 507 );
$locked_request = attempt_smoke_request( 507 );
$release_lock = $attempts->acquire_create_lock( $locked_order, $locked_request );
attempt_smoke_assert( is_callable( $release_lock ), 'Test must acquire generic create lock.' );
$same_carrier_other_service = $attempts->acquire_create_lock( $locked_order, attempt_smoke_request( 507, 'service-b' ) );
attempt_smoke_assert( null === $same_carrier_other_service, 'Same order and carrier must share one create lock even with different service keys.' );
$different_carrier_lock = $attempts->acquire_create_lock( $locked_order, attempt_smoke_request( 507, 'service-a', array(), 'other_carrier' ) );
attempt_smoke_assert( is_callable( $different_carrier_lock ), 'Different carrier create locks must remain independent.' );
$different_carrier_lock();
$different_order_lock = $attempts->acquire_create_lock( new AttemptSmokeOrder( 508 ), $locked_request );
attempt_smoke_assert( is_callable( $different_order_lock ), 'Different order create locks must remain independent.' );
$different_order_lock();
$locked_calls_before = $adapter->create_calls;
$locked_result = $service->create( $locked_order, $locked_request );
attempt_smoke_assert( ! $locked_result->success && 'shipment_create_in_progress' === $locked_result->error_code && $locked_calls_before === $adapter->create_calls, 'Generic create lock must prevent duplicate carrier mutation before adapter call.' );
$release_lock();

$stale_order = new AttemptSmokeOrder( 509 );
$stale_request = attempt_smoke_request( 509 );
$attempt_now = 2000;
$release_a = $attempts->acquire_create_lock( $stale_order, $stale_request );
attempt_smoke_assert( is_callable( $release_a ), 'Owner A must acquire lock for stale-owner regression.' );
$attempt_now = 2401;
$release_b = $attempts->acquire_create_lock( $stale_order, $stale_request );
attempt_smoke_assert( is_callable( $release_b ), 'Owner B must take over expired lock.' );
$release_a();
$release_c_blocked = $attempts->acquire_create_lock( $stale_order, $stale_request );
attempt_smoke_assert( null === $release_c_blocked, 'Stale owner A must not delete successor owner B lock.' );
$release_b();
$release_c = $attempts->acquire_create_lock( $stale_order, $stale_request );
attempt_smoke_assert( is_callable( $release_c ), 'Owner C must acquire only after B releases its own lock.' );
$release_c();

$malformed_order = new AttemptSmokeOrder( 505 );
$malformed_order->update_meta_data( ShipmentCreationAttemptService::META_KEY, array( 'attempt_carrier|service-a' => array( 'current_attempt_id' => 'not-a-uuid', 'generation' => 1, 'state' => 'active', 'updated_at' => '2026-08-08 10:00:00' ) ) );
$malformed_preview = $service->safe_preview( attempt_smoke_request( 505 ), $malformed_order );
attempt_smoke_assert( array() !== $malformed_preview['errors'] && str_contains( (string) $malformed_preview['errors'][0], 'состояние попытки' ), 'Malformed persisted attempt state must fail closed.' );

$legacy_active_order = new AttemptSmokeOrder( 506 );
$repository->save_for_carrier( $legacy_active_order, 'attempt_carrier', array( 'carrier_key' => 'attempt_carrier', 'service_key' => 'service-a', 'status' => 'created', 'tracking_number' => 'LEGACY' ) );
$legacy_preview = $service->safe_preview( attempt_smoke_request( 506 ), $legacy_active_order );
attempt_smoke_assert( false === (bool) ( $legacy_preview['body']['creation_attempt_present'] ?? false ), 'Legacy active shipment without attempt must not allocate a new current attempt during preview.' );

echo "Shipment creation attempt smoke passed\n";

<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . '/' );
defined( 'WDC_SECRET_KEY' ) || define( 'WDC_SECRET_KEY', 'test-secret' );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'test-app-encryption-key' );
require_once $root . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', $root . '/src' ) )->register();

use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryAccessTokenService;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiResponse;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryMessageSanitizer;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryTokenCache;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliveryCredentials;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Carriers\OzonDelivery\Returns\OzonDeliveryReturnInfoParser;
use WallsShop\WDC\Carriers\OzonDelivery\Returns\OzonDeliveryReturnLifecycleResolver;
use WallsShop\WDC\Carriers\OzonDelivery\Returns\OzonDeliveryReturnSearchParser;
use WallsShop\WDC\Carriers\OzonDelivery\Returns\OzonDeliveryReturnService;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentExternalIdResolver;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentStatusMapping;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentStatusMapper;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( string $type = 'mysql', bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( mixed $value ): mixed { return $value; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['oz_return_options'][ $key ] ?? $default; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( string $key, mixed $value, bool $autoload = true ): bool { $GLOBALS['oz_return_options'][ $key ] = $value; return true; } }

function oz_return_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class OzonReturnSmokeHttp implements OzonDeliveryHttpClientInterface {
	/** @var array<int,array{method:string,url:string,body:array<string,mixed>}> */
	public array $calls = array();
	/** @var array<int,array<string,mixed>> */
	public array $return_pages = array();
	/** @var array<string,array<string,mixed>> */
	public array $return_info = array();
	public bool $fail_search = false;
	public bool $fail_info = false;
	public bool $partial_info = false;

	public function request( string $method, string $url, array $args = array() ): OzonDeliveryApiResponse {
		$body = json_decode( (string) ( $args['body'] ?? '{}' ), true );
		$body = is_array( $body ) ? $body : array();
		$this->calls[] = array( 'method' => $method, 'url' => $url, 'body' => $body );
		if ( str_contains( $url, '/oauth/token' ) ) {
			return new OzonDeliveryApiResponse( 200, '{"access_token":"token","expires_in":9999999999,"token_type":"bearer","scope":["delivery-api.all"]}', array( 'content-type' => 'application/json' ) );
		}
		if ( str_contains( $url, '/v1/return/search' ) ) {
			if ( $this->fail_search ) {
				return new OzonDeliveryApiResponse( 500, '{"error":{"code":"return_search_failed","message":"temporary"}}', array( 'content-type' => 'application/json' ) );
			}
			$cursor = (string) ( $body['pagination']['cursor'] ?? '' );
			$page_index = '' !== $cursor && ctype_digit( $cursor ) ? (int) $cursor : 0;
			$page = $this->return_pages[ $page_index ] ?? array( 'returns' => array(), 'next_cursor' => '' );
			return new OzonDeliveryApiResponse( 200, wp_json_encode( $page ) ?: '{}', array( 'content-type' => 'application/json' ) );
		}
		if ( str_contains( $url, '/v1/return/info' ) ) {
			if ( $this->fail_info ) {
				return new OzonDeliveryApiResponse( 500, '{"error":{"code":"return_info_failed","message":"temporary"}}', array( 'content-type' => 'application/json' ) );
			}
			$numbers = is_array( $body['return_numbers'] ?? null ) ? array_values( $body['return_numbers'] ) : array();
			if ( $this->partial_info ) {
				$numbers = array_slice( $numbers, 0, 1 );
			}
			$returns = array();
			foreach ( $numbers as $number ) {
				$returns[] = $this->return_info[ (string) $number ] ?? array( 'return_number' => (string) $number, 'return_external_id' => '1030', 'status' => 'MOVING' );
			}
			return new OzonDeliveryApiResponse( 200, wp_json_encode( array( 'returns' => $returns ) ) ?: '{}', array( 'content-type' => 'application/json' ) );
		}
		return new OzonDeliveryApiResponse( 404, '{"error":{"code":"not_found","message":"not found"}}', array( 'content-type' => 'application/json' ) );
	}

	public function calls_for( string $needle ): int {
		return count( array_filter( $this->calls, static fn( array $call ): bool => str_contains( $call['url'], $needle ) ) );
	}
}

final class OzonReturnSmokeOrder {
	public function __construct( private string $number ) {}
	public function get_order_number(): string { return $this->number; }
}

/** @return array{service:OzonDeliveryReturnService,http:OzonReturnSmokeHttp,mapper:OzonDeliveryShipmentStatusMapper} */
function oz_return_stack( array $mapping = array() ): array {
	$GLOBALS['oz_return_options'] = array();
	$encryption = new EncryptionService();
	$settings_repository = new SettingsRepository();
	$settings_repository->replace( array(
		OzonDeliverySettings::CLIENT_ID_KEY => 'client',
		OzonDeliverySettings::CLIENT_SECRET_ENCRYPTED_KEY => $encryption->encrypt( 'secret' ),
		OzonDeliverySettings::SHIPMENT_STATUS_MAPPING_KEY => $mapping,
	) );
	$settings = new OzonDeliverySettings( $settings_repository );
	$http = new OzonReturnSmokeHttp();
	$credentials = new OzonDeliveryCredentials( $settings_repository, $encryption );
	$tokens = new OzonDeliveryAccessTokenService( $credentials, $http, new OzonDeliveryMessageSanitizer(), new OzonDeliveryTokenCache( $encryption ) );
	$api = new OzonDeliveryApiClient( $http, $tokens );
	$mapper = new OzonDeliveryShipmentStatusMapper( $settings );
	return array(
		'service' => new OzonDeliveryReturnService( $api, new OzonDeliveryShipmentExternalIdResolver(), new OzonDeliveryReturnSearchParser(), new OzonDeliveryReturnInfoParser(), new OzonDeliveryReturnLifecycleResolver(), $mapper ),
		'http' => $http,
		'mapper' => $mapper,
	);
}

function oz_return_shipment( array $postings, array $returns = array(), array $search = array(), string $universal = DeliveryStatus::IN_TRANSIT ): array {
	return array(
		'ozon_order_external_id' => '1030',
		'ozon_postings' => $postings,
		'ozon_returns' => $returns,
		'ozon_return_search' => $search,
		'universal_status_code' => $universal,
	);
}

function oz_return_posting( int $place, string $number, string $raw, string $universal, ?bool $handover = true, bool $unknown = false ): array {
	$posting = array( 'place_number' => $place, 'posting_number' => $number, 'last_raw_status' => $raw, 'last_universal_status' => $universal );
	if ( null !== $handover ) {
		$posting['handover_seen'] = $handover;
	}
	if ( $unknown ) {
		$posting['handover_unknown'] = true;
		unset( $posting['handover_seen'] );
	}
	return $posting;
}

$ids = new OzonDeliveryShipmentExternalIdResolver();
oz_return_assert( '1030' === $ids->order_external_id( '1030' ) && '1030' === $ids->posting_external_id( '1030', 1, 1 ) && '1030-2' === $ids->expected_return_external_id( '1030', 2, 2 ) && ! str_contains( $ids->expected_return_external_id( '1030', 2, 2 ), '_' ), 'Ozon external IDs must keep order-number and hyphenated multi-place format.' );

$documented = OzonDeliveryShipmentStatusMapping::documented_statuses();
foreach ( array( 'MOVING', 'AT_PICKUP_POINT', 'AT_THE_PICK_UP_POINT', 'RECEIVED', 'UTILIZATION', 'UTILIZED', 'WRITTEN_OFF', 'LOOKING_FOR' ) as $status ) {
	oz_return_assert( in_array( $status, $documented, true ), 'Ozon documented return status missing from catalog: ' . $status );
}

$stack = oz_return_stack();
$stack['http']->return_pages = array( array( 'returns' => array( array( 'return_number' => 'R1', 'return_external_id' => '1030', 'status' => 'MOVING' ) ), 'next_cursor' => '' ) );
$stack['http']->return_info = array( 'R1' => array( 'return_number' => 'R1', 'return_external_id' => '1030', 'status' => 'MOVING' ) );
$result = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), oz_return_shipment( array( oz_return_posting( 1, 'P1', 'CANCELED', DeliveryStatus::IN_TRANSIT, true ) ) ), array( 'P1' => 'CANCELED' ) );
oz_return_assert( $result['success'] && DeliveryStatus::RETURNING_TO_SENDER === $result['universal_status'] && 'R1' === (string) ( $result['shipment']['ozon_returns'][0]['return_number'] ?? '' ), 'Single external cancel after handover must find return, call return/info, and become returning_to_sender.' );

$stack['http']->return_info['R1']['status'] = 'RECEIVED';
$next = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), $result['shipment'], array( 'P1' => 'UNKNOWN' ) );
oz_return_assert( $next['success'] && 1 === $stack['http']->calls_for( '/v1/return/search' ) && 2 === $stack['http']->calls_for( '/v1/return/info' ) && DeliveryStatus::RETURNED_TO_SENDER === $next['universal_status'], 'Stored return_number must continue via return/info even without current outbound CANCELED.' );

$stack = oz_return_stack( array( 'moving' => DeliveryStatus::IN_TRANSIT ) );
$stack['http']->return_pages = array( array( 'returns' => array( array( 'return_number' => 'R2', 'return_external_id' => '1030', 'status' => 'MOVING' ) ), 'next_cursor' => '' ) );
$stack['http']->return_info = array( 'R2' => array( 'return_number' => 'R2', 'return_external_id' => '1030', 'status' => 'MOVING' ) );
$override = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), oz_return_shipment( array( oz_return_posting( 1, 'P1', 'CANCELED', DeliveryStatus::IN_TRANSIT, true ) ) ), array( 'P1' => 'CANCELED' ) );
oz_return_assert( DeliveryStatus::IN_TRANSIT === $override['universal_status'], 'Saved MOVING admin override must affect return runtime mapping.' );

$stack = oz_return_stack();
foreach ( array( 'UNKNOWN' => DeliveryStatus::UNKNOWN, 'UTILIZED' => DeliveryStatus::RETURNED_TO_SENDER, 'WRITTEN_OFF' => DeliveryStatus::RETURNED_TO_SENDER ) as $raw => $expected ) {
	$stack['http']->calls = array();
	$stack['http']->return_pages = array( array( 'returns' => array( array( 'return_number' => 'R' . $raw, 'return_external_id' => '1030', 'status' => $raw ) ), 'next_cursor' => '' ) );
	$stack['http']->return_info = array( 'R' . $raw => array( 'return_number' => 'R' . $raw, 'return_external_id' => '1030', 'status' => $raw ) );
	$status = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), oz_return_shipment( array( oz_return_posting( 1, 'P1', 'CANCELED', DeliveryStatus::IN_TRANSIT, true ) ) ), array( 'P1' => 'CANCELED' ) );
	oz_return_assert( $expected === $status['universal_status'], 'Return status ' . $raw . ' must map through settings-backed defaults.' );
}

$stack = oz_return_stack();
$stack['http']->return_pages = array( array( 'returns' => array( array( 'return_number' => 'R3', 'return_external_id' => '1030', 'status' => 'MOVING' ) ), 'next_cursor' => '' ) );
$stack['http']->return_info = array( 'R3' => array( 'return_number' => 'R3', 'return_external_id' => '1030', 'status' => 'MOVING' ) );
$false_handover_found = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), oz_return_shipment( array( oz_return_posting( 1, 'P1', 'CANCELED', DeliveryStatus::CREATED_IN_CARRIER, false ) ) ), array( 'P1' => 'CANCELED' ) );
oz_return_assert( DeliveryStatus::RETURNING_TO_SENDER === $false_handover_found['universal_status'], 'Real return search match must win over handover_seen=false.' );

$stack = oz_return_stack();
$stack['http']->return_pages = array( array( 'returns' => array(), 'next_cursor' => '' ) );
$false_handover_empty = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), oz_return_shipment( array( oz_return_posting( 1, 'P1', 'CANCELED', DeliveryStatus::CREATED_IN_CARRIER, false ) ) ), array( 'P1' => 'CANCELED' ) );
$unknown_handover_empty = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), oz_return_shipment( array( oz_return_posting( 1, 'P1', 'CANCELED', DeliveryStatus::UNKNOWN, null, true ) ) ), array( 'P1' => 'CANCELED' ) );
oz_return_assert( DeliveryStatus::CANCELLED === $false_handover_empty['universal_status'] && DeliveryStatus::UNKNOWN === $unknown_handover_empty['universal_status'], 'Complete no-match search must use handover false as cancelled_no_return and unknown as UNKNOWN.' );

$resolver = new OzonDeliveryReturnLifecycleResolver();
oz_return_assert( DeliveryStatus::RETURNING_TO_SENDER === $resolver->aggregate( array( array( 'state' => 'outbound_active', 'outbound_universal' => DeliveryStatus::DELIVERED ), array( 'state' => 'return_found_active', 'return_universal' => DeliveryStatus::RETURNING_TO_SENDER ) ) ), 'Delivered outbound plus active return must not aggregate to delivered.' );
oz_return_assert( DeliveryStatus::UNKNOWN === $resolver->aggregate( array( array( 'state' => 'outbound_active', 'outbound_universal' => DeliveryStatus::IN_TRANSIT ), array( 'state' => 'return_not_found' ) ) ), 'Outbound active plus unresolved return must aggregate to UNKNOWN.' );
oz_return_assert( DeliveryStatus::RETURNING_TO_SENDER === $resolver->aggregate( array( array( 'state' => 'return_resolved', 'return_universal' => DeliveryStatus::RETURNED_TO_SENDER ), array( 'state' => 'return_found_active', 'return_universal' => DeliveryStatus::RETURNING_TO_SENDER ) ) ), 'MOVING plus RECEIVED returns must stay returning_to_sender.' );
oz_return_assert( DeliveryStatus::RETURNED_TO_SENDER === $resolver->aggregate( array( array( 'state' => 'return_resolved', 'return_universal' => DeliveryStatus::RETURNED_TO_SENDER ), array( 'state' => 'return_resolved', 'return_universal' => DeliveryStatus::RETURNED_TO_SENDER ) ) ), 'All received returns must aggregate to returned_to_sender.' );

$stack = oz_return_stack();
$stack['http']->fail_search = true;
$search_error = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), oz_return_shipment( array( oz_return_posting( 1, 'P1', 'CANCELED', DeliveryStatus::IN_TRANSIT, true ) ) ), array( 'P1' => 'CANCELED' ) );
oz_return_assert( ! $search_error['success'] && $search_error['retryable'] && DeliveryStatus::UNKNOWN === $search_error['universal_status'] && 'error' === (string) ( $search_error['shipment']['ozon_return_search']['search_state'] ?? '' ), 'Return search API error must persist safe diagnostics and return success=false/retryable=true.' );

$stack = oz_return_stack();
$stack['http']->return_pages = array( array( 'returns' => array( array( 'return_number' => 'R4', 'return_external_id' => '1030', 'status' => 'MOVING' ) ), 'next_cursor' => '' ) );
$stack['http']->return_info = array( 'R4' => array( 'return_number' => 'R4', 'return_external_id' => '1030', 'status' => 'MOVING' ) );
$found = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), oz_return_shipment( array( oz_return_posting( 1, 'P1', 'CANCELED', DeliveryStatus::IN_TRANSIT, true ) ) ), array( 'P1' => 'CANCELED' ) );
$stack['http']->fail_info = true;
$info_error = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), $found['shipment'], array() );
oz_return_assert( ! $info_error['success'] && $info_error['retryable'] && 'R4' === (string) ( $info_error['shipment']['ozon_returns'][0]['return_number'] ?? '' ) && 'MOVING' === (string) ( $info_error['shipment']['ozon_returns'][0]['status'] ?? '' ), 'Return info API error must preserve stored return number and last status while returning failure.' );

$stack = oz_return_stack();
$stack['http']->partial_info = true;
$partial = $stack['service']->reconcile(
	new OzonReturnSmokeOrder( '1030' ),
	oz_return_shipment(
		array( oz_return_posting( 1, 'P1', 'CANCELED', DeliveryStatus::IN_TRANSIT, true ), oz_return_posting( 2, 'P2', 'CANCELED', DeliveryStatus::IN_TRANSIT, true ) ),
		array( array( 'place_number' => 1, 'return_number' => 'R5', 'status' => 'RECEIVED' ), array( 'place_number' => 2, 'return_number' => 'R6', 'status' => 'MOVING' ) )
	),
	array()
);
oz_return_assert( ! $partial['success'] && $partial['retryable'] && DeliveryStatus::RETURNED_TO_SENDER !== $partial['universal_status'], 'Partial return/info response must not produce false terminal returned_to_sender.' );

$stack = oz_return_stack();
$stack['http']->return_pages = array( array( 'returns' => array(), 'next_cursor' => '1' ), array( 'returns' => array(), 'next_cursor' => '1' ) );
$repeated_cursor = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), oz_return_shipment( array( oz_return_posting( 1, 'P1', 'CANCELED', DeliveryStatus::IN_TRANSIT, true ) ) ), array( 'P1' => 'CANCELED' ) );
oz_return_assert( ! $repeated_cursor['success'] && 'incomplete' === (string) ( $repeated_cursor['shipment']['ozon_return_search']['search_state'] ?? '' ) && 'return_search_cursor_repeated' === (string) ( $repeated_cursor['shipment']['ozon_return_search']['safe_error_code'] ?? '' ), 'Repeated return/search cursor must stop as incomplete.' );

$stack = oz_return_stack();
$stack['http']->return_pages = array( array( 'returns' => array( array( 'return_number' => 'RX', 'return_external_id' => '1030-extra', 'status' => 'MOVING' ) ), 'next_cursor' => '' ) );
$exact = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), oz_return_shipment( array( oz_return_posting( 1, 'P1', 'CANCELED', DeliveryStatus::IN_TRANSIT, true ) ) ), array( 'P1' => 'CANCELED' ) );
oz_return_assert( DeliveryStatus::UNKNOWN === $exact['universal_status'] && array() === ( $exact['shipment']['ozon_returns'] ?? array() ), 'Exact return_external_id matching must not match 1030-extra for expected 1030.' );

$stack = oz_return_stack();
$stack['http']->return_pages = array( array( 'returns' => array(), 'next_cursor' => '' ) );
$not_found = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), oz_return_shipment( array( oz_return_posting( 1, 'P1', 'CANCELED', DeliveryStatus::IN_TRANSIT, true ) ) ), array( 'P1' => 'CANCELED' ) );
$stack['http']->return_pages = array( array( 'returns' => array( array( 'return_number' => 'R7', 'return_external_id' => '1030', 'status' => 'MOVING' ) ), 'next_cursor' => '' ) );
$stack['http']->return_info = array( 'R7' => array( 'return_number' => 'R7', 'return_external_id' => '1030', 'status' => 'MOVING' ) );
$rediscovered = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), $not_found['shipment'], array( 'P1' => 'UNKNOWN' ) );
oz_return_assert( DeliveryStatus::RETURNING_TO_SENDER === $rediscovered['universal_status'] && 'R7' === (string) ( $rediscovered['shipment']['ozon_returns'][0]['return_number'] ?? '' ) && 2 === $stack['http']->calls_for( '/v1/return/search' ) && 'return_found_active' === (string) ( $rediscovered['shipment']['ozon_postings'][0]['return_state'] ?? '' ), 'Unresolved return_not_found must repeat search from persisted place evidence and clear obsolete unresolved state after finding a return.' );

$stack = oz_return_stack();
$stack['http']->return_pages = array( array( 'returns' => array(), 'next_cursor' => '' ) );
$no_return = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), oz_return_shipment( array( oz_return_posting( 1, 'P1', 'CANCELED', DeliveryStatus::CREATED_IN_CARRIER, false ) ) ), array( 'P1' => 'CANCELED' ) );
$again = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), $no_return['shipment'], array( 'P1' => 'UNKNOWN' ) );
oz_return_assert( DeliveryStatus::CANCELLED === $again['universal_status'] && 1 === $stack['http']->calls_for( '/v1/return/search' ) && 'cancelled_no_return' === (string) ( $again['shipment']['ozon_postings'][0]['return_state'] ?? '' ), 'Resolved cancelled_no_return must not repeat return/search on later updates.' );

$stack = oz_return_stack();
$stack['http']->return_pages = array( array( 'returns' => array( array( 'return_number' => 'R8', 'return_external_id' => '1030', 'status' => 'MOVING' ) ), 'next_cursor' => '' ) );
$stack['http']->return_info = array( 'R8' => array( 'return_number' => 'R8', 'return_external_id' => '1030', 'status' => 'MOVING' ) );
$found_for_error = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), oz_return_shipment( array( oz_return_posting( 1, 'P1', 'CANCELED', DeliveryStatus::IN_TRANSIT, true ) ) ), array( 'P1' => 'CANCELED' ) );
$stack['http']->fail_info = true;
$failed_info = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), $found_for_error['shipment'], array() );
$stack['http']->fail_info = false;
$stack['http']->return_info['R8']['status'] = 'RECEIVED';
$recovered_info = $stack['service']->reconcile( new OzonReturnSmokeOrder( '1030' ), $failed_info['shipment'], array() );
oz_return_assert( ! $failed_info['success'] && $recovered_info['success'] && 'found' === (string) ( $recovered_info['shipment']['ozon_return_search']['search_state'] ?? '' ) && '' === (string) ( $recovered_info['shipment']['ozon_return_search']['safe_error_code'] ?? '' ) && DeliveryStatus::RETURNED_TO_SENDER === $recovered_info['universal_status'], 'Successful return/info retry must clear stale info_error diagnostics.' );

$place_numbers = array();
foreach ( $rediscovered['shipment']['ozon_return_place_states'] as $state ) {
	$place_numbers[] = (int) ( is_array( $state ) ? ( $state['place_number'] ?? 0 ) : 0 );
}
oz_return_assert( array( 1 ) === $place_numbers, 'Return reconciliation must persist exactly one final place state per place.' );

oz_return_assert( ! ( new OzonDeliveryReturnService( new OzonDeliveryApiClient( $stack['http'], new OzonDeliveryAccessTokenService( new OzonDeliveryCredentials( new SettingsRepository(), new EncryptionService() ), $stack['http'], new OzonDeliveryMessageSanitizer(), new OzonDeliveryTokenCache( new EncryptionService() ) ) ), new OzonDeliveryShipmentExternalIdResolver(), new OzonDeliveryReturnSearchParser(), new OzonDeliveryReturnInfoParser(), new OzonDeliveryReturnLifecycleResolver(), $stack['mapper'] ) )->should_reconcile( oz_return_shipment( array( oz_return_posting( 1, 'P1', 'READY_FOR_SHIPPING', DeliveryStatus::CREATED_IN_CARRIER, false ) ) ), array( 'P1' => 'READY_FOR_SHIPPING' ) ), 'Non-canceled outbound without return diagnostics must not start return reconciliation.' );

echo "Ozon Delivery return smoke passed.\n";

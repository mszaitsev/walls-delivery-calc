<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekHttpClientInterface;
use WallsShop\WDC\Carriers\Pek\Api\PekRequestBudget;
use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseSearchCache;
use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseService;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

function pek_dt_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function pek_dt_uuid( string $seed ): string {
	$hash = substr( hash( 'sha256', $seed ), 0, 32 );

	return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-' . substr( $hash, 12, 4 ) . '-' . substr( $hash, 16, 4 ) . '-' . substr( $hash, 20, 12 );
}

function current_datetime(): DateTimeImmutable { return new DateTimeImmutable( $GLOBALS['pek_dt_now'] ); }
function current_time( string $type ): int|string {
	if ( 'timestamp' === $type || 'U' === $type ) {
		throw new RuntimeException( 'PEK availability code must not call current_time timestamp.' );
	}

	return '2026-08-02 12:00:00';
}
function wp_timezone(): DateTimeZone { return new DateTimeZone( '+07:00' ); }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function wp_unslash( mixed $value ): mixed { return is_string( $value ) ? stripslashes( $value ) : $value; }
function sanitize_text_field( string $value ): string { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( $value ) ) ?? '' ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' ); }
function sanitize_email( string $value ): string { return trim( $value ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['pek_dt_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool $autoload = true ): bool { $GLOBALS['pek_dt_options'][ $option ] = $value; return true; }
function get_current_user_id(): int { return 1; }
function get_transient( string $key ): mixed {
	$row = $GLOBALS['pek_dt_transients'][ $key ] ?? null;
	if ( ! is_array( $row ) ) {
		return false;
	}
	if ( (int) ( $row['expires_at'] ?? 0 ) > 0 && (int) $row['expires_at'] <= current_datetime()->getTimestamp() ) {
		unset( $GLOBALS['pek_dt_transients'][ $key ] );
		return false;
	}

	return $row['value'];
}
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
	$GLOBALS['pek_dt_transients'][ $key ] = array(
		'value' => $value,
		'expires_at' => $expiration > 0 ? current_datetime()->getTimestamp() + $expiration : 0,
		'expiration' => $expiration,
	);

	return true;
}
function delete_transient( string $key ): bool { unset( $GLOBALS['pek_dt_transients'][ $key ] ); return true; }

final class PekDatetimeFakeHttp implements PekHttpClientInterface {
	/** @var array<int,array<string,mixed>> */
	public array $requests = array();

	/** @param array<int,array<string,mixed>> $responses */
	public function __construct( private array $responses ) {
	}

	public function request( string $method, string $url, array $args ): array {
		$this->requests[] = array( 'method' => strtoupper( $method ), 'url' => $url, 'args' => $args );

		return array_shift( $this->responses ) ?? pek_dt_json_response( array( 'branches' => array() ) );
	}
}

function pek_dt_json_response( mixed $body ): array {
	return array( 'status' => 200, 'body' => json_encode( $body, JSON_UNESCAPED_UNICODE ) ?: 'null' );
}

function pek_dt_branches_all( string $warehouse_id, mixed $branch_timezone, array $fields = array(), array $operations = array( 'Прием грузов' ), int $type = 3 ): array {
	$branch = array(
		'id' => 'branch-1',
		'title' => 'Тестовый филиал',
		'divisions' => array(
			array(
				'id' => 'division-1',
				'name' => 'Тестовое отделение',
				'departmentTypeId' => 7,
				'departmentType' => 'Отделение компании',
				'warehouses' => array(
					array_merge(
						array(
							'id' => $warehouse_id,
							'name' => 'Склад',
							'divisionName' => 'Склад тестовый',
							'address' => 'short',
							'addressDivision' => 'full',
							'coordinatesobj' => array( 'latitude' => '55.1', 'longitude' => '82.9' ),
							'maxWeight' => 100,
							'maxVolume' => 2,
							'maxWeightPerPlace' => 50,
							'maxDimension' => 3,
							'kindsOfTransportation' => array( array( 'type' => $type, 'operations' => $operations ) ),
						),
						$fields
					),
				),
			),
		),
	);
	if ( '__missing__' !== $branch_timezone ) {
		$branch['timezone'] = $branch_timezone;
	}

	return array( 'branches' => array( $branch ) );
}

function pek_dt_cached_item( string $warehouse_id, mixed $branch_timezone, array $fields = array() ): array {
	return array_merge(
		array(
			'warehouseId' => $warehouse_id,
			'branchId' => 'cache-branch',
			'branchName' => 'Cache branch',
			'divisionName' => 'Cache division',
			'departmentTypeId' => 7,
			'departmentType' => 'Отделение компании',
			'address' => 'Cached address',
			'coordinates' => array( 'latitude' => '55.1', 'longitude' => '82.9' ),
			'branchTimezone' => $branch_timezone,
			'maxWeight' => 100,
			'maxVolume' => 2,
			'maxDimension' => 3,
			'maxWeightOnePlace' => 50,
			'maxCount' => null,
		),
		$fields
	);
}

function pek_dt_nearest_item( string $warehouse_id, mixed $time_zone, array $fields = array() ): array {
	return array_merge(
		array(
			'warehouseId' => $warehouse_id,
			'branchId' => 'branch-id',
			'branchName' => 'Самара',
			'divisionName' => 'Самара Запад',
			'departmentTypeId' => 0,
			'departmentType' => 'Отделение компании',
			'address' => 'Самарская область, г. Самара',
			'coordinates' => array(
				'latitude' => 53.181565,
				'longitude' => 50.174304,
			),
			'timeZone' => $time_zone,
			'priority' => 70,
			'maxWeight' => 0.0,
			'maxVolume' => 0.0,
			'maxDimension' => 0.0,
			'maxWeightOnePlace' => 0.0,
			'maxCount' => 0,
		),
		$fields
	);
}

function pek_dt_service( array $responses, ?PekDatetimeFakeHttp &$http = null, ?PekSettings &$settings = null, ?PekSenderWarehouseSearchCache &$cache = null ): PekSenderWarehouseService {
	$GLOBALS['pek_dt_options'] = array();
	$repository = new SettingsRepository();
	$settings = new PekSettings( $repository, new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer() );
	$credentials = new PekCredentials( $repository, new EncryptionService() );
	$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'secret-key' ) );
	$http = new PekDatetimeFakeHttp( $responses );
	$cache = new PekSenderWarehouseSearchCache();

	return new PekSenderWarehouseService( new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) ), $settings, $cache );
}

function pek_dt_select_from_branches( string $warehouse_id, mixed $branch_timezone, array $fields = array(), ?PekSettings &$settings = null ): array {
	$service = pek_dt_service( array( pek_dt_json_response( pek_dt_branches_all( $warehouse_id, $branch_timezone, $fields ) ) ), $http, $settings, $cache );

	return $service->validate_and_select( $warehouse_id );
}

defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'pek-datetime-test-key' );
$GLOBALS['pek_dt_options'] = array();
$GLOBALS['pek_dt_transients'] = array();
$GLOBALS['pek_dt_now'] = '2026-08-02T12:00:00+07:00';

$result = pek_dt_select_from_branches( pek_dt_uuid( 'clock-ok' ), 'UTC+03:00', array( 'endOfAvailabilityBeforeClosing' => '2026-08-02T09:00:00' ) );
pek_dt_assert( $result['success'], 'PEK availability must compare real Unix instants, not site-offset current_time timestamps.' );
$boundary = pek_dt_select_from_branches( pek_dt_uuid( 'clock-boundary' ), 'UTC+03:00', array( 'endOfAvailabilityBeforeClosing' => '2026-08-02T08:00:00' ) );
pek_dt_assert( ! $boundary['success'], 'PEK exact expiry instant must be rejected when now >= expiry.' );

foreach ( array(
	'date_only' => array( 'UTC+03:00', '2026-08-03' ),
	'leap_date' => array( 'UTC+03:00', '2028-02-29' ),
	'plain_datetime' => array( 'UTC+03:00', '2026-08-02T09:00:00' ),
	'fraction_3' => array( 'UTC+03:00', '2026-08-02T09:00:00.123' ),
	'fraction_6' => array( 'UTC+03:00', '2026-08-02T09:00:00.123456' ),
	'explicit_z' => array( '__missing__', '2026-08-02T06:00:00Z' ),
	'explicit_offset' => array( 'UTC+03:00', '2026-08-02T09:00:00+03:00' ),
	'negative_offset_future' => array( 'UTC-05:00', '2026-08-02T01:00:00' ),
) as $name => $case ) {
	$result = pek_dt_select_from_branches( pek_dt_uuid( 'valid-' . $name ), $case[0], array( 'endOfAvailabilityBeforeClosing' => $case[1] ) );
	pek_dt_assert( $result['success'], 'PEK valid availability date must be accepted: ' . $name );
}

foreach ( array(
	'impossible_february' => '2027-02-30',
	'non_leap_february' => '2027-02-29',
	'invalid_month' => '2027-13-01',
	'zero_month' => '2027-00-10',
	'invalid_day' => '2027-08-32',
	'invalid_hour' => '2027-08-02T25:00:00',
	'invalid_minute' => '2027-08-02T23:60:00',
	'invalid_second' => '2027-08-02T23:59:60',
	'invalid_offset_hour' => '2027-08-02T12:00:00+25:00',
	'invalid_offset_14_30' => '2027-08-02T12:00:00+14:30',
	'localized' => '02 августа 2026',
) as $name => $date_value ) {
	$result = pek_dt_select_from_branches( pek_dt_uuid( 'invalid-' . $name ), 'UTC+03:00', array( 'endOfAvailabilityBeforeClosing' => $date_value ), $settings );
	pek_dt_assert( ! $result['success'] && array() === $settings->sender_warehouse(), 'PEK invalid date must fail closed and not save warehouse: ' . $name );
}

foreach ( array(
	'past_order' => array( 'endOfAvailabilityBeforeClosing', '2026-08-02T07:59:59' ),
	'past_cost' => array( 'endOfCostCalculationAvailability', '2026-08-02T07:59:59' ),
	'past_closing' => array( 'departmentClosingDate', '2026-08-02T07:59:59' ),
) as $name => $case ) {
	$past_id = pek_dt_uuid( 'past-' . $name );
	$service = pek_dt_service( array( pek_dt_json_response( pek_dt_branches_all( $past_id, 'UTC+03:00', array( $case[0] => $case[1] ) ) ) ), $http, $settings, $cache );
	$settings->save_sender_warehouse( array( 'warehouseId' => 'previous', 'branchName' => 'Previous' ) );
	$result = $service->validate_and_select( $past_id );
	pek_dt_assert( ! $result['success'] && $settings->sender_warehouse()['warehouseId'] === 'previous', 'PEK past ' . $case[0] . ' must reject and preserve previous warehouse.' );
}

$timezone_less_missing = pek_dt_select_from_branches( pek_dt_uuid( 'missing-tz' ), '__missing__', array( 'endOfAvailabilityBeforeClosing' => '2026-08-02T09:00:00' ) );
pek_dt_assert( ! $timezone_less_missing['success'], 'PEK timezone-less date must require branch timezone.' );
$timezone_less_invalid = pek_dt_select_from_branches( pek_dt_uuid( 'invalid-tz' ), 'UTC+25:00', array( 'endOfAvailabilityBeforeClosing' => '2026-08-02T09:00:00' ) );
pek_dt_assert( ! $timezone_less_invalid['success'], 'PEK invalid branch timezone must reject timezone-less date.' );
$explicit_missing_timezone = pek_dt_select_from_branches( pek_dt_uuid( 'explicit-missing-tz' ), '__missing__', array( 'endOfAvailabilityBeforeClosing' => '2026-08-02T06:00:00+00:00' ) );
pek_dt_assert( $explicit_missing_timezone['success'], 'PEK explicit timestamp must not require branch timezone.' );
$absent_dates_missing_timezone = pek_dt_select_from_branches( pek_dt_uuid( 'absent-dates-missing-tz' ), '__missing__' );
pek_dt_assert( $absent_dates_missing_timezone['success'], 'PEK missing branch timezone must not reject warehouse without closing fields.' );
$negative_offset_past = pek_dt_select_from_branches( pek_dt_uuid( 'negative-offset-past' ), 'UTC-05:00', array( 'departmentClosingDate' => '2026-08-01T23:59:59' ) );
pek_dt_assert( ! $negative_offset_past['success'], 'PEK UTC-05 branch timezone must compare by instant and reject past expiry.' );

$service = pek_dt_service( array(), $http, $settings, $cache );
$cache_payload = array(
	'success' => true,
	'message' => 'found',
	'requested' => array( 'address' => 'Test', 'departmentOperation' => 2, 'type' => 3 ),
	'items' => array(
		pek_dt_cached_item(
			pek_dt_uuid( 'cache-roundtrip' ),
			'UTC+03:00',
			array(
				'endOfAvailabilityBeforeClosing' => '2026-08-02T09:00:00',
				'endOfCostCalculationAvailability' => null,
				'departmentClosingDate' => '2026-08-03',
			)
		),
	),
);
$cache->save_for_current_user( $cache_payload );
$cached = $cache->current_for_current_user();
pek_dt_assert( ( $cached['items'][0]['branchTimezone'] ?? '' ) === 'UTC+03:00' && ( $cached['items'][0]['endOfAvailabilityBeforeClosing'] ?? '' ) === '2026-08-02T09:00:00' && ( $cached['items'][0]['departmentClosingDate'] ?? '' ) === '2026-08-03', 'PEK search cache must preserve branch timezone and availability fields after round-trip.' );
$result = $service->validate_and_select( pek_dt_uuid( 'cache-roundtrip' ) );
$snapshot = $settings->sender_warehouse();
pek_dt_assert( $result['success'] && count( $http->requests ) === 0 && $snapshot['branchTimezone'] === 'UTC+03:00' && $snapshot['availability']['departmentClosingDate'] === '2026-08-03', 'PEK cached future warehouse can be selected and snapshot preserves timezone/availability fields.' );

$nearest_service = pek_dt_service(
	array(
		pek_dt_json_response(
			array(
				'freeDepartments' => array( pek_dt_nearest_item( pek_dt_uuid( 'nearest-timezone-wh' ), '04:00:00' ) ),
				'paidDepartments' => array(),
			)
		),
	),
	$nearest_http,
	$nearest_settings,
	$nearest_cache
);
$nearest_search = $nearest_service->search( 'Самара' );
$nearest_cached = $nearest_cache->current_for_current_user();
pek_dt_assert( ( $nearest_search['items'][0]['branchTimezone'] ?? '' ) === 'UTC+04:00' && ( $nearest_cached['items'][0]['branchTimezone'] ?? '' ) === 'UTC+04:00' && ( $nearest_cached['items'][0]['source'] ?? '' ) === 'free', 'PEK nearestdepartments timeZone and free source must normalize and survive cache round-trip.' );
$nearest_result = $nearest_service->validate_and_select( pek_dt_uuid( 'nearest-timezone-wh' ) );
$nearest_snapshot = $nearest_settings->sender_warehouse();
pek_dt_assert( $nearest_result['success'] && count( $nearest_http->requests ) === 1 && $nearest_http->requests[0]['url'] === PekSettings::BASE_URL . '/branches/nearestdepartments/' && $nearest_snapshot['branchTimezone'] === 'UTC+04:00' && $nearest_snapshot['source'] === 'free' && ! array_key_exists( 'timeZone', $nearest_snapshot ), 'PEK search/select path must save cached canonical timezone and source without branches/all fallback or raw timeZone field.' );

$paid_service = pek_dt_service(
	array(
		pek_dt_json_response(
			array(
				'freeDepartments' => array(),
				'paidDepartments' => array( pek_dt_nearest_item( pek_dt_uuid( 'paid-timezone-wh' ), '05:30:00' ) ),
			)
		),
	),
	$paid_http,
	$paid_settings,
	$paid_cache
);
$paid_service->search( 'Самара' );
pek_dt_assert( ( $paid_cache->current_for_current_user()['items'][0]['branchTimezone'] ?? '' ) === 'UTC+05:30' && ( $paid_cache->current_for_current_user()['items'][0]['source'] ?? '' ) === 'paid', 'PEK paidDepartments timeZone must use the same normalizer and preserve paid source.' );

foreach ( array( '00:00:00' => 'UTC+00:00', '03:00:00' => 'UTC+03:00', '04:30:00' => 'UTC+04:30', '14:00:00' => 'UTC+14:00' ) as $source_timezone => $canonical_timezone ) {
	$timezone_service = pek_dt_service( array( pek_dt_json_response( array( 'freeDepartments' => array( pek_dt_nearest_item( pek_dt_uuid( 'valid-tz-' . str_replace( ':', '-', $source_timezone ) ), $source_timezone ) ), 'paidDepartments' => array() ) ) ), $timezone_http, $timezone_settings, $timezone_cache );
	$timezone_service->search( 'Самара' );
	pek_dt_assert( ( $timezone_cache->current_for_current_user()['items'][0]['branchTimezone'] ?? '' ) === $canonical_timezone, 'PEK nearestdepartments valid timeZone must normalize: ' . $source_timezone );
}

foreach ( array( '3:00:00', '03:0:00', '03:00', '03:00:01', '03:60:00', '15:00:00', '14:30:00', '-05:00:00', 'UTC+03:00:00', 'MSK', 'Europe/Moscow', 'arbitrary string' ) as $invalid_timezone ) {
	$invalid_tz_service = pek_dt_service( array( pek_dt_json_response( array( 'freeDepartments' => array( pek_dt_nearest_item( pek_dt_uuid( 'invalid-tz-' . md5( $invalid_timezone ) ), $invalid_timezone ) ), 'paidDepartments' => array() ) ) ), $invalid_tz_http, $invalid_tz_settings, $invalid_tz_cache );
	$invalid_tz_service->search( 'Самара' );
	$invalid_item = $invalid_tz_cache->current_for_current_user()['items'][0] ?? array();
	pek_dt_assert( is_array( $invalid_item ) && null === ( $invalid_item['branchTimezone'] ?? null ) && ! str_contains( json_encode( $invalid_item, JSON_UNESCAPED_UNICODE ) ?: '', 'secret-key' ), 'PEK invalid nearestdepartments timeZone must not persist raw source or credentials: ' . $invalid_timezone );
}

$missing_tz_service = pek_dt_service( array( pek_dt_json_response( array( 'freeDepartments' => array( pek_dt_nearest_item( pek_dt_uuid( 'missing-timezone-wh' ), null ) ), 'paidDepartments' => array() ) ) ), $missing_tz_http, $missing_tz_settings, $missing_tz_cache );
$missing_tz_service->search( 'Самара' );
pek_dt_assert( $missing_tz_service->validate_and_select( pek_dt_uuid( 'missing-timezone-wh' ) )['success'] && null === ( $missing_tz_settings->sender_warehouse()['branchTimezone'] ?? null ), 'PEK missing nearestdepartments timeZone must not reject item without closing fields.' );

$unresolved_cached_service = pek_dt_service(
	array(
		pek_dt_json_response( pek_dt_branches_all( pek_dt_uuid( 'cached-unresolved-nearest-tz' ), 'UTC+04:00', array( 'endOfAvailabilityBeforeClosing' => '2026-08-02T10:00:00' ) ) ),
	),
	$unresolved_cached_http,
	$unresolved_cached_settings,
	$unresolved_cached_cache
);
$unresolved_cached_cache->save_for_current_user(
	array(
		'success' => true,
		'requested' => array( 'address' => 'Самара', 'departmentOperation' => 2, 'type' => 3 ),
		'items' => array( pek_dt_nearest_item( pek_dt_uuid( 'cached-unresolved-nearest-tz' ), 'bad', array( 'endOfAvailabilityBeforeClosing' => '2026-08-02T10:00:00' ) ) ),
	)
);
$unresolved_result = $unresolved_cached_service->validate_and_select( pek_dt_uuid( 'cached-unresolved-nearest-tz' ) );
pek_dt_assert( $unresolved_result['success'] && count( $unresolved_cached_http->requests ) === 1 && $unresolved_cached_settings->sender_warehouse()['branchTimezone'] === 'UTC+04:00', 'PEK cached timezone-less date without canonical branchTimezone must fall back to branches/all.' );

$settings->save_sender_warehouse( array( 'warehouseId' => 'previous-cache', 'branchName' => 'Previous cache' ) );
$cache->save_for_current_user(
	array(
		'success' => true,
		'requested' => array( 'address' => 'Test', 'departmentOperation' => 2, 'type' => 3 ),
		'items' => array( pek_dt_cached_item( pek_dt_uuid( 'cache-past' ), 'UTC+03:00', array( 'departmentClosingDate' => '2026-08-02T07:59:59' ) ) ),
	)
);
$result = $service->validate_and_select( pek_dt_uuid( 'cache-past' ) );
pek_dt_assert( ! $result['success'] && $settings->sender_warehouse()['warehouseId'] === 'previous-cache', 'PEK cached past warehouse must not authorize selection and must preserve previous warehouse.' );

$settings->save_sender_warehouse( array( 'warehouseId' => 'previous-invalid-cache', 'branchName' => 'Previous invalid cache' ) );
$cache->save_for_current_user(
	array(
		'success' => true,
		'requested' => array( 'address' => 'Test', 'departmentOperation' => 2, 'type' => 3 ),
		'items' => array( pek_dt_cached_item( pek_dt_uuid( 'cache-invalid-date' ), 'UTC+03:00', array( 'endOfAvailabilityBeforeClosing' => '2027-02-30' ) ) ),
	)
);
$result = $service->validate_and_select( pek_dt_uuid( 'cache-invalid-date' ) );
pek_dt_assert( ! $result['success'] && $settings->sender_warehouse()['warehouseId'] === 'previous-invalid-cache', 'PEK cached invalid date must not authorize warehouse selection.' );

$fallback_service = pek_dt_service( array( pek_dt_json_response( pek_dt_branches_all( pek_dt_uuid( 'cache-unresolved' ), 'UTC+03:00', array( 'endOfAvailabilityBeforeClosing' => '2026-08-02T09:00:00' ) ) ) ), $fallback_http, $fallback_settings, $fallback_cache );
$fallback_cache->save_for_current_user(
	array(
		'success' => true,
		'requested' => array( 'address' => 'Test', 'departmentOperation' => 2, 'type' => 3 ),
		'items' => array( pek_dt_cached_item( pek_dt_uuid( 'cache-unresolved' ), null, array( 'endOfAvailabilityBeforeClosing' => '2026-08-02T09:00:00' ) ) ),
	)
);
$result = $fallback_service->validate_and_select( pek_dt_uuid( 'cache-unresolved' ) );
pek_dt_assert( $result['success'] && count( $fallback_http->requests ) === 1 && $fallback_http->requests[0]['url'] === PekSettings::BASE_URL . '/branches/all/' && $fallback_settings->sender_warehouse()['branchTimezone'] === 'UTC+03:00', 'PEK unresolved cached timezone/date must trigger fresh branches/all validation.' );

$snapshot_repository = new SettingsRepository();
$snapshot_settings = new PekSettings( $snapshot_repository, new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer() );
$snapshot_settings->save_sender_warehouse(
	array(
		'warehouseId' => pek_dt_uuid( 'sanitize' ),
		'source' => 'free',
		'branchTimezone' => 'UTC+03:00',
		'availability' => array(
			'endOfAvailabilityBeforeClosing' => '2026-08-02T09:00:00',
			'endOfCostCalculationAvailability' => null,
			'departmentClosingDate' => '2026-08-03',
		),
	)
);
$sanitized = $snapshot_settings->sender_warehouse();
pek_dt_assert( $sanitized['branchTimezone'] === 'UTC+03:00' && $sanitized['source'] === 'free' && $sanitized['availability']['endOfAvailabilityBeforeClosing'] === '2026-08-02T09:00:00' && null === $sanitized['availability']['endOfCostCalculationAvailability'] && $sanitized['availability']['departmentClosingDate'] === '2026-08-03', 'PEK settings snapshot sanitation must preserve source, branch timezone, availability strings, and nulls.' );

$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Pek/Api/PekSenderWarehouseService.php' );
pek_dt_assert( ! str_contains( $source, "current_time( 'timestamp'" ) && ! str_contains( $source, "current_time('timestamp'" ) && ! str_contains( $source, "current_time( 'U'" ) && ! str_contains( $source, "current_time('U'" ) && ! str_contains( $source, 'strtotime(' ), 'PEK warehouse datetime code must not use current_time timestamp or strtotime.' );
pek_dt_assert( ! str_contains( $source, 'require_valid_machine_dates' ), 'PEK warehouse availability must not keep unused require_valid_machine_dates parameter.' );
pek_dt_assert( str_contains( $source, "'timeZone'" ) && str_contains( $source, 'normalize_nearest_department_timezone' ) && ! str_contains( $source, 'DateFramework' ), 'PEK warehouse normalizer must explicitly support nearestdepartments timeZone without generic timezone framework.' );

echo "PEK warehouse datetime smoke OK\n";

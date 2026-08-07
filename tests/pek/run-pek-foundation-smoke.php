<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\Api\PekConnectionDiagnosticService;
use WallsShop\WDC\Carriers\Pek\Api\PekHttpClientInterface;
use WallsShop\WDC\Carriers\Pek\Api\PekRequestBudget;
use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseSearchCache;
use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseService;
use WallsShop\WDC\Carriers\Pek\Admin\PekAdminNoticeStore;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Rules\Storage\RuleRepository;

function pek_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_datetime(): DateTimeImmutable { return ( new DateTimeImmutable( '@' . (string) (int) $GLOBALS['pek_now'] ) )->setTimezone( new DateTimeZone( 'UTC' ) ); }
function current_time( string $type ): int|string { return 'timestamp' === $type ? (int) $GLOBALS['pek_now'] : '2026-08-02 12:00:00'; }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function wp_unslash( mixed $value ): mixed {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wp_slash( mixed $value ): mixed {
	if ( is_array( $value ) ) {
		return array_map( 'wp_slash', $value );
	}
	return is_string( $value ) ? addslashes( $value ) : $value;
}
function sanitize_text_field( string $value ): string { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( $value ) ) ?? '' ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' ); }
function sanitize_email( string $value ): string { return trim( $value ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['pek_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool $autoload = true ): bool { $GLOBALS['pek_options'][ $option ] = $value; return true; }
function get_current_user_id(): int { return (int) ( $GLOBALS['pek_current_user_id'] ?? 1 ); }
function get_transient( string $key ): mixed {
	$row = $GLOBALS['pek_transients'][ $key ] ?? null;
	if ( ! is_array( $row ) ) {
		return false;
	}
	if ( (int) ( $row['expires_at'] ?? 0 ) > 0 && (int) $row['expires_at'] <= (int) $GLOBALS['pek_now'] ) {
		unset( $GLOBALS['pek_transients'][ $key ] );
		return false;
	}
	return $row['value'];
}
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
	$GLOBALS['pek_transients'][ $key ] = array(
		'value' => $value,
		'expires_at' => $expiration > 0 ? (int) $GLOBALS['pek_now'] + $expiration : 0,
		'expiration' => $expiration,
	);
	return true;
}
function delete_transient( string $key ): bool { unset( $GLOBALS['pek_transients'][ $key ] ); return true; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public array $services = array();
		public array $countries = array();
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function insert( string $table, array $data, array $format = array() ): bool {
			$data['id'] = ++$this->insert_id;
			if ( str_contains( $table, 'wdc_delivery_service_countries' ) ) {
				$this->countries[] = $data;
			} elseif ( str_contains( $table, 'wdc_delivery_services' ) ) {
				$this->services[] = $data;
			}
			return true;
		}
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			$rows =& $this->rows( $table );
			foreach ( $rows as $index => $row ) {
				$matches = true;
				foreach ( $where as $key => $value ) {
					$matches = $matches && (string) ( $row[ $key ] ?? '' ) === (string) $value;
				}
				if ( $matches ) {
					$rows[ $index ] = array_merge( $row, $data );
				}
			}
			return true;
		}
		public function delete( string $table, array $where, array $format = array() ): bool {
			$rows =& $this->rows( $table );
			$rows = array_values( array_filter( $rows, static function ( array $row ) use ( $where ): bool {
				foreach ( $where as $key => $value ) {
					if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
						return true;
					}
				}
				return false;
			} ) );
			return true;
		}
		public function replace( string $table, array $data, array $format = array() ): bool { return $this->insert( $table, $data, $format ); }
		public function get_row( string $query, mixed $output = null ): ?array {
			if ( preg_match( '/WHERE id = ([0-9]+)/', $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( (int) $row['id'] === (int) $matches[1] ) {
						return $row;
					}
				}
			}
			if ( preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( (string) $row['service_key'] === $matches[1] && ( ! str_contains( $query, 'deleted = 0' ) || empty( $row['deleted'] ) ) ) {
						return $row;
					}
				}
			}
			return null;
		}
		public function get_results( string $query, mixed $output = null ): array {
			if ( str_contains( $query, 'wdc_delivery_service_countries' ) && preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				return array_values( array_filter( $this->countries, static fn( array $row ): bool => (int) $row['service_id'] === (int) $matches[1] ) );
			}
			if ( str_contains( $query, 'wdc_delivery_services' ) && preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
				return array_values( array_filter( $this->services, static fn( array $row ): bool => (string) $row['service_key'] === $matches[1] && empty( $row['deleted'] ) ) );
			}
			return $this->services;
		}
		public function get_col( string $query ): array {
			if ( str_contains( $query, 'wdc_delivery_service_countries' ) && preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				return array_values( array_map( static fn( array $row ): string => (string) $row['country_code'], array_filter( $this->countries, static fn( array $row ): bool => (int) $row['service_id'] === (int) $matches[1] ) ) );
			}
			return array();
		}
		private function &rows( string $table ): array {
			if ( str_contains( $table, 'wdc_delivery_service_countries' ) ) {
				return $this->countries;
			}
			return $this->services;
		}
	}
}

final class PekFakeHttp implements PekHttpClientInterface {
	public array $requests = array();
	public function __construct( private array $responses ) {}
	public function request( string $method, string $url, array $args ): array {
		$this->requests[] = array( 'method' => strtoupper( $method ), 'url' => $url, 'args' => $args );
		return array_shift( $this->responses ) ?? array( 'status' => 200, 'body' => '[]' );
	}
}

function pek_json_response( mixed $body ): array {
	return array( 'status' => 200, 'body' => json_encode( $body, JSON_UNESCAPED_UNICODE ) ?: 'null' );
}

function pek_boot_api( SettingsRepository $settings_repository, PekFakeHttp $http ): array {
	$settings = new PekSettings( $settings_repository, new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer() );
	$credentials = new PekCredentials( $settings_repository, new EncryptionService() );
	$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'secret-key' ) );
	return array( $settings, $credentials, new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) ) );
}

$GLOBALS['pek_options'] = array();
$GLOBALS['pek_transients'] = array();
$GLOBALS['pek_current_user_id'] = 1;
$GLOBALS['pek_now'] = 1785652800;

$settings_repository = new SettingsRepository();
$settings = new PekSettings( $settings_repository, new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer() );
$credentials = new PekCredentials( $settings_repository, new EncryptionService() );

pek_assert( PekSettings::CARRIER_KEY === 'pek' && PekSettings::SERVICE_KEY === 'pek', 'PEK keys must be stable.' );
pek_assert( PekSettings::LTL_PRODUCT_TYPE === 3, 'PEK LTL product type must be 3.' );
pek_assert( PekSettings::PLANNED_COUNTRIES === array( 'RU', 'AM', 'BY', 'KG', 'KZ' ), 'PEK planned countries must include RU/AM/BY/KG/KZ.' );
pek_assert( PekSettings::INITIAL_COUNTRIES === array( 'RU' ), 'PEK initial countries must be RU only.' );
pek_assert( PekSettings::COUNTRY_CLASSIFIER_CODES === array( 'RU' => '643', 'AM' => '051', 'BY' => '112', 'KG' => '417', 'KZ' => '398' ), 'PEK classifier codes must be centralized.' );
pek_assert( $settings->request_timeout() === 15 && $settings->request_soft_limit_per_minute() === 90, 'PEK settings defaults must be safe.' );
$settings->save_from_admin( array( PekSettings::REQUEST_TIMEOUT_KEY => 999, PekSettings::REQUESTS_PER_MINUTE_KEY => 999, PekSettings::SENDER_LEGAL_FORM_KEY => 1, PekSettings::SENDER_INN_KEY => '1234567890', PekSettings::SENDER_KPP_KEY => '123456789', PekSettings::SENDER_PHONE_KEY => '+7 (999) 123-45-67', PekSettings::SENDER_EMAIL_KEY => 'sender@example.test', PekSettings::SENDER_REGISTRATION_COUNTRY_KEY => 'KZ', PekSettings::SMS_RELEASE_LIMIT_RUB_KEY => 0 ) );
pek_assert( $settings->request_timeout() === 60 && $settings->request_soft_limit_per_minute() === 100, 'PEK numeric settings must clamp.' );
pek_assert( $settings->sender_inn() === '1234567890' && $settings->sender_kpp() === '123456789' && $settings->sender_phone() === '+79991234567' && $settings->sender_registration_classifier_code() === '398', 'PEK sender settings must be normalized without cleaning malformed identity values.' );
try {
	$settings->save_from_admin( array( PekSettings::SENDER_LEGAL_FORM_KEY => 1, PekSettings::SENDER_INN_KEY => '12-34', PekSettings::SENDER_KPP_KEY => '123456789', PekSettings::SENDER_PHONE_KEY => '+79991234567', PekSettings::SENDER_EMAIL_KEY => 'sender@example.test' ) );
	pek_assert( false, 'Malformed PEK sender INN must be rejected.' );
} catch ( InvalidArgumentException ) {
	pek_assert( $settings->sender_inn() === '1234567890', 'Rejected malformed PEK sender INN must not partially update settings.' );
}
pek_assert( $settings->sms_release_limit_rub() === 1, 'PEK SMS limit must clamp lower bound.' );
$settings_repository->set( PekSettings::SMS_RELEASE_LIMIT_RUB_KEY, PekSettings::DEFAULT_SMS_RELEASE_LIMIT_RUB );
pek_assert( $settings->sms_release_limit_rub() === 500000, 'PEK SMS limit default must be 500000 rub.' );

pek_assert( ! $credentials->encryption_ready(), 'PEK credentials must detect missing APP_ENCRYPTION_KEY.' );
$preserve = $credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'secret' ) );
pek_assert( ! $preserve && ! $credentials->has_api_key(), 'PEK credentials must not save API key when encryption is unavailable.' );
$settings_repository->set( PekSettings::API_KEY_ENCRYPTED_KEY, 'existing-encrypted-secret' );
$preserve_existing = $credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'replacement' ) );
pek_assert( ! $preserve_existing && $settings_repository->get_string( PekSettings::API_KEY_ENCRYPTED_KEY, '' ) === 'existing-encrypted-secret', 'PEK failed replacement without encryption must preserve existing encrypted secret.' );
$credentials->clear_api_key();
define( 'APP_ENCRYPTION_KEY', 'pek-test-key' );
$secret = 'a\\b"c\'d+/:=';
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => wp_slash( $secret ) ) );
pek_assert( $credentials->login() === 'login' && $credentials->api_key() === $secret && $credentials->has_api_key(), 'PEK encrypted key must save wp_unslashed secret exactly.' );
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login2', 'pek_api_key' => '' ) );
pek_assert( $credentials->login() === 'login2' && $credentials->api_key() === $secret, 'Empty PEK password field must preserve key.' );
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login2', 'pek_clear_api_key' => '1' ) );
pek_assert( '' === $credentials->api_key() && ! $credentials->has_api_key(), 'PEK API key clear checkbox must clear key.' );
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'secret-key' ) );

$http = new PekFakeHttp( array(
	pek_json_response( array( array( 'type' => 3 ) ) ),
	pek_json_response( array( array( 'shortName' => 'RU', 'codeByClassifier' => '643' ) ) ),
	pek_json_response( array( array( 'name' => 'ООО' ) ) ),
	pek_json_response( array( 'freeDepartments' => array(), 'paidDepartments' => array() ) ),
	pek_json_response( array( 'branches' => array() ) ),
	pek_json_response( array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array() ) ),
) );
$api = new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) );
$api->types_of_delivery_all();
$api->branches_country();
$api->legal_form_types();
$api->nearest_departments( 'Новосибирск' );
$api->branches_all_for_warehouse( '85974fc8-d0b8-11e5-9833-00155d668909' );
$api->calculate_price( array( 'currencyCode' => '643', 'types' => array( 3 ) ) );
$methods = array_column( $http->requests, 'method' );
pek_assert( $methods === array( 'GET', 'POST', 'POST', 'POST', 'POST', 'POST' ), 'PEK typed API methods must use GET for typesOfDelivery and POST for the other foundation methods including calculator.' );
pek_assert( $http->requests[0]['url'] === PekSettings::BASE_URL . '/typesOfDelivery/all/' && ! isset( $http->requests[0]['args']['body'] ) && $http->requests[0]['args']['headers']['Content-Type'] === 'application/json;charset=utf-8', 'PEK GET request must have official URL, JSON Content-Type header and no body.' );
pek_assert( $http->requests[5]['url'] === PekSettings::BASE_URL . '/calculator/calculateprice/' && 'POST' === $http->requests[5]['method'], 'PEK calculate_price must use typed POST /calculator/calculateprice/.' );
foreach ( array_slice( $http->requests, 1 ) as $request ) {
	pek_assert( str_starts_with( $request['url'], 'https://' ) && true === $request['args']['sslverify'], 'PEK requests must use HTTPS and sslverify.' );
	pek_assert( $request['args']['headers']['Content-Type'] === 'application/json;charset=utf-8' && $request['args']['headers']['Accept'] === 'application/json' && $request['args']['headers']['Accept-Encoding'] === 'gzip', 'PEK POST JSON headers must match protocol.' );
}
pek_assert( $http->requests[0]['args']['headers']['Authorization'] === 'Basic ' . base64_encode( 'login:secret-key' ) && $http->requests[0]['args']['timeout'] === 60, 'PEK Basic Auth and timeout must be built from settings/credentials.' );
pek_assert( ! ( new ReflectionClass( PekApiClient::class ) )->hasMethod( 'build_args_for_test' ), 'PEK production API client must not expose build_args_for_test().' );
try {
	$method = ( new ReflectionClass( PekApiClient::class ) )->getMethod( 'call' );
	$method->setAccessible( true );
	$method->invoke( $api, 'PATCH', '/typesOfDelivery/all/', array() );
	pek_assert( false, 'PEK invalid HTTP method must be rejected before transport.' );
} catch ( PekApiException $exception ) {
	pek_assert( ( $exception->context()['error_code'] ?? '' ) === 'pek_invalid_http_method' && count( $http->requests ) === 6, 'PEK invalid method must expose stable error and avoid network.' );
}

foreach ( array(
	'logical' => array( 'status' => 200, 'body' => json_encode( array( 'error' => array( 'title' => 'Bad', 'message' => 'No' ) ) ), 'code' => 'pek_logical_error' ),
	'403' => array( 'status' => 403, 'body' => '{}', 'code' => 'pek_http_403' ),
	'404' => array( 'status' => 404, 'body' => '{}', 'code' => 'pek_http_404' ),
	'500' => array( 'status' => 500, 'body' => '{}', 'code' => 'pek_http_500' ),
	'invalid' => array( 'status' => 200, 'body' => '{bad', 'code' => 'pek_invalid_json' ),
	'empty' => array( 'status' => 200, 'body' => '', 'code' => 'pek_empty_response' ),
	'transport' => array( 'error' => true, 'message' => 'network secret-key Basic ' . base64_encode( 'login:secret-key' ), 'code' => 'pek_transport_error' ),
) as $case ) {
	try {
		( new PekApiClient( $settings, $credentials, new PekFakeHttp( array( $case ) ), new PekRequestBudget( $settings ) ) )->types_of_delivery_all();
		pek_assert( false, 'PEK API must fail for ' . $case['code'] );
	} catch ( PekApiException $exception ) {
		pek_assert( ( $exception->context()['error_code'] ?? '' ) === $case['code'], 'PEK API must expose stable error code ' . $case['code'] );
		pek_assert( ! str_contains( $exception->getMessage(), 'secret-key' ) && ! str_contains( $exception->getMessage(), base64_encode( 'login:secret-key' ) ), 'PEK API exception messages must be redacted.' );
	}
}

try {
	( new PekApiClient( $settings, $credentials, new PekFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( 'error' => array( 'title' => array( 'bad' ), 'message' => (object) array() ) ) ) ) ) ), new PekRequestBudget( $settings ) ) )->types_of_delivery_all();
	pek_assert( false, 'PEK logical error with malformed title/message must still fail.' );
} catch ( PekApiException $exception ) {
	pek_assert( 'pek_logical_error' === (string) ( $exception->context()['error_code'] ?? '' ) && 'ПЭК вернул логическую ошибку без описания.' === $exception->getMessage(), 'Malformed PEK logical error description must use safe fallback without Array casts.' );
}

try {
	$field_messages = array( 'Значение должно быть больше 0.', 'Значение должно быть больше 0.', "login=login api_key=secret-key Basic " . base64_encode( 'login:secret-key' ) );
	$field_rows = array(
		array( 'Key' => 'volume', 'Value' => $field_messages, 'RejectedValue' => 'Москва, секретный адрес' ),
		array( 'Key' => 'volume', 'Value' => array( 'Второе сообщение.' ) ),
		array( 'Key' => 'searchRadius', 'Value' => array( 'Максимально допустимое значение — 100.' ) ),
		array( 'Key' => array( 'bad' ), 'Value' => array( 'skip' ) ),
		array( 'Key' => '<script>alert(1)</script>', 'Value' => array( '<b>bad</b>' ) ),
	);
	for ( $i = 0; $i < 25; $i++ ) {
		$field_rows[] = array( 'Key' => 'field-' . $i, 'Value' => array( str_repeat( 'x', 700 ) ) );
	}
	( new PekApiClient( $settings, $credentials, new PekFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( 'error' => array( 'title' => 'Ошибка валидации', 'message' => 'Детальные сообщения об ошибках приведены отдельно для каждого поля', 'fields' => $field_rows ) ), JSON_UNESCAPED_UNICODE ) ) ) ), new PekRequestBudget( $settings ) ) )->destination_nearest_departments( new \WallsShop\WDC\Carriers\Pek\Pickup\PekDestinationTerminalRequest( '', 55.030204, 82.92043, 1000, 1.2, 1000, 1000, 1, 50, 20 ) );
	pek_assert( false, 'PEK logical validation error with field details must fail.' );
} catch ( PekApiException $exception ) {
	$field_errors = $exception->context()['field_errors'] ?? array();
	$field_json = json_encode( $field_errors, JSON_UNESCAPED_UNICODE ) ?: '';
	pek_assert( 'pek_logical_error' === (string) ( $exception->context()['error_code'] ?? '' ) && is_array( $field_errors ) && count( $field_errors ) === 20, 'PEK logical validation error must expose normalized field_errors with field limit.' );
	pek_assert( 'volume' === (string) ( $field_errors[0]['field'] ?? '' ) && 'Значение должно быть больше 0.' === (string) ( $field_errors[0]['messages'][0] ?? '' ) && str_contains( (string) ( $field_errors[0]['messages'][1] ?? '' ), '[redacted]' ) && 'Второе сообщение.' === (string) ( $field_errors[0]['messages'][2] ?? '' ), 'PEK field errors must preserve order, merge duplicate fields, deduplicate messages and redact credentials.' );
	pek_assert( 'searchRadius' === (string) ( $field_errors[1]['field'] ?? '' ) && 'Максимально допустимое значение — 100.' === (string) ( $field_errors[1]['messages'][0] ?? '' ), 'PEK field errors must preserve later Key/Value field order.' );
	pek_assert( str_contains( $field_json, '<script>alert(1)' ) && str_contains( $field_json, '<b>bad' ) && ! str_contains( $field_json, 'unknown_field' ) && ! str_contains( $field_json, 'RejectedValue' ) && ! str_contains( $field_json, 'Москва, секретный адрес' ) && ! str_contains( $field_json, 'secret-key' ) && ! str_contains( $field_json, base64_encode( 'login:secret-key' ) ), 'PEK field error context must keep only safe names/messages without raw values or secrets.' );
	pek_assert( strlen( (string) ( $field_errors[3]['messages'][0] ?? '' ) ) <= 500, 'PEK field error messages must be length-limited.' );
}

try {
	( new PekApiClient( $settings, $credentials, new PekFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( 'error' => array( 'title' => 'Ошибка', 'message' => 'Описание', 'fields' => array( 'volume' => array( 'Значение должно быть больше 0.' ) ) ) ), JSON_UNESCAPED_UNICODE ) ) ) ), new PekRequestBudget( $settings ) ) )->types_of_delivery_all();
	pek_assert( false, 'PEK logical field map must still fail.' );
} catch ( PekApiException $exception ) {
	$field_errors = $exception->context()['field_errors'] ?? array();
	pek_assert( is_array( $field_errors ) && 'volume' === (string) ( $field_errors[0]['field'] ?? '' ) && 'Значение должно быть больше 0.' === (string) ( $field_errors[0]['messages'][0] ?? '' ), 'PEK field error parser must normalize associative error.fields maps.' );
}

$settings_repository->set( PekSettings::REQUESTS_PER_MINUTE_KEY, 1 );
$GLOBALS['pek_transients'] = array();
$limited_api = new PekApiClient( $settings, $credentials, new PekFakeHttp( array( pek_json_response( array() ), pek_json_response( array() ) ) ), new PekRequestBudget( $settings ) );
$limited_api->types_of_delivery_all();
try {
	$limited_api->types_of_delivery_all();
	pek_assert( false, 'PEK local rate limit must fail.' );
} catch ( PekApiException $exception ) {
	pek_assert( ( $exception->context()['error_code'] ?? '' ) === PekRequestBudget::ERROR_CODE, 'PEK local rate limit must expose stable carrier error code.' );
}
$settings_repository->set( PekSettings::REQUESTS_PER_MINUTE_KEY, 90 );
$GLOBALS['pek_transients'] = array();

$diag_http = new PekFakeHttp( array(
	pek_json_response( array( array( 'type' => 3, 'name' => 'ПЭК:LTL Авто' ) ) ),
	pek_json_response( array(
		array( 'codeByClassifier' => '643', 'name' => 'РОССИЯ', 'shortName' => 'RU' ),
		array( 'codeByClassifier' => '398', 'name' => 'КАЗАХСТАН', 'shortName' => 'KZ' ),
	) ),
	pek_json_response( array( array( 'name' => 'ООО', 'shortName' => 'ООО' ) ) ),
) );
$diag = ( new PekConnectionDiagnosticService( $settings, $credentials, new PekApiClient( $settings, $credentials, $diag_http, new PekRequestBudget( $settings ) ) ) )->run();
pek_assert( $diag['success'] && $diag['connection_ok'] && $diag['ltl_product_type'] === true && $diag['legal_forms_available'] === true && $diag['countries_found'] === array( 'RU', 'KZ' ) && in_array( 'AM', $diag['planned_countries_missing'], true ), 'PEK diagnostic must use official shortName/codeByClassifier and not require all planned countries.' );
pek_assert( ( $diag['checks']['products']['method'] ?? '' ) === 'GET' && ( $diag['checks']['countries']['method'] ?? '' ) === 'POST' && ( $diag['checks']['warehouse_api']['status'] ?? '' ) === 'skipped', 'PEK diagnostic checks must expose methods and skip warehouse check without selected warehouse.' );
$mismatch_http = new PekFakeHttp( array(
	pek_json_response( array( array( 'type' => 3 ) ) ),
	pek_json_response( array( array( 'codeByClassifier' => '999', 'name' => 'РОССИЯ', 'shortName' => 'RU' ), array( 'code' => 'KZ' ) ) ),
	pek_json_response( array( array( 'name' => 'ООО' ) ) ),
) );
$mismatch = ( new PekConnectionDiagnosticService( $settings, $credentials, new PekApiClient( $settings, $credentials, $mismatch_http, new PekRequestBudget( $settings ) ) ) )->run();
pek_assert( $mismatch['connection_ok'] && $mismatch['countries_found'] === array() && ( $mismatch['classifier_mismatches'][0]['country'] ?? '' ) === 'RU', 'PEK diagnostic must report RU classifier mismatch and not rely on invented code field.' );

$foundation_wh_1 = '85974fc8-d0b8-11e5-9833-00155d668909';
$foundation_cache_wh = '5c7775d4-0013-11ec-80cf-00155d4a0436';
$foundation_paid_wh = '77968d17-65bc-11e9-80cd-00155d4a0436';
$foundation_unknown_wh = 'c496b0c6-8e45-11df-bb3b-0019bbc941ce';
$branches_all = array(
	'branches' => array(
		array(
			'id' => 'br-1',
			'title' => 'Новосибирск',
			'timezone' => 'UTC+00:00',
			'divisions' => array(
				array(
					'id' => 'div-1',
					'name' => 'Division',
					'departmentTypeId' => 7,
					'departmentType' => 'Отделение компании',
					'warehouses' => array(
						array(
							'id' => $foundation_wh_1,
							'name' => 'Warehouse',
							'divisionName' => 'Склад Левый',
							'address' => 'short address',
							'addressDivision' => 'full address',
							'coordinatesobj' => array( 'latitude' => '55.1', 'longitude' => '82.9' ),
							'maxWeight' => 100,
							'maxVolume' => 2,
							'maxWeightPerPlace' => 50,
							'maxDimension' => 3,
							'endOfAvailabilityBeforeClosing' => '2026-08-03T00:00:00',
							'endOfCostCalculationAvailability' => '2026-08-03',
							'departmentClosingDate' => '2026-08-04T00:00:00+00:00',
							'kindsOfTransportation' => array( array( 'type' => 3, 'operations' => array( 'Выдача грузов', 'Прием грузов' ) ) ),
						),
					),
				),
			),
		),
	),
);
$nearest_foundation = array(
	'freeDepartments' => array(
		array(
			'warehouseId' => strtoupper( $foundation_wh_1 ),
			'branchId' => 'br-1',
			'branchName' => 'Новосибирск',
			'divisionName' => 'Склад Левый',
			'departmentTypeId' => 7,
			'departmentType' => 'Отделение компании',
			'address' => 'full address',
			'coordinates' => array( 'latitude' => '55.1', 'longitude' => '82.9' ),
			'maxWeight' => 100,
			'maxVolume' => 2,
			'maxWeightOnePlace' => 50,
			'maxDimension' => 3,
			'branchTimezone' => 'UTC+00:00',
			'endOfAvailabilityBeforeClosing' => '2026-08-03T00:00:00',
			'endOfCostCalculationAvailability' => '2026-08-03',
			'departmentClosingDate' => '2026-08-04T00:00:00+00:00',
		),
	),
	'paidDepartments' => array(),
);
$warehouse_service = new PekSenderWarehouseService( new PekApiClient( $settings, $credentials, new PekFakeHttp( array( pek_json_response( $nearest_foundation ) ) ), new PekRequestBudget( $settings ) ), $settings, new PekSenderWarehouseSearchCache() );
$warehouse_service->search( 'Россия, Новосибирск' );
$selected = $warehouse_service->validate_and_select( strtoupper( $foundation_wh_1 ) );
$snapshot = $settings->sender_warehouse();
pek_assert( $selected['success'] && $snapshot['branchName'] === 'Новосибирск' && $snapshot['departmentTypeId'] === 7 && $snapshot['address'] === 'full address' && $snapshot['coordinates']['latitude'] === '55.1' && $snapshot['limits']['maxWeightOnePlace'] === 50 && $snapshot['branchTimezone'] === 'UTC+00:00' && $snapshot['source'] === 'free', 'PEK nearestdepartments official sender shape must normalize branch title, division type, address, coordinates, limits, branch timezone and source.' );
pek_assert( ( $snapshot['availability']['endOfAvailabilityBeforeClosing'] ?? '' ) === '2026-08-03T00:00:00' && ( $snapshot['availability']['endOfCostCalculationAvailability'] ?? '' ) === '2026-08-03' && ( $snapshot['availability']['departmentClosingDate'] ?? '' ) === '2026-08-04T00:00:00+00:00', 'PEK sender warehouse snapshot must store compact availability/closing dates.' );
$previous = $snapshot;
foreach ( array(
	'past_order_availability' => array( 'endOfAvailabilityBeforeClosing', '2026-08-01T00:00:00' ),
	'past_cost_availability' => array( 'endOfCostCalculationAvailability', '2026-08-01T00:00:00' ),
	'past_closing' => array( 'departmentClosingDate', '2026-08-01' ),
	'invalid_date' => array( 'endOfAvailabilityBeforeClosing', '01 августа 2026' ),
) as $name => $case ) {
	$bad_date = $nearest_foundation;
	$bad_date_id = substr_replace( $foundation_wh_1, substr( hash( 'sha256', 'date-' . $name ), 0, 4 ), 14, 4 );
	$bad_date['freeDepartments'][0]['warehouseId'] = $bad_date_id;
	$bad_date['freeDepartments'][0][ $case[0] ] = $case[1];
	$service = new PekSenderWarehouseService( new PekApiClient( $settings, $credentials, new PekFakeHttp( array( pek_json_response( $bad_date ) ) ), new PekRequestBudget( $settings ) ), $settings, new PekSenderWarehouseSearchCache() );
	$service->search( 'Россия, Новосибирск' );
	$result = $service->validate_and_select( $bad_date_id );
	pek_assert( ! $result['success'] && $settings->sender_warehouse() !== array() && $settings->sender_warehouse()['warehouseId'] !== $bad_date_id, 'PEK nearestdepartments must reject unavailable warehouse date case ' . $name . ' and preserve previous selection.' );
}
$absent_dates = $nearest_foundation;
$absent_dates['freeDepartments'][0]['warehouseId'] = $foundation_paid_wh;
unset( $absent_dates['freeDepartments'][0]['endOfAvailabilityBeforeClosing'], $absent_dates['freeDepartments'][0]['endOfCostCalculationAvailability'], $absent_dates['freeDepartments'][0]['departmentClosingDate'] );
$service = new PekSenderWarehouseService( new PekApiClient( $settings, $credentials, new PekFakeHttp( array( pek_json_response( $absent_dates ) ) ), new PekRequestBudget( $settings ) ), $settings, new PekSenderWarehouseSearchCache() );
$service->search( 'Россия, Новосибирск' );
pek_assert( $service->validate_and_select( $foundation_paid_wh )['success'], 'PEK absent closing fields must not reject otherwise valid warehouse.' );
$current_previous = $settings->sender_warehouse();
$unknown = new PekSenderWarehouseService( new PekApiClient( $settings, $credentials, new PekFakeHttp( array( pek_json_response( $branches_all ) ) ), new PekRequestBudget( $settings ) ), $settings, new PekSenderWarehouseSearchCache() );
pek_assert( ! $unknown->validate_and_select( $foundation_unknown_wh )['success'] && $settings->sender_warehouse() === $current_previous, 'PEK unknown warehouse must be rejected and preserve previous selection.' );

$settings->save_sender_warehouse( array( 'warehouseId' => $foundation_cache_wh, 'source' => 'free', 'branchName' => 'Saved branch' ) );
$mismatch_previous_warehouse = $settings->sender_warehouse();
$all_200_mismatch_http = new PekFakeHttp( array(
	pek_json_response( array( array( 'type' => 3, 'name' => 'ПЭК:LTL Авто' ) ) ),
	pek_json_response( array(
		array( 'codeByClassifier' => '643', 'name' => 'РОССИЯ', 'shortName' => 'RU' ),
		array( 'codeByClassifier' => '051', 'name' => 'АРМЕНИЯ', 'shortName' => 'AM' ),
		array( 'codeByClassifier' => '112', 'name' => 'БЕЛАРУСЬ', 'shortName' => 'BY' ),
		array( 'codeByClassifier' => '417', 'name' => 'КИРГИЗИЯ', 'shortName' => 'KG' ),
		array( 'codeByClassifier' => '398', 'name' => 'КАЗАХСТАН', 'shortName' => 'KZ' ),
	) ),
	pek_json_response( array( array( 'name' => 'ООО' ) ) ),
	pek_json_response( array( 'branches' => array( array( 'divisions' => array( array( 'warehouses' => array( array( 'id' => $foundation_unknown_wh ) ) ) ) ) ) ) ),
) );
$all_200_mismatch = ( new PekConnectionDiagnosticService( $settings, $credentials, new PekApiClient( $settings, $credentials, $all_200_mismatch_http, new PekRequestBudget( $settings ) ) ) )->run();
pek_assert( $all_200_mismatch['connection_ok'] === true && $all_200_mismatch['success'] === true && $all_200_mismatch['all_checks_passed'] === true && $all_200_mismatch['message'] === 'Подключение ПЭК успешно проверено.', 'PEK all API endpoints 200 must pass connection and all_checks even when warehouse semantic match is informational warning.' );
pek_assert( ( $all_200_mismatch['checks']['warehouse_api']['status'] ?? '' ) === 'passed' && ( $all_200_mismatch['checks']['warehouse_api']['http_status'] ?? 0 ) === 200, 'PEK /branches/all HTTP 200 must produce passed warehouse_api.' );
pek_assert( ( $all_200_mismatch['checks']['warehouse_match']['status'] ?? '' ) === 'warning' && true === ( $all_200_mismatch['checks']['warehouse_match']['informational'] ?? false ) && false === ( $all_200_mismatch['checks']['warehouse_match']['affects_all_checks'] ?? true ) && false === ( $all_200_mismatch['checks']['warehouse_match']['warehouse_found'] ?? true ), 'PEK missing saved warehouse ID must produce informational warehouse_match warning only.' );
pek_assert( ( $all_200_mismatch['checks']['warehouse_match']['warehouses_checked'] ?? 0 ) === 1 && ( $all_200_mismatch['checks']['warehouse_match']['info_code'] ?? '' ) === 'pek_diagnostic_warehouse_not_matched' && ! isset( $all_200_mismatch['checks']['warehouse_match']['raw_response'] ), 'PEK warehouse_match mismatch must keep safe counters and no raw response.' );
pek_assert( $settings->sender_warehouse() === $mismatch_previous_warehouse, 'PEK warehouse diagnostics must not mutate selected sender warehouse on semantic mismatch.' );
pek_assert( ! str_contains( json_encode( $all_200_mismatch, JSON_UNESCAPED_UNICODE ) ?: '', $foundation_unknown_wh ), 'PEK diagnostic mismatch must not store raw unmatched warehouse rows.' );

$settings->save_sender_warehouse( array( 'warehouseId' => $foundation_cache_wh, 'source' => 'free' ) );
$positive_match_branches = array( 'branches' => array( array( 'divisions' => array( array( 'warehouses' => array( array( 'id' => strtoupper( $foundation_cache_wh ) ) ) ) ) ) ) );
$positive_match_http = new PekFakeHttp( array(
	pek_json_response( array( array( 'type' => 3 ) ) ),
	pek_json_response( array( array( 'codeByClassifier' => '643', 'name' => 'РОССИЯ', 'shortName' => 'RU' ) ) ),
	pek_json_response( array( array( 'name' => 'ООО' ) ) ),
	pek_json_response( $positive_match_branches ),
) );
$positive_match = ( new PekConnectionDiagnosticService( $settings, $credentials, new PekApiClient( $settings, $credentials, $positive_match_http, new PekRequestBudget( $settings ) ) ) )->run();
pek_assert( ( $positive_match['checks']['warehouse_match']['status'] ?? '' ) === 'passed' && true === ( $positive_match['checks']['warehouse_match']['warehouse_found'] ?? false ) && ( $positive_match['checks']['warehouse_match']['matched_field'] ?? '' ) === 'id' && ( $positive_match['checks']['warehouse_match']['matched_id'] ?? '' ) === $foundation_cache_wh, 'PEK warehouse_match must find official nested warehouses[].id after UUID case normalization.' );

foreach ( array(
	'empty' => array( 'response' => array( 'branches' => array() ), 'branches' => 0, 'divisions' => 0, 'warehouses' => 0 ),
	'branch_without_divisions' => array( 'response' => array( 'branches' => array( array( 'title' => 'No divisions' ) ) ), 'branches' => 1, 'divisions' => 0, 'warehouses' => 0 ),
	'division_without_warehouses' => array( 'response' => array( 'branches' => array( array( 'divisions' => array( array( 'name' => 'No warehouses' ) ) ) ) ), 'branches' => 1, 'divisions' => 1, 'warehouses' => 0 ),
	'non_array_rows' => array( 'response' => array( 'branches' => array( 'bad', array( 'divisions' => array( 'bad' ) ) ) ), 'branches' => 1, 'divisions' => 0, 'warehouses' => 0 ),
) as $name => $case ) {
	$match = PekSenderWarehouseService::find_warehouse_in_branches_response( $case['response'], $foundation_cache_wh );
	pek_assert( false === $match['warehouse_found'] && $match['branches_checked'] === $case['branches'] && $match['divisions_checked'] === $case['divisions'] && $match['warehouses_checked'] === $case['warehouses'], 'PEK warehouse matcher must safely count structure mismatch case ' . $name );
}
$unexpected_match = PekSenderWarehouseService::find_warehouse_in_branches_response( array( 'unexpected' => array() ), $foundation_cache_wh );
pek_assert( false === $unexpected_match['warehouse_found'] && true === $unexpected_match['unexpected_structure'], 'PEK warehouse matcher must mark unexpected top-level structure without warnings.' );

$settings->save_sender_warehouse( $snapshot );
$diagnostic_previous_warehouse = $settings->sender_warehouse();
$prod_like_http = new PekFakeHttp( array(
	array( 'status' => 403, 'body' => '{}' ),
	pek_json_response( array( array( 'codeByClassifier' => '643', 'name' => 'РОССИЯ', 'shortName' => 'RU' ) ) ),
	pek_json_response( array( array( 'name' => 'ООО', 'shortName' => 'ООО' ) ) ),
	pek_json_response( $branches_all ),
) );
$prod_like = ( new PekConnectionDiagnosticService( $settings, $credentials, new PekApiClient( $settings, $credentials, $prod_like_http, new PekRequestBudget( $settings ) ) ) )->run();
pek_assert( $prod_like['connection_ok'] === true && $prod_like['success'] === true && $prod_like['all_checks_passed'] === false, 'PEK products 403 plus warehouse success must confirm connection but not all checks.' );
pek_assert( $prod_like['ltl_product_type'] === null && $prod_like['legal_forms_available'] === true && $prod_like['countries_found'] === array( 'RU' ), 'PEK diagnostic must keep tri-state products and successful countries/legal forms after products 403.' );
pek_assert( ( $prod_like['checks']['products']['error_code'] ?? '' ) === 'pek_http_403' && ( $prod_like['checks']['products']['http_status'] ?? 0 ) === 403 && ( $prod_like['checks']['warehouse_api']['success'] ?? false ) === true, 'PEK diagnostic checks must preserve endpoint-specific HTTP 403 and continue to warehouse API.' );
pek_assert( array_column( $prod_like_http->requests, 'method' ) === array( 'GET', 'POST', 'POST', 'POST' ) && $settings->sender_warehouse() === $diagnostic_previous_warehouse, 'PEK diagnostic must execute all independent checks and must not mutate selected warehouse.' );
pek_assert( $prod_like['message'] === 'Подключение ПЭК частично работает. Некоторые API-проверки завершились ошибкой; подробности приведены ниже.', 'PEK production-like warning summary must be stable.' );

$settings->save_sender_warehouse( array() );
$no_warehouse_http = new PekFakeHttp( array(
	array( 'status' => 403, 'body' => '{}' ),
	pek_json_response( array( array( 'codeByClassifier' => '643', 'name' => 'РОССИЯ', 'shortName' => 'RU' ) ) ),
	pek_json_response( array( array( 'name' => 'ООО' ) ) ),
) );
$no_warehouse = ( new PekConnectionDiagnosticService( $settings, $credentials, new PekApiClient( $settings, $credentials, $no_warehouse_http, new PekRequestBudget( $settings ) ) ) )->run();
pek_assert( $no_warehouse['connection_ok'] && $no_warehouse['success'] && ! $no_warehouse['all_checks_passed'] && ( $no_warehouse['checks']['warehouse_api']['status'] ?? '' ) === 'skipped' && count( $no_warehouse_http->requests ) === 3, 'PEK diagnostic must skip warehouse API without selected warehouse and still confirm working connection from other endpoints.' );

$settings->save_sender_warehouse( $snapshot );
$no_ltl_http = new PekFakeHttp( array(
	pek_json_response( array( array( 'type' => 1 ) ) ),
	pek_json_response( array( array( 'codeByClassifier' => '643', 'name' => 'РОССИЯ', 'shortName' => 'RU' ) ) ),
	pek_json_response( array( array( 'name' => 'ООО' ) ) ),
	pek_json_response( $branches_all ),
) );
$no_ltl = ( new PekConnectionDiagnosticService( $settings, $credentials, new PekApiClient( $settings, $credentials, $no_ltl_http, new PekRequestBudget( $settings ) ) ) )->run();
pek_assert( $no_ltl['ltl_product_type'] === false && ( $no_ltl['checks']['products']['success'] ?? false ) === true && $no_ltl['connection_ok'], 'PEK products success without type=3 must set ltl_product_type=false without failing connection health.' );

$countries_403_http = new PekFakeHttp( array(
	pek_json_response( array( array( 'type' => 3 ) ) ),
	array( 'status' => 403, 'body' => '{}' ),
	pek_json_response( array( array( 'name' => 'ООО' ) ) ),
	pek_json_response( $branches_all ),
) );
$countries_403 = ( new PekConnectionDiagnosticService( $settings, $credentials, new PekApiClient( $settings, $credentials, $countries_403_http, new PekRequestBudget( $settings ) ) ) )->run();
pek_assert( $countries_403['connection_ok'] && $countries_403['countries_found'] === array() && $countries_403['planned_countries_missing'] === array(), 'PEK country endpoint failure must not declare planned countries missing when dictionary was unavailable.' );

$legal_403_http = new PekFakeHttp( array(
	pek_json_response( array( array( 'type' => 3 ) ) ),
	pek_json_response( array( array( 'codeByClassifier' => '643', 'name' => 'РОССИЯ', 'shortName' => 'RU' ) ) ),
	array( 'status' => 403, 'body' => '{}' ),
	pek_json_response( $branches_all ),
) );
$legal_403 = ( new PekConnectionDiagnosticService( $settings, $credentials, new PekApiClient( $settings, $credentials, $legal_403_http, new PekRequestBudget( $settings ) ) ) )->run();
pek_assert( $legal_403['connection_ok'] && $legal_403['legal_forms_available'] === null, 'PEK legal forms endpoint failure must produce tri-state null and keep warehouse-based connection health.' );

$warehouse_403_http = new PekFakeHttp( array(
	pek_json_response( array( array( 'type' => 3 ) ) ),
	pek_json_response( array( array( 'codeByClassifier' => '643', 'name' => 'РОССИЯ', 'shortName' => 'RU' ) ) ),
	pek_json_response( array( array( 'name' => 'ООО' ) ) ),
	array( 'status' => 403, 'body' => '{}' ),
) );
$warehouse_403 = ( new PekConnectionDiagnosticService( $settings, $credentials, new PekApiClient( $settings, $credentials, $warehouse_403_http, new PekRequestBudget( $settings ) ) ) )->run();
pek_assert( $warehouse_403['connection_ok'] && ! $warehouse_403['all_checks_passed'] && ( $warehouse_403['checks']['warehouse_api']['http_status'] ?? 0 ) === 403, 'PEK warehouse 403 must not hide successful country proof but must fail all_checks_passed.' );

$all_403_http = new PekFakeHttp( array(
	array( 'status' => 403, 'body' => '{}' ),
	array( 'status' => 403, 'body' => '{}' ),
	array( 'status' => 403, 'body' => '{}' ),
	array( 'status' => 403, 'body' => '{}' ),
) );
$all_403 = ( new PekConnectionDiagnosticService( $settings, $credentials, new PekApiClient( $settings, $credentials, $all_403_http, new PekRequestBudget( $settings ) ) ) )->run();
pek_assert( ! $all_403['connection_ok'] && ! $all_403['success'] && ! $all_403['all_checks_passed'], 'PEK diagnostic must fail connection health when all authenticated endpoints fail.' );

$saved_options_for_missing_credentials = $GLOBALS['pek_options'];
$GLOBALS['pek_options'] = array();
$missing_credentials_http = new PekFakeHttp( array( pek_json_response( array() ) ) );
$missing_credentials_settings = new PekSettings( new SettingsRepository(), new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer() );
$missing_credentials = new PekCredentials( new SettingsRepository(), new EncryptionService() );
$missing_result = ( new PekConnectionDiagnosticService( $missing_credentials_settings, $missing_credentials, new PekApiClient( $missing_credentials_settings, $missing_credentials, $missing_credentials_http, new PekRequestBudget( $missing_credentials_settings ) ) ) )->run();
pek_assert( ! $missing_result['connection_ok'] && ! $missing_result['success'] && count( $missing_credentials_http->requests ) === 0, 'PEK missing credentials diagnostic must not perform API requests.' );
$GLOBALS['pek_options'] = $saved_options_for_missing_credentials;

$cache = new PekSenderWarehouseSearchCache();
pek_assert( $cache->ttl_seconds() <= 900, 'PEK warehouse search cache TTL must be <= 15 minutes.' );
$search_payload = array(
	'freeDepartments' => array( array( 'warehouseId' => strtoupper( $foundation_cache_wh ), 'branchId' => 'cache-br', 'branchName' => 'Cache Branch', 'divisionName' => 'Cache Division', 'departmentTypeId' => 1, 'departmentType' => 'Type', 'address' => 'Cached address', 'branchTimezone' => 'UTC+03:00', 'endOfAvailabilityBeforeClosing' => '2026-08-03T00:00:00', 'endOfCostCalculationAvailability' => null, 'departmentClosingDate' => '2026-08-04T00:00:00+03:00' ) ),
	'paidDepartments' => array(),
);
$search_http = new PekFakeHttp( array( pek_json_response( $search_payload ), pek_json_response( $search_payload ), pek_json_response( $search_payload ) ) );
$search_service = new PekSenderWarehouseService( new PekApiClient( $settings, $credentials, $search_http, new PekRequestBudget( $settings ) ), $settings, $cache );
$search = $search_service->search( 'Россия, Новосибирск' );
pek_assert( $search['requested']['departmentOperation'] === 2 && $search['requested']['type'] === 3 && $search_service->validate_and_select( $foundation_cache_wh )['success'], 'PEK current user can select exact result from own fresh server search cache.' );
$cache_key_user_1 = $cache->key_for_current_user();
pek_assert( ( $cache->current_for_current_user()['items'][0]['branchTimezone'] ?? '' ) === 'UTC+03:00' && ( $cache->current_for_current_user()['items'][0]['source'] ?? '' ) === 'free' && ( $cache->current_for_current_user()['items'][0]['endOfAvailabilityBeforeClosing'] ?? '' ) === '2026-08-03T00:00:00' && ( $cache->current_for_current_user()['items'][0]['departmentClosingDate'] ?? '' ) === '2026-08-04T00:00:00+03:00', 'PEK warehouse search cache must preserve branch timezone, source and closing fields.' );
pek_assert( ( $settings->sender_warehouse()['source'] ?? '' ) === 'free', 'PEK sender warehouse snapshot must preserve freeDepartments source after cached selection.' );
pek_assert( ! str_contains( json_encode( $GLOBALS['pek_transients'][ $cache_key_user_1 ], JSON_UNESCAPED_UNICODE ) ?: '', 'secret-key' ), 'PEK warehouse search cache must not contain credentials.' );
$paid_payload = array(
	'freeDepartments' => array(),
	'paidDepartments' => array( array( 'warehouseId' => $foundation_paid_wh, 'branchId' => 'paid-br', 'branchName' => 'Paid Branch', 'divisionName' => 'Paid Division', 'departmentTypeId' => 1, 'departmentType' => 'Type', 'address' => 'Paid address', 'branchTimezone' => 'UTC+03:00' ) ),
);
$paid_cache = new PekSenderWarehouseSearchCache();
$paid_service = new PekSenderWarehouseService( new PekApiClient( $settings, $credentials, new PekFakeHttp( array( pek_json_response( $paid_payload ) ) ), new PekRequestBudget( $settings ) ), $settings, $paid_cache );
$paid_service->search( 'Россия, Томск' );
pek_assert( array() === ( $paid_cache->current_for_current_user()['items'] ?? array() ), 'PEK sender warehouse search must not expose paidDepartments for sender self-delivery.' );
$search_service->search( 'Россия, Новосибирск' );
pek_assert( array() !== $cache->current_for_current_user(), 'PEK successful warehouse search must store a current-user cache.' );
$empty_search = $search_service->search( '' );
pek_assert( ! $empty_search['success'] && array() === $cache->current_for_current_user(), 'PEK empty warehouse search must clear old current-user cache.' );
$search_service->search( 'Россия, Новосибирск' );
$previous_selected = $settings->sender_warehouse();
$failed_search_http = new PekFakeHttp( array( array( 'error' => true, 'message' => 'network failed' ), pek_json_response( array( 'branches' => array() ) ) ) );
$failed_search_service = new PekSenderWarehouseService( new PekApiClient( $settings, $credentials, $failed_search_http, new PekRequestBudget( $settings ) ), $settings, $cache );
try {
	$failed_search_service->search( 'Россия, Бердск' );
	pek_assert( false, 'PEK failed warehouse search must throw API exception.' );
} catch ( PekApiException ) {
}
pek_assert( array() === $cache->current_for_current_user(), 'PEK failed API warehouse search must clear old current-user cache.' );
pek_assert( ! $failed_search_service->select_from_cached_search( $foundation_cache_wh )['success'], 'PEK old warehouse ID must not be selectable from cache after failed search.' );
$fallback = $failed_search_service->validate_and_select( $foundation_cache_wh );
pek_assert( ! $fallback['success'] && count( $failed_search_http->requests ) === 1 && $settings->sender_warehouse() === $previous_selected, 'PEK old warehouse ID after failed search must not be rescued through branches/all and must preserve previous warehouse.' );
$GLOBALS['pek_current_user_id'] = 2;
$other_user_service = new PekSenderWarehouseService( new PekApiClient( $settings, $credentials, new PekFakeHttp( array( pek_json_response( $branches_all ) ) ), new PekRequestBudget( $settings ) ), $settings, $cache );
pek_assert( ! $other_user_service->select_from_cached_search( $foundation_cache_wh )['success'], 'PEK user B must not use user A search cache.' );
$GLOBALS['pek_current_user_id'] = 1;
$GLOBALS['pek_now'] += 901;
$expired_http = new PekFakeHttp( array( pek_json_response( $branches_all ) ) );
$expired_service = new PekSenderWarehouseService( new PekApiClient( $settings, $credentials, $expired_http, new PekRequestBudget( $settings ) ), $settings, $cache );
$expired_service->validate_and_select( $foundation_wh_1 );
pek_assert( count( $expired_http->requests ) === 0, 'PEK expired/missing cache must not fall back to branches/all validation.' );
$settings_repository->set( 'pek_last_warehouse_search', $search );
$GLOBALS['pek_transients'] = array();
$old_setting_service = new PekSenderWarehouseService( new PekApiClient( $settings, $credentials, new PekFakeHttp( array() ), new PekRequestBudget( $settings ) ), $settings, $cache );
pek_assert( ! $old_setting_service->validate_and_select( $foundation_cache_wh )['success'], 'PEK persistent SettingsRepository search must not authorize old warehouse selection.' );

$notice_store = new PekAdminNoticeStore();
$notice_store->save_for_current_user( 'not-real', "notice\nmessage" );
$notice_key_user_1 = $notice_store->key_for_current_user();
$GLOBALS['pek_current_user_id'] = 2;
pek_assert( array() === $notice_store->consume_for_current_user(), 'PEK admin notice must be user-scoped.' );
$GLOBALS['pek_current_user_id'] = 1;
$notice = $notice_store->consume_for_current_user();
pek_assert( $notice['type'] === 'info' && $notice['message'] === 'notice message' && array() === $notice_store->consume_for_current_user(), 'PEK admin notice must normalize type, clean control characters, and be one-shot.' );
pek_assert( (int) ( $GLOBALS['pek_transients'][ $notice_key_user_1 ]['expiration'] ?? $notice_store->ttl_seconds() ) <= 120 && $notice_store->ttl_seconds() <= 120, 'PEK admin notice TTL must be <= 120 seconds.' );
pek_assert( ! ( new ReflectionClass( PekSettings::class ) )->hasConstant( 'ADMIN_NOTICE_KEY' ) && array() === $settings_repository->get_array( 'pek_admin_notice', array() ), 'PEK admin notice must not be owned by PekSettings or persistent SettingsRepository.' );

$GLOBALS['wpdb'] = new wpdb();
$services = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
$countries = new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] );
$directory = ( new ReflectionClass( WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory::class ) )->newInstanceWithoutConstructor();
$manager = new DeliveryServiceManager( $services, $countries, new RuleRepository(), $directory );
$manager->ensure_builtin_services();
$pek_service = $services->find_by_service_key( PekSettings::SERVICE_KEY );
pek_assert( $pek_service instanceof DeliveryService && ! $pek_service->enabled && array( 'RU' ) === $countries->countries( (int) $pek_service->id ), 'PEK fresh setup must seed disabled service with only RU.' );
$manager->ensure_builtin_services();
pek_assert( array( 'RU' ) === $countries->countries( (int) $pek_service->id ), 'PEK repeated boot must keep existing RU.' );
$countries->replace_countries( (int) $pek_service->id, array( 'RU', 'KZ' ) );
$manager->ensure_builtin_services();
pek_assert( array( 'RU', 'KZ' ) === $countries->countries( (int) $pek_service->id ), 'PEK repeated ensure must not overwrite custom RU+KZ.' );
$countries->replace_countries( (int) $pek_service->id, array() );
$manager->ensure_builtin_services();
pek_assert( array() === $countries->countries( (int) $pek_service->id ), 'PEK explicit empty country selection must remain empty after boot.' );
$GLOBALS['wpdb']->services[ array_key_last( $GLOBALS['wpdb']->services ) ]['deleted'] = 1;
$manager->ensure_builtin_services();
$restored = $services->find_by_service_key( PekSettings::SERVICE_KEY );
pek_assert( $restored instanceof DeliveryService && array() === $countries->countries( (int) $restored->id ), 'PEK restored predefined service must not reseed RU merely because countries are empty.' );
pek_assert( $services->is_predefined_service_key( PekSettings::SERVICE_KEY ), 'PEK service must be predefined.' );

$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$warehouse_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Pek/Api/PekSenderWarehouseService.php' );
$shipment_manifest = (string) file_get_contents( dirname( __DIR__ ) . '/shipments/regression/shipment-regression-manifest.php' );
pek_assert( str_contains( $plugin_source, 'PekSenderWarehouseSearchCache::class' ) && str_contains( $plugin_source, 'PekAdminNoticeStore::class' ) && str_contains( $plugin_source, 'PekApiClient::class' ) && str_contains( $plugin_source, 'PekAdminPage::class' ), 'PEK DI/source wiring must be in Plugin.php.' );
pek_assert( ! str_contains( $warehouse_source, 'mb_strtolower' ) && ! str_contains( $warehouse_source, 'strtolower( $operation' ), 'PEK operation comparison must not depend on mbstring or strtolower for Cyrillic.' );
pek_assert( ! str_contains( $plugin_source, 'DateFramework' ) && ! str_contains( $plugin_source, 'WarehouseAvailabilityPolicy' ), 'PEK foundation must not register a generic date framework.' );
$carrier_registry_block = substr( $plugin_source, (int) strpos( $plugin_source, 'CarrierRegistry::class' ), 800 );
pek_assert( str_contains( $carrier_registry_block, 'PekCarrier::class' ), 'PEK checkout runtime must register PekCarrier in CarrierRegistry.' );
pek_assert( str_contains( $plugin_source, 'PekShipmentAdapter' ) && str_contains( $plugin_source, 'PekShipmentPersistenceMapper' ) && str_contains( $plugin_source, 'PekShipmentModalExtension' ) && str_contains( $plugin_source, 'PekShipmentDocumentProvider' ), 'PEK must be registered in Shipment Framework registries.' );
pek_assert( str_contains( $shipment_manifest, "'pek.foundation'" ) && str_contains( $shipment_manifest, "'pek.admin-routing'" ) && str_contains( $shipment_manifest, "'pek.admin-ui'" ) && str_contains( $shipment_manifest, "'pek.warehouse-datetime'" ) && str_contains( $shipment_manifest, "'pek.quote-foundation'" ) && str_contains( $shipment_manifest, "'pek.checkout-runtime'" ) && str_contains( $shipment_manifest, "'pek.shipment-create'" ) && str_contains( $shipment_manifest, "'pek.shipment-modal'" ) && str_contains( $shipment_manifest, "'pek.shipment-integration'" ), 'PEK mandatory smokes must be in shipment regression manifest.' );
$settings->save_diagnostic_result(
	array(
		'checked_at' => '2026-08-03 01:13:52',
		'checked_at_iso' => '2026-08-03T01:13:52+07:00',
		'phone' => '+7 999 123-45-67',
		'message' => 'Позвонить +7 999 123-45-67',
	)
);
$sanitized_report = $settings->last_diagnostic();
pek_assert( $sanitized_report['checked_at'] === '2026-08-03 01:13:52' && $sanitized_report['checked_at_iso'] === '2026-08-03T01:13:52+07:00', 'PEK diagnostic sanitation must not redact machine datetime values as phones.' );
pek_assert( $sanitized_report['phone'] === '[redacted-phone]' && $sanitized_report['message'] === 'Позвонить [redacted-phone]', 'PEK diagnostic sanitation must keep phone redaction for real phone numbers.' );
$redacted = $settings->last_diagnostic();
pek_assert( ! str_contains( json_encode( $redacted, JSON_UNESCAPED_UNICODE ) ?: '', 'secret-key' ), 'PEK normalized diagnostic must not contain API key.' );
pek_assert( count( $http->requests ) === 6 && count( $diag_http->requests ) === 3 && count( $prod_like_http->requests ) === 4, 'PEK smoke must use fake HTTP only and perform no production network calls.' );

echo "PEK foundation smoke OK\n";

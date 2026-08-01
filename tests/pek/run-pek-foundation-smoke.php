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
use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseService;
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

function current_time( string $type ): string { return '2026-08-02 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function sanitize_text_field( string $value ): string { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( $value ) ) ?? '' ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' ); }
function sanitize_email( string $value ): string { return trim( $value ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['pek_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool $autoload = true ): bool { $GLOBALS['pek_options'][ $option ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['pek_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { $GLOBALS['pek_transients'][ $key ] = $value; return true; }

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
		public function replace( string $table, array $data, array $format = array() ): bool {
			return $this->insert( $table, $data, $format );
		}
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
	public function post( string $url, array $args ): array {
		$this->requests[] = array( 'url' => $url, 'args' => $args );
		return array_shift( $this->responses ) ?? array( 'status' => 200, 'body' => '[]' );
	}
}

$GLOBALS['pek_options'] = array();
$GLOBALS['pek_transients'] = array();

$settings_repository = new SettingsRepository();
$settings = new PekSettings( $settings_repository );
$credentials = new PekCredentials( $settings_repository, new EncryptionService() );

pek_assert( PekSettings::CARRIER_KEY === 'pek' && PekSettings::SERVICE_KEY === 'pek', 'PEK keys must be stable.' );
pek_assert( PekSettings::LTL_PRODUCT_TYPE === 3, 'PEK LTL product type must be 3.' );
pek_assert( PekSettings::PLANNED_COUNTRIES === array( 'RU', 'AM', 'BY', 'KG', 'KZ' ), 'PEK planned countries must include RU/AM/BY/KG/KZ.' );
pek_assert( PekSettings::INITIAL_COUNTRIES === array( 'RU' ), 'PEK initial countries must be RU only.' );
pek_assert( PekSettings::COUNTRY_CLASSIFIER_CODES === array( 'RU' => '643', 'AM' => '051', 'BY' => '112', 'KG' => '417', 'KZ' => '398' ), 'PEK classifier codes must be centralized.' );
pek_assert( $settings->request_timeout() === 15 && $settings->request_soft_limit_per_minute() === 90, 'PEK settings defaults must be safe.' );
$settings->save_from_admin( array( PekSettings::REQUEST_TIMEOUT_KEY => 999, PekSettings::REQUESTS_PER_MINUTE_KEY => 999, PekSettings::SENDER_INN_KEY => ' 12-34 ', PekSettings::SENDER_PHONE_KEY => '+7 (999) 123-45-67', PekSettings::SENDER_EMAIL_KEY => 'sender@example.test', PekSettings::SENDER_REGISTRATION_COUNTRY_KEY => 'KZ', PekSettings::SMS_RELEASE_LIMIT_RUB_KEY => 0 ) );
pek_assert( $settings->request_timeout() === 60 && $settings->request_soft_limit_per_minute() === 100, 'PEK numeric settings must clamp.' );
pek_assert( $settings->sender_inn() === '1234' && $settings->sender_phone() === '+79991234567' && $settings->sender_registration_classifier_code() === '398', 'PEK sender settings must be normalized.' );
pek_assert( $settings->sms_release_limit_rub() === 1, 'PEK SMS limit must clamp lower bound.' );
$settings_repository->set( PekSettings::SMS_RELEASE_LIMIT_RUB_KEY, PekSettings::DEFAULT_SMS_RELEASE_LIMIT_RUB );
pek_assert( $settings->sms_release_limit_rub() === 500000, 'PEK SMS limit default must be 500000 rub.' );

pek_assert( ! $credentials->encryption_ready(), 'PEK credentials must detect missing APP_ENCRYPTION_KEY.' );
$preserve = $credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'secret' ) );
pek_assert( ! $preserve && ! $credentials->has_api_key(), 'PEK credentials must not save API key when encryption is unavailable.' );
define( 'APP_ENCRYPTION_KEY', 'pek-test-key' );
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'secret-key' ) );
pek_assert( $credentials->login() === 'login' && $credentials->api_key() === 'secret-key' && $credentials->has_api_key(), 'PEK encrypted key must save/read.' );
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login2', 'pek_api_key' => '' ) );
pek_assert( $credentials->login() === 'login2' && $credentials->api_key() === 'secret-key', 'Empty PEK password field must preserve key.' );
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login2', 'pek_clear_api_key' => '1' ) );
pek_assert( '' === $credentials->api_key() && ! $credentials->has_api_key(), 'PEK API key clear checkbox must clear key.' );
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'secret-key' ) );

$http = new PekFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( array( 'type' => 3 ) ) ) ) ) );
$api = new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) );
$args = $api->build_args_for_test( array( 'sample' => true ) );
pek_assert( str_starts_with( PekSettings::BASE_URL, 'https://' ) && $args['method'] === 'POST' && true === $args['sslverify'], 'PEK transport must use HTTPS POST with sslverify.' );
pek_assert( $args['headers']['Content-Type'] === 'application/json;charset=utf-8' && $args['headers']['Accept'] === 'application/json' && $args['headers']['Accept-Encoding'] === 'gzip', 'PEK JSON headers must match protocol.' );
pek_assert( $args['headers']['Authorization'] === 'Basic ' . base64_encode( 'login:secret-key' ) && $args['timeout'] === 60, 'PEK Basic Auth and timeout must be built from settings/credentials.' );
$api->types_of_delivery_all();
pek_assert( $http->requests[0]['url'] === PekSettings::BASE_URL . '/typesOfDelivery/all/', 'PEK types wrapper must call official endpoint.' );

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

$settings_repository->set( PekSettings::REQUESTS_PER_MINUTE_KEY, 1 );
$GLOBALS['pek_transients'] = array();
$limited_api = new PekApiClient( $settings, $credentials, new PekFakeHttp( array( array( 'status' => 200, 'body' => '[]' ), array( 'status' => 200, 'body' => '[]' ) ) ), new PekRequestBudget( $settings ) );
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
	array( 'status' => 200, 'body' => json_encode( array( array( 'type' => 3, 'name' => 'ПЭК:LTL Авто' ) ) ) ),
	array( 'status' => 200, 'body' => json_encode( array( array( 'code' => 'RU' ), array( 'code' => 'KZ' ) ) ) ),
	array( 'status' => 200, 'body' => json_encode( array( array( 'name' => 'ООО', 'shortName' => 'ООО' ) ) ) ),
) );
$diag = ( new PekConnectionDiagnosticService( $settings, $credentials, new PekApiClient( $settings, $credentials, $diag_http, new PekRequestBudget( $settings ) ) ) )->run();
pek_assert( $diag['success'] && $diag['ltl_product_type'] && $diag['countries_found'] === array( 'RU', 'KZ' ) && in_array( 'AM', $diag['planned_countries_missing'], true ), 'PEK diagnostic must normalize products/countries/legal forms without requiring all planned countries.' );

$warehouse_payload = array(
	'freeDepartments' => array(
		array( 'warehouseId' => 'wh-1', 'branchId' => 'br-1', 'branchName' => 'Новосибирск', 'divisionName' => 'Склад', 'departmentTypeId' => 0, 'departmentType' => 'Отделение компании', 'address' => 'Адрес', 'maxWeight' => 100, 'maxVolume' => 2, 'maxDimension' => 3, 'maxWeightPerPlace' => 50, 'maxCount' => 10 ),
	),
	'paidDepartments' => array(),
);
$warehouse_http = new PekFakeHttp( array( array( 'status' => 200, 'body' => json_encode( $warehouse_payload, JSON_UNESCAPED_UNICODE ) ) ) );
$warehouse_api = new PekApiClient( $settings, $credentials, $warehouse_http, new PekRequestBudget( $settings ) );
$warehouse_service = new PekSenderWarehouseService( $warehouse_api, $settings );
$search = $warehouse_service->search( 'Россия, Новосибирск' );
pek_assert( $search['success'] && $search['requested']['departmentOperation'] === 2 && $search['requested']['type'] === 3 && $search['items'][0]['warehouseId'] === 'wh-1' && $search['items'][0]['maxWeightOnePlace'] === 50, 'PEK sender warehouse search must normalize nearestdepartments with operation=2 and type=3.' );
$select = $warehouse_service->validate_and_select( 'wh-1' );
pek_assert( $select['success'] && $settings->sender_warehouse()['warehouseId'] === 'wh-1', 'PEK warehouse selection must use server-side cached result.' );
$previous = $settings->sender_warehouse();
$bad_select = $warehouse_service->validate_and_select( 'browser-injected' );
pek_assert( ! $bad_select['success'] && $settings->sender_warehouse() === $previous, 'PEK untrusted warehouse ID must be rejected and preserve existing warehouse on failure.' );

$GLOBALS['wpdb'] = new wpdb();
$services = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
$countries = new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] );
$pek_service = $services->ensure_pek_service();
pek_assert( $pek_service instanceof DeliveryService && ! $pek_service->enabled, 'PEK service seed must be disabled.' );
pek_assert( $services->is_predefined_service_key( PekSettings::SERVICE_KEY ), 'PEK service must be predefined.' );
$directory = ( new ReflectionClass( WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory::class ) )->newInstanceWithoutConstructor();
$manager = new DeliveryServiceManager( $services, $countries, new RuleRepository(), $directory );
$manager->ensure_builtin_services();
$pek_service = $services->find_by_service_key( PekSettings::SERVICE_KEY );
pek_assert( $pek_service instanceof DeliveryService && array( 'RU' ) === $countries->countries( (int) $pek_service->id ), 'PEK fresh setup must assign only RU.' );
$countries->replace_countries( (int) $pek_service->id, array( 'RU', 'KZ' ) );
$manager->ensure_builtin_services();
pek_assert( array( 'RU', 'KZ' ) === $countries->countries( (int) $pek_service->id ), 'PEK repeated ensure must not overwrite admin country choices.' );
$services->soft_delete_service( (int) $pek_service->id );
pek_assert( $services->find_by_service_key( PekSettings::SERVICE_KEY ) instanceof DeliveryService, 'PEK predefined service cannot be soft-deleted.' );

$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$shipment_manifest = (string) file_get_contents( dirname( __DIR__ ) . '/shipments/regression/shipment-regression-manifest.php' );
pek_assert( str_contains( $plugin_source, 'PekSettings::class' ) && str_contains( $plugin_source, 'PekApiClient::class' ) && str_contains( $plugin_source, 'PekAdminPage::class' ), 'PEK DI/source wiring must be in Plugin.php.' );
$carrier_registry_block = substr( $plugin_source, (int) strpos( $plugin_source, 'CarrierRegistry::class' ), 800 );
pek_assert( ! str_contains( $carrier_registry_block, 'Pek' ) && ! str_contains( $carrier_registry_block, "'pek'" ), 'PEK must not be registered in CarrierRegistry.' );
pek_assert( ! str_contains( $plugin_source, 'PekShipmentAdapter' ) && ! str_contains( $plugin_source, 'PekShipmentPersistenceMapper' ) && ! str_contains( $plugin_source, 'PekShipmentModalExtension' ) && ! str_contains( $plugin_source, 'PekShipmentDocumentProvider' ), 'PEK must not be registered in Shipment Framework registries.' );
pek_assert( str_contains( $admin_source, "'save_pek_settings'" ) && str_contains( $admin_source, 'render_pek_settings_tab' ), 'PEK admin actions must route through delivery service page.' );
pek_assert( str_contains( $shipment_manifest, "'pek.foundation'" ), 'PEK foundation smoke must be in mandatory shipment regression manifest.' );

$redacted = $settings->last_diagnostic();
pek_assert( ! str_contains( json_encode( $redacted, JSON_UNESCAPED_UNICODE ) ?: '', 'secret-key' ), 'PEK normalized diagnostic must not contain API key.' );
pek_assert( count( $diag_http->requests ) === 3 && count( $warehouse_http->requests ) === 2 && str_starts_with( (string) $warehouse_http->requests[1]['url'], PekSettings::BASE_URL ), 'PEK smoke must use fake HTTP only and perform no production network calls.' );

echo "PEK foundation smoke OK\n";

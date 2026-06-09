<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiResponse;
use WallsShop\WDC\Carriers\Cdek\Api\CdekHttpClientInterface;
use WallsShop\WDC\Carriers\Cdek\Api\CdekOAuthTokenService;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

function cdek_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-09 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'cdek-smoke-salt-' . $scheme; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_cdek_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_cdek_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_cdek_options'][ $key ] ); return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_cdek_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { $GLOBALS['wdc_cdek_transients'][ $key ] = $value; return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['wdc_cdek_transients'][ $key ] ); return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $services = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function insert( string $table, array $data, array $format = array() ): bool {
			$data['id'] = ++$this->insert_id;
			$this->services[] = $data;
			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			foreach ( $this->services as $index => $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === (int) ( $where['id'] ?? 0 ) ) {
					$this->services[ $index ] = array_merge( $row, $data );
				}
			}
			return true;
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			if ( preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( (string) $row['service_key'] === $matches[1] && ( str_contains( $query, 'ORDER BY deleted ASC' ) || empty( $row['deleted'] ) ) ) {
						return $row;
					}
				}
			}
			return null;
		}

		public function get_results( string $query, mixed $output = null ): array {
			if ( str_contains( $query, 'wdc_delivery_services' ) ) {
				return array_values( array_filter( $this->services, static fn ( array $row ): bool => empty( $row['deleted'] ) ) );
			}
			return array();
		}
	}
}

final class CdekFakeHttpClient implements CdekHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	public bool $network_failure = false;
	public int $status = 200;
	/** @var array<string,mixed> */
	public array $payload = array(
		'token_type' => 'bearer',
		'access_token' => 'fake-token',
		'expires_in' => 3600,
	);

	public function request( string $method, string $url, array $args = array() ): CdekApiResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		if ( $this->network_failure ) {
			throw new CdekApiException( 'Network unavailable' );
		}

		return new CdekApiResponse( $this->status, (string) json_encode( $this->payload ) );
	}
}

$GLOBALS['wdc_cdek_options'] = array();
$GLOBALS['wdc_cdek_transients'] = array();
$GLOBALS['wpdb'] = new wpdb();

$settings_repository = new SettingsRepository();
$encryption = new EncryptionService();
$settings = new CdekSettings( $settings_repository, $encryption );

cdek_smoke_assert( false === $settings->enabled(), 'CDEK must be disabled by default.' );
cdek_smoke_assert( CdekSettings::ENV_TEST === $settings->environment(), 'CDEK default environment must be test.' );
cdek_smoke_assert( 'https://api.edu.cdek.ru' === $settings->base_url(), 'CDEK test base URL mismatch.' );

$settings->save_from_admin(
	array(
		CdekSettings::ENABLED_KEY => '1',
		CdekSettings::ENVIRONMENT_KEY => CdekSettings::ENV_PRODUCTION,
		CdekSettings::ACCOUNT_KEY => 'account-1',
		'cdek_secure_password' => 'secret-1',
	)
);
cdek_smoke_assert( 'https://api.cdek.ru' === $settings->base_url(), 'CDEK production base URL mismatch.' );
cdek_smoke_assert( 'account-1' === $settings->credentials()->account, 'CDEK account must be read from settings.' );
cdek_smoke_assert( 'secret-1' === $settings->credentials()->secure_password, 'CDEK secret must decrypt from settings.' );

$settings->save_from_admin(
	array(
		CdekSettings::ENVIRONMENT_KEY => CdekSettings::ENV_TEST,
		CdekSettings::ACCOUNT_KEY => 'account-1',
		'cdek_secure_password' => '',
	)
);
cdek_smoke_assert( 'secret-1' === $settings->credentials()->secure_password, 'Empty password input must not clear saved CDEK secret.' );

$http = new CdekFakeHttpClient();
$tokens = new CdekOAuthTokenService( $settings, $http );
$token = $tokens->getToken();
cdek_smoke_assert( 'fake-token' === $token, 'CDEK OAuth token must be returned.' );
cdek_smoke_assert( 1 === count( $http->requests ), 'First CDEK getToken must call HTTP once.' );
$request = $http->requests[0];
cdek_smoke_assert( 'POST' === $request['method'], 'CDEK OAuth must use POST.' );
cdek_smoke_assert( 'https://api.edu.cdek.ru/v2/oauth/token' === $request['url'], 'CDEK OAuth endpoint mismatch.' );
parse_str( (string) ( $request['args']['body'] ?? '' ), $body );
cdek_smoke_assert( 'client_credentials' === ( $body['grant_type'] ?? '' ), 'CDEK OAuth grant_type mismatch.' );
cdek_smoke_assert( 'account-1' === ( $body['client_id'] ?? '' ), 'CDEK OAuth client_id mismatch.' );
cdek_smoke_assert( 'secret-1' === ( $body['client_secret'] ?? '' ), 'CDEK OAuth client_secret mismatch.' );

$second = $tokens->getToken();
cdek_smoke_assert( 'fake-token' === $second && 1 === count( $http->requests ), 'Second CDEK getToken must use cache.' );

$tokens->clearTokenCache();
$tokens->getToken();
cdek_smoke_assert( 2 === count( $http->requests ), 'clearTokenCache must force next HTTP request.' );

$tokens->clearTokenCache();
$http->payload['access_token'] = 'short-token';
$http->payload['expires_in'] = 30;
$tokens->getToken();
$tokens->getToken();
cdek_smoke_assert( 4 === count( $http->requests ), 'Token with expires_in below safety margin must expire immediately.' );

$tokens->clearTokenCache();
$http->status = 401;
$http->payload = array( 'error' => 'invalid_client', 'message' => 'bad secret-1' );
try {
	$tokens->getToken();
	cdek_smoke_assert( false, 'Bad CDEK credentials must throw controlled error.' );
} catch ( CdekApiException $exception ) {
	cdek_smoke_assert( str_contains( $exception->getMessage(), 'bad secret-1' ), 'CDEK API error message must be controlled.' );
}

$http->status = 200;
$http->payload = array( 'access_token' => 'network-token', 'expires_in' => 3600 );
$http->network_failure = true;
try {
	$tokens->clearTokenCache();
	$tokens->getToken();
	cdek_smoke_assert( false, 'CDEK network failure must throw controlled error.' );
} catch ( CdekApiException $exception ) {
	cdek_smoke_assert( str_contains( $exception->getMessage(), 'Network unavailable' ), 'CDEK network error mismatch.' );
}

$settings_repository->set( CdekSettings::ACCOUNT_KEY, '' );
$settings_repository->set( CdekSettings::SECURE_PASSWORD_ENCRYPTED_KEY, '' );
$http->network_failure = false;
try {
	$tokens->clearTokenCache();
	$tokens->getToken();
	cdek_smoke_assert( false, 'Missing CDEK credentials must throw controlled error.' );
} catch ( CdekApiException $exception ) {
	cdek_smoke_assert( str_contains( $exception->getMessage(), 'Заполните Account' ), 'Missing CDEK credentials message mismatch.' );
}

$settings_repository->set( CdekSettings::ACCOUNT_KEY, 'account-1' );
$settings_repository->set( CdekSettings::SECURE_PASSWORD_ENCRYPTED_KEY, $encryption->encrypt( 'secret-1' ) );
$settings->save_connection_result( false, 'Не удалось подключиться к СДЭК: token fake-token secret-1 account-1' );
cdek_smoke_assert( '2026-06-09 12:00:00' === $settings->last_connection_check(), 'CDEK connection check timestamp must be saved.' );
cdek_smoke_assert( 'error' === $settings->last_connection_status(), 'CDEK connection status must be saved.' );
cdek_smoke_assert( ! str_contains( $settings->last_connection_message(), 'secret-1' ), 'CDEK secret must not be exposed in diagnostics.' );
cdek_smoke_assert( ! str_contains( $settings->last_connection_message(), 'account-1' ), 'CDEK account must not be exposed in diagnostics.' );
cdek_smoke_assert( ! str_contains( $settings->last_connection_message(), 'fake-token' ), 'CDEK token-like text must not be exposed in diagnostics.' );

$services = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
$cdek_service = $services->ensure_cdek_service();
cdek_smoke_assert( CdekSettings::SERVICE_KEY === $cdek_service->service_key, 'CDEK service key mismatch.' );
cdek_smoke_assert( CdekSettings::CARRIER_KEY === $cdek_service->carrier_key, 'CDEK carrier key mismatch.' );
cdek_smoke_assert( false === $cdek_service->enabled, 'CDEK service must be disabled by default.' );
cdek_smoke_assert( $services->is_predefined_service_key( CdekSettings::SERVICE_KEY ), 'CDEK service must be predefined.' );

$registry = new CarrierRegistry();
cdek_smoke_assert( ! $registry->has( CdekSettings::CARRIER_KEY ), 'CDEK runtime carrier must not be registered in foundation smoke.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
cdek_smoke_assert( str_contains( $admin_source, 'check_cdek_connection' ), 'Admin page must expose CDEK connection check action.' );
cdek_smoke_assert( str_contains( $admin_source, "check_admin_referer( 'wdc_delivery_services' )" ), 'Admin CDEK action must be behind nonce check.' );
cdek_smoke_assert( str_contains( $admin_source, 'current_user_can( AdminMenu::CAPABILITY )' ), 'Admin CDEK action must be behind capability check.' );
cdek_smoke_assert( ! str_contains( $admin_source, 'secret-1' ) && ! str_contains( $admin_source, 'fake-token' ), 'Admin source must not contain test CDEK secrets or tokens.' );

echo "CDEK foundation smoke test passed.\n";

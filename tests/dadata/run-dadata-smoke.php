<?php
declare(strict_types=1);

use WallsShop\WDC\Checkout\Address\AddressQueryBuilder;
use WallsShop\WDC\Checkout\Address\DaDataAddressNormalizer;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\DaData\DaDataCredentials;
use WallsShop\WDC\Locations\DaData\DaDataHttpClient;
use WallsShop\WDC\Locations\DaData\DaDataLogger;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'test-dadata-encryption-key' );

$GLOBALS['wdc_dadata_options'] = array();
$GLOBALS['wdc_dadata_http_requests'] = array();
$GLOBALS['wdc_dadata_http_mode'] = 'success';

function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dadata_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dadata_options'][ $key ] = $value; return true; }
function __( string $text, string $domain = '' ): string { return $text; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags | JSON_UNESCAPED_UNICODE ); }
function is_wp_error( mixed $value ): bool { return is_object( $value ) && method_exists( $value, 'get_error_message' ); }
function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }
function wp_remote_post( string $url, array $args = array() ): array {
	$GLOBALS['wdc_dadata_http_requests'][] = array( 'url' => $url, 'args' => $args );
	if ( 'timeout' === $GLOBALS['wdc_dadata_http_mode'] ) {
		throw new RuntimeException( 'cURL error 28: Operation timed out' );
	}

	if ( 'failure' === $GLOBALS['wdc_dadata_http_mode'] ) {
		return array( 'response' => array( 'code' => 500 ), 'body' => '{}' );
	}

	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode(
			array(
				array(
					'postal_code'      => '630099',
					'region_with_type' => 'Новосибирская обл',
					'region_iso_code'  => 'RU-NVS',
					'city'             => 'Новосибирск',
					'street_with_type' => 'Красный пр-кт',
					'house'            => '25',
					'block'            => '',
					'result'           => '630099, Новосибирская обл, г Новосибирск, Красный пр-кт, д 25',
					'fias_id'          => 'dadata-fias-id',
					'qc'               => 0,
					'qc_complete'      => 0,
				),
			)
		),
	);
}

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {
		public string $id = '';
	}
}

final class WdcDaDataSmokeSession {
	private array $data = array();
	public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
	public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
	public function __unset( string $key ): void { unset( $this->data[ $key ] ); }
}

final class WdcDaDataSmokeWooCommerce {
	public WdcDaDataSmokeSession $session;
	public function __construct() { $this->session = new WdcDaDataSmokeSession(); }
}

function WC(): WdcDaDataSmokeWooCommerce {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new WdcDaDataSmokeWooCommerce();
	}
	return $wc;
}

final class WdcDaDataSmokeOrder {
	public array $meta = array();
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function dadata_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$settings = new SettingsRepository();
$credentials = new DaDataCredentials( $settings, new EncryptionService() );
$credentials->save_token( 'raw-dadata-token' );
$credentials->save_secret( 'raw-dadata-secret' );
$all_settings = $settings->all();
dadata_smoke_assert( isset( $all_settings['dadata_api_token_encrypted'], $all_settings['dadata_secret_key_encrypted'] ), 'Encrypted DaData credentials must be stored.' );
dadata_smoke_assert( 'raw-dadata-token' !== $all_settings['dadata_api_token_encrypted'], 'Stored DaData token must be encrypted.' );
dadata_smoke_assert( 'raw-dadata-secret' !== $all_settings['dadata_secret_key_encrypted'], 'Stored DaData secret must be encrypted.' );
dadata_smoke_assert( '********' === $credentials->masked_token() && '********' === $credentials->masked_secret(), 'DaData masked output must be stars only.' );
dadata_smoke_assert( ! str_contains( $credentials->masked_token(), 'raw-dadata-token' ), 'Raw token must not appear in masked token.' );
dadata_smoke_assert( ! str_contains( $credentials->masked_secret(), 'raw-dadata-secret' ), 'Raw secret must not appear in masked secret.' );

$credentials->save_token( '' );
$credentials->save_secret( '' );
dadata_smoke_assert( ! $credentials->has_token() && ! $credentials->has_secret(), 'Empty DaData credential saves must clear values.' );

$logger = new DaDataLogger( new Logger() );
$http = new DaDataHttpClient( 1, $logger );
$builder = new AddressQueryBuilder();
$normalizer = new DaDataAddressNormalizer( $settings, $credentials, $http, $builder );
$context = array(
	'country_code' => 'RU',
	'region_name'  => 'Новосибирская область',
	'city'         => 'Новосибирск',
	'postcode'     => '630000',
	'address_1'    => 'Красный проспект',
	'address_2'    => '25',
);

$settings->set( 'dadata_enabled', false );
$disabled = $normalizer->normalize( 'Новосибирск Красный проспект 25', $context );
dadata_smoke_assert( ! $disabled->success && 'dadata_disabled' === $disabled->error_code, 'Disabled DaData must fail with dadata_disabled.' );
dadata_smoke_assert( 0 === count( $GLOBALS['wdc_dadata_http_requests'] ), 'Disabled DaData must not call HTTP.' );

$settings->set( 'dadata_enabled', true );
$missing = $normalizer->normalize( 'Новосибирск Красный проспект 25', $context );
dadata_smoke_assert( ! $missing->success && 'dadata_credentials_missing' === $missing->error_code, 'Missing credentials must fail before HTTP.' );
dadata_smoke_assert( 0 === count( $GLOBALS['wdc_dadata_http_requests'] ), 'Missing credentials must not call HTTP.' );

$credentials->save_token( 'raw-dadata-token' );
$credentials->save_secret( 'raw-dadata-secret' );
$empty = $normalizer->normalize( '', array_merge( $context, array( 'address_2' => '' ) ) );
dadata_smoke_assert( ! $empty->success && 'dadata_empty_address' === $empty->error_code, 'Empty street/house must fail before HTTP.' );
dadata_smoke_assert( 0 === count( $GLOBALS['wdc_dadata_http_requests'] ), 'Empty address must not call HTTP.' );

$success = $normalizer->normalize( 'Новосибирск Красный проспект 25', $context );
dadata_smoke_assert( $success->success, 'Mocked DaData response must normalize successfully.' );
dadata_smoke_assert( 'dadata' === $success->source, 'DaData success source must be dadata.' );
dadata_smoke_assert( '630099' === $success->address->postcode, 'DaData postal_code must map to postcode.' );
dadata_smoke_assert( 'Красный пр-кт' === $success->address->street, 'DaData street_with_type must map to street.' );
dadata_smoke_assert( '25' === $success->address->house, 'DaData house must map to house.' );
dadata_smoke_assert( 'dadata-fias-id' === $success->address->fias_id, 'DaData fias_id must map to address FIAS id.' );
dadata_smoke_assert( 0.95 === $success->confidence, 'DaData qc=0/qc_complete=0 must map to high confidence.' );
$request = $GLOBALS['wdc_dadata_http_requests'][0] ?? array();
dadata_smoke_assert( ! str_contains( (string) ( $request['args']['body'] ?? '' ), 'raw-dadata-token' ), 'HTTP request body must not contain token.' );
dadata_smoke_assert( str_contains( (string) ( $request['args']['body'] ?? '' ), 'Красный проспект' ), 'HTTP request body must contain address query.' );

$GLOBALS['wdc_dadata_http_mode'] = 'timeout';
$timeout = $normalizer->normalize( 'Новосибирск Красный проспект 25', $context );
dadata_smoke_assert( ! $timeout->success && 'dadata_timeout' === $timeout->error_code, 'DaData timeout must return unsuccessful result.' );

$session = new CheckoutSessionManager();
$session->save_normalized_address_result( $success );
$session->save_city_context( array( 'source' => 'local_db', 'postcode' => '630000' ) );
$session->save_rates(
	array(
		'demo:courier' => array(
			'carrier_key'   => 'demo',
			'rate_id'       => 'demo:courier',
			'delivery_type' => 'courier',
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':demo:courier' ) );
$order = new WdcDaDataSmokeOrder();
( new OrderShippingMetaPersister( $session ) )->persist( $order );
dadata_smoke_assert( true === ( $order->meta['_wdc_platform_normalized'] ?? false ), 'DaData order meta must mark normalized=true.' );
dadata_smoke_assert( 'dadata' === ( $order->meta['_wdc_platform_normalization_source'] ?? '' ), 'DaData order meta must persist source=dadata.' );
dadata_smoke_assert( '630099' === ( $order->meta['_wdc_platform_resolved_postcode'] ?? '' ), 'DaData order meta must persist DaData postcode.' );
dadata_smoke_assert( 'dadata-fias-id' === ( $order->meta['_wdc_platform_fias_id'] ?? '' ), 'DaData order meta must persist FIAS id.' );

echo "DaData smoke test passed.\n";

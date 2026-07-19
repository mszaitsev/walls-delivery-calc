<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
define( 'WDC_SECRET_KEY', 'russian-post-documents-smoke-key' );
defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentDocumentProvider;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentDocumentService;

function rp_docs_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['rp_docs_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['rp_docs_options'][ $key ] = $value; return true; }
function sanitize_file_name( mixed $name ): string { return preg_replace( '/[^A-Za-z0-9._\-]/', '-', (string) $name ) ?? ''; }
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public function prepare( string $query, mixed ...$args ): string {
			unset( $args );
			return $query;
		}
		public function get_row( string $query, string $output = OBJECT ): mixed {
			unset( $query, $output );
			return $GLOBALS['rp_docs_service_row'] ?? null;
		}
		/** @return array<int,array<string,mixed>> */
		public function get_results( string $query, string $output = OBJECT ): array {
			unset( $query, $output );
			return $GLOBALS['rp_docs_service_settings'] ?? array();
		}
	}
}
$GLOBALS['wpdb'] = new wpdb();

final class RpDocsOrder {
	public function __construct( private int $id, private string $number ) {}
	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return $this->number; }
}

final class RpDocsDownloader {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();
	/** @param array<int,array<string,mixed>> $responses */
	public function __construct( private array $responses ) {}
	public function __invoke( string $url, string $type, string $token, string $basic, int $timeout ): array {
		$this->calls[] = compact( 'url', 'type', 'token', 'basic', 'timeout' );
		return array_shift( $this->responses ) ?? array( 'http_code' => 500, 'body' => '', 'content_type' => 'application/json' );
	}
}

function rp_docs_settings(): RussianPostOtpravkaApiSettings {
	$GLOBALS['rp_docs_options'] = array();
	$encryption = new EncryptionService();
	$GLOBALS['rp_docs_service_row'] = array(
		'id' => 1,
		'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
		'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
		'service_type' => 'api',
		'title' => RussianPostDomesticSettings::TITLE,
		'enabled' => 1,
		'availability_mode' => 'selected_countries',
		'use_default_rules_when_no_service_rules' => 1,
		'round_up_to_ruble' => 1,
		'minimum_price_rub' => 1,
		'include_packaging_weight' => 1,
		'packaging_weight_mode' => 'total_weight',
		'pickup_customer_comment' => '',
		'courier_customer_comment' => '',
		'sort_order' => 20,
		'deleted' => 0,
	);
	$GLOBALS['rp_docs_service_settings'] = array(
		array(
			'setting_key' => RussianPostOtpravkaApiSettings::ACCESS_TOKEN_KEY,
			'setting_value' => 'token-1',
			'value_format' => 'string',
		),
		array(
			'setting_key' => RussianPostOtpravkaApiSettings::LOGIN_KEY,
			'setting_value' => 'login-1',
			'value_format' => 'string',
		),
		array(
			'setting_key' => RussianPostOtpravkaApiSettings::PASSWORD_ENCRYPTED_KEY,
			'setting_value' => $encryption->encrypt( 'password-1' ),
			'value_format' => 'string',
		),
	);
	$settings = new SettingsRepository();
	$services = new DeliveryServiceRepository();
	$service_settings = new DeliveryServiceSettingsRepository();
	return new RussianPostOtpravkaApiSettings( $settings, $encryption, $services, $service_settings );
}
function rp_docs_shipment( array $override = array() ): array {
	return array_merge(
		array(
			'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
			'backlog_order_id' => '001234',
			'tracking_number' => '80000000000000',
		),
		$override
	);
}

$downloader = new RpDocsDownloader( array( array( 'http_code' => 200, 'body' => '%PDF-1.7 label', 'content_type' => 'application/pdf' ) ) );
$client = new RussianPostOtpravkaApiClient( rp_docs_settings(), $downloader );
$service = new RussianPostShipmentDocumentService( $client );
$provider = new RussianPostShipmentDocumentProvider( $service );
$order = new RpDocsOrder( 91, '91-A' );
$shipment = rp_docs_shipment();

$actions = $provider->actions( $order, $shipment );
rp_docs_assert( array() === $actions, 'Russian Post provider must temporarily hide postal label action in production.' );
rp_docs_assert( array() === $provider->actions( $order, rp_docs_shipment( array( 'backlog_order_id' => '' ) ) ), 'Russian Post shipment without backlog ID must hide label action.' );
rp_docs_assert( array() === $provider->actions( $order, rp_docs_shipment( array( 'batch_id' => 'B1' ) ) ), 'Russian Post shipment with explicit batch marker must hide label action.' );

$document = $provider->download( $order, $shipment, 'download_label' );
rp_docs_assert( '%PDF-1.7 label' === $document->body && 'application/pdf' === $document->content_type && 'pochta-rossii-91-A.pdf' === $document->filename, 'Valid Russian Post PDF must produce safe binary document.' );
rp_docs_assert( 1 === count( $downloader->calls ), 'Valid download must call Russian Post once.' );
rp_docs_assert( str_ends_with( $downloader->calls[0]['url'], '/1.0/forms/backlog/001234/forms' ), 'Russian Post forms endpoint must use persisted backlog ID with leading zeros preserved.' );
rp_docs_assert( 'BACKLOG_FORMS' === $downloader->calls[0]['type'] && 'token-1' === $downloader->calls[0]['token'] && base64_encode( 'login-1:password-1' ) === $downloader->calls[0]['basic'], 'Russian Post forms request must use existing Otpravka auth settings.' );

$missing_downloader = new RpDocsDownloader( array() );
$missing_service = new RussianPostShipmentDocumentService( new RussianPostOtpravkaApiClient( rp_docs_settings(), $missing_downloader ) );
try {
	$missing_service->download_label( $order, rp_docs_shipment( array( 'backlog_order_id' => '' ) ) );
	rp_docs_assert( false, 'Missing backlog ID must fail.' );
} catch ( RuntimeException ) {
}
rp_docs_assert( 0 === count( $missing_downloader->calls ), 'Missing backlog ID must not call Russian Post API.' );

foreach ( array(
	array( 'http_code' => 200, 'body' => '', 'content_type' => 'application/pdf', 'message' => 'empty body' ),
	array( 'http_code' => 400, 'body' => '{"message":"bad backlog"}', 'content_type' => 'application/json', 'message' => 'JSON error' ),
	array( 'http_code' => 200, 'body' => '<html>bad</html>', 'content_type' => 'text/html', 'message' => 'HTML response' ),
) as $case ) {
	$case_downloader = new RpDocsDownloader( array( $case ) );
	$case_service = new RussianPostShipmentDocumentService( new RussianPostOtpravkaApiClient( rp_docs_settings(), $case_downloader ) );
	try {
		$case_service->download_label( $order, $shipment );
		rp_docs_assert( false, $case['message'] . ' must fail.' );
	} catch ( RuntimeException ) {
	}
	rp_docs_assert( 1 === count( $case_downloader->calls ), $case['message'] . ' must perform exactly one API call.' );
}

$client_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/RussianPost/Otpravka/RussianPostOtpravkaApiClient.php' );
rp_docs_assert( str_contains( $client_source, "method' => 'GET'" ) && str_contains( $client_source, 'BACKLOG_FORMS_ENDPOINT' ) && str_contains( $client_source, "Content-Type' => 'application/json;charset=UTF-8'" ), 'Otpravka client must implement GET backlog forms with required headers.' );
rp_docs_assert( ! str_contains( $client_source, 'json_decode( $body, true );' . "\n\t\t" . 'if ( $code >= 200' ), 'Successful PDF path must not JSON-decode binary response.' );

$provider_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/RussianPost/RussianPostShipmentDocumentProvider.php' );
rp_docs_assert(
	str_contains( $provider_source, 'public function download' )
	&& str_contains( $provider_source, 'ACTION_DOWNLOAD_LABEL' )
	&& str_contains( $provider_source, '$this->service->download_label' )
	&& str_contains( $provider_source, 'Forbidden mail type' )
	&& str_contains( $provider_source, '/1.0/forms/backlog/{id}/forms' ),
	'Russian Post provider must hide action while keeping download implementation and API limitation comment.'
);

echo "Russian Post documents smoke passed.\n";

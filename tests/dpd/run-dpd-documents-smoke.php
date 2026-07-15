<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCredentials;
use WallsShop\WDC\Carriers\Dpd\DpdEndpoints;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSoapClientInterface;
use WallsShop\WDC\Carriers\Dpd\DpdSoapRequest;
use WallsShop\WDC\Carriers\Dpd\DpdSoapResponse;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentPayloadBuilder;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentAdapter;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentButtonPolicy;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentDocumentService;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function dpd_documents_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_documents_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_documents_options'][ $key ] = $value; return true; }
function sanitize_key( mixed $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?? ''; }
function sanitize_file_name( mixed $name ): string { return preg_replace( '/[^A-Za-z0-9._\-]/', '-', (string) $name ) ?? ''; }
function wp_generate_uuid4(): string { static $i = 0; $i++; return '00000000-0000-4000-8000-' . str_pad( (string) $i, 12, '0', STR_PAD_LEFT ); }
function get_temp_dir(): string { return sys_get_temp_dir() . DIRECTORY_SEPARATOR; }

final class DpdDocumentsFakeSoap implements DpdSoapClientInterface {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();
	/** @param array<int,array<string,mixed>|Throwable> $responses */
	public function __construct( private array $responses ) {}
	public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse {
		$this->calls[] = compact( 'service', 'method', 'payload', 'options' );
		$response = array_shift( $this->responses );
		if ( $response instanceof Throwable ) { throw $response; }
		return new DpdSoapResponse( true, $response ?? array(), array( 'service' => $service, 'method' => $method, 'wrapper' => $options['wrapper'] ?? '' ) );
	}
	public function is_available(): bool { return true; }
}

final class DpdDocumentsFakeOrder {
	/** @var array<string,mixed> */
	public array $meta = array();
	public int $save_count = 0;
	public function __construct( private int $id, array $shipment ) { $this->meta[ OrderShipmentRepository::META_KEY ] = array( DpdSettings::CARRIER_KEY => $shipment ); }
	public function get_id(): int { return $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void { $this->save_count++; }
}

function dpd_documents_settings(): DpdSettings { return new DpdSettings( new SettingsRepository(), new EncryptionService() ); }
function dpd_documents_shipment( array $override = array() ): array {
	return array_merge(
		array(
			'carrier_key' => DpdSettings::CARRIER_KEY,
			'status' => 'created',
			'dpd_order_number' => '05120002MOW',
			'dpd_event_code' => '1401',
			'dpd_event_marker' => 'OrderCreate',
			'tracking_checked_at' => '2026-06-22 10:00:00',
			'dpd_sent_places' => array( array( 'number' => 1 ), array( 'number' => 2 ) ),
		),
		$override
	);
}
function dpd_documents_service( DpdDocumentsFakeSoap $soap ): DpdShipmentDocumentService {
	return new DpdShipmentDocumentService( new OrderShipmentRepository(), new DpdApiClient( dpd_documents_settings(), $soap ) );
}
function dpd_documents_pdf( string $label ): string { return '%PDF-1.4\n%' . $label . '\n'; }
function dpd_documents_temp_glob(): array { return glob( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-dpd-documents-*' ) ?: array(); }

$adapter = new DpdShipmentAdapter( new DpdShipmentPayloadBuilder( dpd_documents_settings() ), null, null, new DpdShipmentButtonPolicy() );
$payload_1401 = $adapter->status_payload( new stdClass(), dpd_documents_shipment() );
dpd_documents_assert( ! empty( $payload_1401['can_cancel'] ) && empty( $payload_1401['can_remove_from_order'] ) && ! empty( $payload_1401['can_download_dpd_documents'] ), 'Initial DPD payload for 1401 must show cancel/download and hide remove.' );
$payload_1301 = $adapter->status_payload( new stdClass(), dpd_documents_shipment( array( 'dpd_event_code' => '1301' ) ) );
dpd_documents_assert( empty( $payload_1301['can_cancel'] ) && ! empty( $payload_1301['can_remove_from_order'] ) && empty( $payload_1301['can_download_dpd_documents'] ), 'Initial DPD payload for 1301 must show remove and hide cancel/download.' );
dpd_documents_assert( 'Скачать документы' === (string) $adapter->label_actions( new stdClass(), dpd_documents_shipment() )[0]['label'], 'Button must be visible for DPD 1401 with order number.' );
dpd_documents_assert( array() === $adapter->label_actions( new stdClass(), dpd_documents_shipment( array( 'dpd_event_code' => '1001' ) ) ), 'Button must be hidden for DPD 1001.' );
dpd_documents_assert( array() === $adapter->label_actions( new stdClass(), dpd_documents_shipment( array( 'dpd_event_code' => '1501' ) ) ), 'Button must be hidden for DPD 1501.' );
foreach ( array( 'delivered', 'cancelled', 'unknown', '2201', '' ) as $code ) {
	dpd_documents_assert( array() === $adapter->label_actions( new stdClass(), dpd_documents_shipment( array( 'dpd_event_code' => $code ) ) ), 'Button must be hidden for non-1401 status ' . $code . '.' );
}

$soap = new DpdDocumentsFakeSoap( array( array( 'file' => dpd_documents_pdf( 'invoice' ) ), array( 'file' => base64_encode( dpd_documents_pdf( 'label' ) ), 'order' => array( 'orderNum' => '05120002MOW', 'status' => 'OrderPending' ) ) ) );
$order = new DpdDocumentsFakeOrder( 77, dpd_documents_shipment() );
$before_meta = $order->meta;
$before_temp = dpd_documents_temp_glob();
$result = dpd_documents_service( $soap )->create_zip_for_order( $order );
dpd_documents_assert( ! empty( $result['success'] ) && is_file( (string) $result['path'] ), 'Two PDFs must create a ZIP file.' );
dpd_documents_assert( 'dpd-documents-order-77-05120002MOW.zip' === (string) $result['filename'], 'ZIP filename must include order id and DPD order number.' );
dpd_documents_assert( 2 === count( $soap->calls ), 'Document service must call invoice and label endpoints.' );
dpd_documents_assert( DpdEndpoints::SERVICE_ORDER === $soap->calls[0]['service'] && 'getInvoiceFile' === $soap->calls[0]['method'], 'Invoice must use order2/getInvoiceFile.' );
dpd_documents_assert( ! array_key_exists( 'parcelCount', $soap->calls[0]['payload'] ) && ! array_key_exists( 'cargoValue', $soap->calls[0]['payload'] ), 'getInvoiceFile payload must not contain parcelCount or cargoValue.' );
dpd_documents_assert( '05120002MOW' === (string) ( $soap->calls[0]['payload']['orderNum'] ?? '' ), 'getInvoiceFile payload must contain DPD orderNum.' );
dpd_documents_assert( DpdEndpoints::SERVICE_LABEL_PRINT === $soap->calls[1]['service'] && 'createLabelFile' === $soap->calls[1]['method'], 'Label must use label-print/createLabelFile.' );
dpd_documents_assert( DpdSoapRequest::WRAPPER_GET_LABEL_FILE === (string) ( $soap->calls[1]['options']['wrapper'] ?? '' ), 'Label payload must use getLabelFile wrapper.' );
dpd_documents_assert( 'PDF' === (string) ( $soap->calls[1]['payload']['fileFormat'] ?? '' ), 'Label payload must request PDF.' );
dpd_documents_assert( 'A6' === (string) ( $soap->calls[1]['payload']['pageSize'] ?? '' ), 'Label payload must request A6.' );
dpd_documents_assert( 2 === (int) ( $soap->calls[1]['payload']['order']['parcelsNumber'] ?? 0 ), 'Label payload must request one label per sent DPD place.' );
$zip = new ZipArchive();
dpd_documents_assert( true === $zip->open( (string) $result['path'] ), 'Created ZIP must be readable.' );
$names = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) { $names[] = (string) $zip->getNameIndex( $i ); }
sort( $names );
dpd_documents_assert( array( 'dpd-invoice-05120002MOW.pdf', 'dpd-label-a6-05120002MOW.pdf' ) === $names, 'ZIP must contain exactly invoice and A6 label PDFs.' );
$zip->close();
dpd_documents_assert( $before_meta === $order->meta && 0 === $order->save_count, 'Downloading documents must not change shipment status or order meta.' );
dpd_documents_service( new DpdDocumentsFakeSoap( array() ) )->delete_temp_file( (string) $result['path'] );
dpd_documents_assert( ! is_file( (string) $result['path'] ), 'Temporary ZIP must be deletable after download.' );
dpd_documents_assert( $before_temp === dpd_documents_temp_glob(), 'Temporary files must be cleaned after the smoke success cleanup.' );

$invoice_error = dpd_documents_service( new DpdDocumentsFakeSoap( array( array( 'errorMessage' => 'invoice failed' ) ) ) )->create_zip_for_order( new DpdDocumentsFakeOrder( 78, dpd_documents_shipment() ) );
dpd_documents_assert( empty( $invoice_error['success'] ) && str_contains( (string) $invoice_error['message'], 'invoice failed' ), 'Invoice error must prevent ZIP creation.' );
$label_error = dpd_documents_service( new DpdDocumentsFakeSoap( array( array( 'file' => dpd_documents_pdf( 'invoice' ) ), array( 'order' => array( 'errorMessage' => 'label failed' ) ) ) ) )->create_zip_for_order( new DpdDocumentsFakeOrder( 79, dpd_documents_shipment() ) );
dpd_documents_assert( empty( $label_error['success'] ) && str_contains( (string) $label_error['message'], 'label failed' ), 'Label error must prevent ZIP creation.' );
$date_error = dpd_documents_service( new DpdDocumentsFakeSoap( array( array( 'file' => dpd_documents_pdf( 'invoice' ) ), array( 'order' => array( 'errorMessage' => 'У заказа дата забора ранее текущей даты' ) ) ) ) )->create_zip_for_order( new DpdDocumentsFakeOrder( 80, dpd_documents_shipment() ) );
dpd_documents_assert( empty( $date_error['success'] ) && DpdShipmentDocumentService::PICKUP_DATE_ERROR === (string) $date_error['message'], 'Pickup-date label error must return the administrator-friendly text.' );
$empty_pdf = dpd_documents_service( new DpdDocumentsFakeSoap( array( array( 'file' => '' ) ) ) )->create_zip_for_order( new DpdDocumentsFakeOrder( 81, dpd_documents_shipment() ) );
dpd_documents_assert( empty( $empty_pdf['success'] ) && str_contains( (string) $empty_pdf['message'], 'пустой файл' ), 'Empty PDF response must be an error.' );
$not_pdf = dpd_documents_service( new DpdDocumentsFakeSoap( array( array( 'file' => 'not-pdf' ) ) ) )->create_zip_for_order( new DpdDocumentsFakeOrder( 82, dpd_documents_shipment() ) );
dpd_documents_assert( empty( $not_pdf['success'] ) && str_contains( (string) $not_pdf['message'], 'не PDF-файл' ), 'Non-PDF response must be an error.' );
$no_number = dpd_documents_service( new DpdDocumentsFakeSoap( array() ) )->create_zip_for_order( new DpdDocumentsFakeOrder( 83, dpd_documents_shipment( array( 'dpd_order_number' => '' ) ) ) );
dpd_documents_assert( empty( $no_number['success'] ) && str_contains( (string) $no_number['message'], 'номер заказа DPD' ), 'Missing DPD order number must be an error.' );
$bad_status = dpd_documents_service( new DpdDocumentsFakeSoap( array() ) )->create_zip_for_order( new DpdDocumentsFakeOrder( 84, dpd_documents_shipment( array( 'dpd_event_code' => '1501' ) ) ) );
dpd_documents_assert( empty( $bad_status['success'] ) && str_contains( (string) $bad_status['message'], '1401' ), 'Non-1401 status must deny document access.' );
dpd_documents_assert( str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.js' ), 'requestDpdDocumentsDownload' ), 'Admin JS must include DPD document ZIP download flow.' );
$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
dpd_documents_assert( str_contains( $metabox_source, 'ShipmentDocumentDownloadService' ) && ! str_contains( $metabox_source, 'ACTION_DPD_DOCUMENTS_ZIP' ) && ! str_contains( $metabox_source, 'admin_post_dpd_documents_zip' ), 'Metabox must expose DPD documents through the common shipment document endpoint.' );
dpd_documents_assert( str_contains( $metabox_source, 'if ( $is_cdek || $is_dpd )' ), 'Initial metabox render must use DPD status payload for cancel/remove button state.' );

echo "DPD documents smoke passed\n";

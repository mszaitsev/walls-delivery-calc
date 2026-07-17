<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Shipments\Documents\CarrierShipmentDocumentProviderInterface;
use WallsShop\WDC\Shipments\Documents\ShipmentBinaryDocument;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentAction;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentDownloadService;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentProviderRegistry;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function shipment_docs_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function sanitize_key( mixed $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ?? '' ); }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
function admin_url( string $path = '' ): string { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function wp_create_nonce( string $action ): string { return 'nonce-' . $action; }

final class ShipmentDocsOrder {
	/** @var array<string,mixed> */
	public array $meta = array();
	public function __construct( private int $id, array $shipments ) { $this->meta[ OrderShipmentRepository::META_KEY ] = $shipments; }
	public function get_id(): int { return $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
}

final class ShipmentDocsProvider implements CarrierShipmentDocumentProviderInterface {
	public int $downloads = 0;
	public function __construct( private string $key = 'test_carrier', private bool $visible = true ) {}
	public function carrier_key(): string { return $this->key; }
	public function actions( object $order, array $shipment ): array {
		unset( $order, $shipment );
		return $this->visible ? array( new ShipmentDocumentAction( 'download_label', 'Скачать ярлык' ) ) : array();
	}
	public function download( object $order, array $shipment, string $action_key ): ShipmentBinaryDocument {
		unset( $order, $shipment );
		$this->downloads++;
		if ( 'download_label' !== $action_key ) {
			throw new RuntimeException( 'unknown action' );
		}
		return new ShipmentBinaryDocument( '%PDF-1.4 test', 'application/pdf', 'label.pdf' );
	}
}

$action = new ShipmentDocumentAction( 'download_label', 'Скачать ярлык' );
shipment_docs_assert( array( 'key' => 'download_label', 'label' => 'Скачать ярлык', 'type' => 'download', 'visible' => true, 'data' => array() ) === $action->to_array(), 'Document action must normalize to expected array shape.' );
foreach ( array( array( '', 'Label' ), array( 'key', '' ) ) as $invalid ) {
	try {
		new ShipmentDocumentAction( $invalid[0], $invalid[1] );
		shipment_docs_assert( false, 'Invalid action must be rejected.' );
	} catch ( InvalidArgumentException ) {
	}
}

new ShipmentBinaryDocument( '%PDF-1.4', 'application/pdf', 'a.pdf' );
new ShipmentBinaryDocument( "PK\x03\x04zip", 'application/zip', 'a.zip' );
foreach ( array(
	array( '', 'application/pdf', 'a.pdf' ),
	array( 'body', '', 'a.pdf' ),
	array( 'body', 'application/pdf', '../a.pdf' ),
	array( 'body', 'application/pdf', "bad\n.pdf" ),
) as $invalid ) {
	try {
		new ShipmentBinaryDocument( $invalid[0], $invalid[1], $invalid[2] );
		shipment_docs_assert( false, 'Invalid binary document must be rejected.' );
	} catch ( InvalidArgumentException ) {
	}
}

$provider = new ShipmentDocsProvider();
$registry = new ShipmentDocumentProviderRegistry( array( $provider ) );
shipment_docs_assert( $provider === $registry->get( 'test_carrier' ) && array( 'test_carrier' ) === $registry->keys(), 'Registry must resolve registered provider by carrier key.' );
shipment_docs_assert( null === $registry->get( 'unknown' ), 'Unknown provider must resolve to null.' );
try {
	new ShipmentDocumentProviderRegistry( array( new ShipmentDocsProvider(), new ShipmentDocsProvider() ) );
	shipment_docs_assert( false, 'Duplicate provider key must be rejected.' );
} catch ( InvalidArgumentException ) {
}
try {
	new ShipmentDocumentProviderRegistry( array( new ShipmentDocsProvider( '' ) ) );
	shipment_docs_assert( false, 'Empty provider key must be rejected.' );
} catch ( InvalidArgumentException ) {
}

$order = new ShipmentDocsOrder( 55, array( 'test_carrier' => array( 'carrier_key' => 'test_carrier', 'tracking_number' => 'T1' ) ) );
$service = new ShipmentDocumentDownloadService( new OrderShipmentRepository(), $registry );
$url = $service->download_url( 55, 'test_carrier', 'download_label' );
shipment_docs_assert( str_contains( $url, 'action=wdc_download_shipment_document' ) && str_contains( $url, 'carrier_key=test_carrier' ) && str_contains( $url, 'action_key=download_label' ) && str_contains( $url, '_wdc_nonce=' ), 'Download URL must use the common protected admin-post action.' );
$document = $service->download_for_order( $order, 'test_carrier', 'download_label' );
shipment_docs_assert( '%PDF-1.4 test' === $document->body && 1 === $provider->downloads, 'Download service must pass persisted shipment to provider and return binary document.' );

$hidden_provider = new ShipmentDocsProvider( 'hidden_carrier', false );
$hidden_service = new ShipmentDocumentDownloadService( new OrderShipmentRepository(), new ShipmentDocumentProviderRegistry( array( $hidden_provider ) ) );
try {
	$hidden_service->download_for_order( new ShipmentDocsOrder( 56, array( 'hidden_carrier' => array( 'carrier_key' => 'hidden_carrier' ) ) ), 'hidden_carrier', 'download_label' );
	shipment_docs_assert( false, 'Hidden action direct URL must be forbidden.' );
} catch ( RuntimeException ) {
}
shipment_docs_assert( 0 === $hidden_provider->downloads, 'Forbidden direct URL must not call provider API.' );

foreach ( array(
	'src/Shipments/Admin/OrderShipmentsMetabox.php',
	'src/Shipments/Documents/ShipmentDocumentDownloadService.php',
) as $common_file ) {
	$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $common_file );
	foreach ( array( 'generate-labels', 'createLabelFile', 'getInvoiceFile', '/forms/backlog/' ) as $forbidden ) {
		shipment_docs_assert( ! str_contains( $source, $forbidden ), $common_file . ' must not contain carrier document implementation detail: ' . $forbidden );
	}
}
$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
shipment_docs_assert( str_contains( $metabox_source, 'document_actions_for_carrier' ) && str_contains( $metabox_source, 'render_document_action_links' ), 'Metabox must render document actions through the common normalized contract.' );
$legacy_document_payload_key = 'label_' . 'actions';
shipment_docs_assert( str_contains( $metabox_source, 'document_actions' ) && ! str_contains( $metabox_source, $legacy_document_payload_key ), 'Metabox payload must use canonical document_actions key and no legacy document payload alias.' );
shipment_docs_assert( ! str_contains( $metabox_source, 'admin_post_cdek_barcode_pdf' ) && ! str_contains( $metabox_source, 'admin_post_dpd_documents_zip' ) && ! str_contains( $metabox_source, 'admin_post_yandex_label_pdf' ), 'Old per-carrier admin-post handlers must be removed from metabox.' );

echo "Shipment document actions smoke passed.\n";

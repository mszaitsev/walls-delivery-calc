<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function pek_docs_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$provider = file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Pek/PekShipmentDocumentProvider.php' ) ?: '';
$service = file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Pek/PekShipmentDocumentService.php' ) ?: '';
$adapter = file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Pek/PekShipmentAdapter.php' ) ?: '';
$fixture = json_decode( file_get_contents( dirname( __DIR__ ) . '/pek/fixtures/order-print-response.json' ) ?: '', true );

pek_docs_assert( class_exists( WallsShop\WDC\Shipments\Pek\PekShipmentDocumentProvider::class ), 'PEK document provider must exist.' );
pek_docs_assert( str_contains( $provider, 'download_application' ), 'Application document action must exist.' );
pek_docs_assert( str_contains( $provider, 'download_label' ), 'Single label document action must exist.' );
pek_docs_assert( str_contains( $provider, 'download_all_labels' ), 'All labels document action must exist.' );
pek_docs_assert( str_contains( $service, "order_print" ) && str_contains( $service, "'%PDF-'" ), 'Document service must call order_print and validate PDF magic.' );
pek_docs_assert( is_array( $fixture ) && 1 === count( $fixture ) && str_starts_with( base64_decode( (string) reset( $fixture ), true ) ?: '', '%PDF-' ), 'Official order/print fixture must be one-value base64 PDF object.' );
pek_docs_assert( ! str_contains( $adapter, 'document_actions' ) && ! str_contains( $adapter, 'ShipmentBinaryDocument' ), 'Adapter must not own document actions or stream documents.' );

$provider_instance = ( new ReflectionClass( WallsShop\WDC\Shipments\Pek\PekShipmentDocumentProvider::class ) )->newInstanceWithoutConstructor();
$actions = $provider_instance->actions(
	new stdClass(),
	array(
		'pek_cargo_code' => '999940950644',
		'pek_position_barcodes' => array( 'p1', 'p2', 'p3', 'p4', 'p5' ),
	)
);
$keys = array_map( static fn( object $action ): string => $action->key, $actions );
pek_docs_assert( in_array( 'download_application', $keys, true ) && in_array( 'download_label', $keys, true ), 'One PEK cargo must expose application and simple label actions.' );
pek_docs_assert( ! in_array( 'download_all_labels', $keys, true ), 'Multiple position barcodes of one cargo must not expose type=multiple.' );

echo "PEK shipment documents smoke passed.\n";

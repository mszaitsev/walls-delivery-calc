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

pek_docs_assert( class_exists( WallsShop\WDC\Shipments\Pek\PekShipmentDocumentProvider::class ), 'PEK document provider must exist.' );
pek_docs_assert( str_contains( $provider, 'download_application' ), 'Application document action must exist.' );
pek_docs_assert( str_contains( $provider, 'download_label' ), 'Single label document action must exist.' );
pek_docs_assert( str_contains( $provider, 'download_all_labels' ), 'All labels document action must exist.' );
pek_docs_assert( str_contains( $service, "order_print" ) && str_contains( $service, "'%PDF-'" ), 'Document service must call order_print and validate PDF magic.' );
pek_docs_assert( ! str_contains( $adapter, 'document_actions' ) && ! str_contains( $adapter, 'ShipmentBinaryDocument' ), 'Adapter must not own document actions or stream documents.' );

echo "PEK shipment documents smoke passed.\n";

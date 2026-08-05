<?php
declare(strict_types=1);

function pek_cancel_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$root = dirname( __DIR__, 2 );
$service = file_get_contents( $root . '/src/Shipments/Pek/PekShipmentService.php' ) ?: '';
$api = file_get_contents( $root . '/src/Carriers/Pek/Api/PekApiClient.php' ) ?: '';
$all_php = '';
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) );
foreach ( $iterator as $file ) {
	if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
		$all_php .= "\n" . ( file_get_contents( $file->getPathname() ) ?: '' );
	}
}

pek_cancel_assert( str_contains( $service, 'order_cancellation' ), 'Cancellation must use /order/cancellation/ wrapper.' );
pek_cancel_assert( str_contains( $api, '/order/cancellation/' ), 'PEK API client must expose order cancellation.' );
pek_cancel_assert( str_contains( $service, 'time() - $created >= 600' ), 'Cancellation must wait at least 10 minutes.' );
pek_cancel_assert( str_contains( $service, '$this->statuses->fetch' ), 'Cancellation must fresh-check status before API call.' );
pek_cancel_assert( str_contains( $service, 'pek_take_on_stock_datetime' ), 'Cancellation must inspect cargo acceptance timestamp.' );
pek_cancel_assert( str_contains( $service, 'delete_for_carrier' ), 'Successful cancellation must remove local shipment.' );
pek_cancel_assert( ! str_contains( strtolower( $all_php ), 'cancelandreturncargo' ), 'Return API must not be present.' );

echo "PEK shipment cancellation smoke passed.\n";

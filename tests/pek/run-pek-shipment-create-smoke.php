<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function pek_shipment_create_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$root = dirname( __DIR__, 2 );
$plugin = file_get_contents( $root . '/src/Core/Plugin.php' ) ?: '';
$adapter = file_get_contents( $root . '/src/Shipments/Pek/PekShipmentAdapter.php' ) ?: '';
$builder = file_get_contents( $root . '/src/Shipments/Pek/PekShipmentRequestBuilder.php' ) ?: '';
$cargo = file_get_contents( $root . '/src/Shipments/Pek/PekShipmentCargoBuilder.php' ) ?: '';
$mapper = file_get_contents( $root . '/src/Shipments/Pek/PekShipmentPersistenceMapper.php' ) ?: '';

pek_shipment_create_assert( class_exists( WallsShop\WDC\Shipments\Pek\PekShipmentAdapter::class ), 'PEK adapter class must exist.' );
pek_shipment_create_assert( class_exists( WallsShop\WDC\Shipments\Pek\PekShipmentRequestBuilder::class ), 'PEK request builder class must exist.' );
pek_shipment_create_assert( str_contains( $plugin, 'PekShipmentAdapter::class' ), 'PEK adapter must be registered in Plugin.php.' );
pek_shipment_create_assert( str_contains( $plugin, 'PekShipmentPersistenceMapper::class' ), 'PEK mapper must be registered in Plugin.php.' );
pek_shipment_create_assert( str_contains( $adapter, '/preregistration/submit/' ), 'Adapter must create via preregistration submit.' );
pek_shipment_create_assert( str_contains( $builder, "'orderType' => 0" ), 'PEK shipment must use orderType 0.' );
pek_shipment_create_assert( str_contains( $builder, 'PekSettings::LTL_PRODUCT_TYPE' ), 'PEK shipment must use LTL type 3 constant.' );
pek_shipment_create_assert( str_contains( $builder, "'payer' => 'sender'" ), 'PEK services must be sender-paid.' );
pek_shipment_create_assert( str_contains( $builder, "'insurance'" ) && str_contains( $builder, "'smsRelease'" ), 'Insurance and SMS release must be in payload policy.' );
pek_shipment_create_assert( str_contains( $cargo, 'cargoPlaceList' ), 'Cargo places must be mapped to cargoPlaceList.' );
pek_shipment_create_assert( str_contains( $cargo, 'ceil2' ), 'Cargo place aggregates must use upward hundredth rounding.' );
pek_shipment_create_assert( str_contains( $mapper, 'pending_creation_in_carrier' ), 'Uncertain create result must be persistable.' );
pek_shipment_create_assert( ! str_contains( $builder, 'identityCard' ) && ! str_contains( $builder, 'passport' ), 'Physical SMS path must not send passport/identityCard.' );

echo "PEK shipment create smoke passed.\n";

<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . '/' );
require_once $root . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', $root . '/src' ) )->register();

use WallsShop\WDC\Carriers\OzonDelivery\Returns\OzonDeliveryReturnInfoParser;
use WallsShop\WDC\Carriers\OzonDelivery\Returns\OzonDeliveryReturnLifecycleResolver;
use WallsShop\WDC\Carriers\OzonDelivery\Returns\OzonDeliveryReturnSearchParser;
use WallsShop\WDC\Carriers\OzonDelivery\Returns\OzonDeliveryReturnService;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentExternalIdResolver;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentStatusMapping;
use WallsShop\WDC\Domain\Status\DeliveryStatus;

function oz_return_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$api_source = file_get_contents( $root . '/src/Carriers/OzonDelivery/Api/OzonDeliveryApiClient.php' ) ?: '';
$service_source = file_get_contents( $root . '/src/Carriers/OzonDelivery/Returns/OzonDeliveryReturnService.php' ) ?: '';
$shipment_source = file_get_contents( $root . '/src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentService.php' ) ?: '';
$adapter_source = file_get_contents( $root . '/src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentAdapter.php' ) ?: '';
$create_source = file_get_contents( $root . '/src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentCreateRequestBuilder.php' ) ?: '';

oz_return_assert( class_exists( OzonDeliveryReturnSearchParser::class ) && class_exists( OzonDeliveryReturnInfoParser::class ) && class_exists( OzonDeliveryReturnLifecycleResolver::class ) && class_exists( OzonDeliveryReturnService::class ), 'Ozon return module classes must be autoloadable.' );
oz_return_assert( str_contains( $api_source, "return_search" ) && str_contains( $api_source, "'/v1/return/search'" ) && str_contains( $api_source, "'pagination'" ) && str_contains( $api_source, "'cursor'" ) && str_contains( $api_source, "'limit'" ), 'Ozon API client must expose official /v1/return/search pagination wrapper.' );
oz_return_assert( str_contains( $api_source, "return_info" ) && str_contains( $api_source, "'/v1/return/info'" ) && str_contains( $api_source, "'return_numbers'" ), 'Ozon API client must expose official /v1/return/info wrapper with return_numbers array.' );
oz_return_assert( str_contains( $service_source, 'SAFETY_PAGE_CAP' ) && str_contains( $service_source, 'next_cursor' ) && str_contains( $service_source, 'return_external_id' ) && str_contains( $service_source, 'return_number' ) && str_contains( $service_source, 'return_info' ), 'Ozon return service must paginate search, exact-match return_external_id, persist return_number, and use return/info.' );
oz_return_assert( str_contains( $shipment_source, 'merge_outbound_posting_lifecycle' ) && str_contains( $shipment_source, 'handover_seen' ) && str_contains( $shipment_source, 'handover_unknown' ) && str_contains( $shipment_source, 'has_external_canceled_posting' ) && str_contains( $shipment_source, 'OzonDeliveryReturnService' ), 'Ozon shipment status path must maintain handover state and branch external CANCELED into return reconciliation.' );
oz_return_assert( str_contains( $shipment_source, "array( 'cancellation_started', 'cancellation_exhausted' )" ) && strpos( $shipment_source, 'return_info' ) === false, 'Local cancellation branches must remain separate from return API calls in shipment service.' );
oz_return_assert( str_contains( $adapter_source, 'return_tracking_presentation' ) && str_contains( $adapter_source, 'Локальные данные Ozon удалены из заказа' ) && ! str_contains( $adapter_source, 'Дождитесь подтверждения отмены Ozon' ), 'Ozon adapter must expose return presentation and allow explicit local remove without carrier mutation.' );
oz_return_assert( str_contains( $create_source, 'OzonDeliveryShipmentExternalIdResolver' ) && ! str_contains( $create_source, "wdc-" ) && ! str_contains( $create_source, "'_'" ), 'Ozon create builder must use shared external ID resolver and avoid synthetic or underscore external IDs.' );

$ids = new OzonDeliveryShipmentExternalIdResolver();
oz_return_assert( '1030' === $ids->order_external_id( '1030' ) && '1030' === $ids->posting_external_id( '1030', 1, 1 ) && '1030' === $ids->expected_return_external_id( '1030', 1, 1 ), 'Single-place Ozon external IDs must equal the WooCommerce order number.' );
oz_return_assert( '1030-1' === $ids->posting_external_id( '1030', 1, 2 ) && '1030-2' === $ids->expected_return_external_id( '1030', 2, 2 ), 'Multi-place Ozon external IDs must use order-number-place with a hyphen.' );
oz_return_assert( ! str_contains( $ids->posting_external_id( '1030', 1, 2 ), '_' ), 'Ozon external IDs must not use underscore format.' );

$documented = OzonDeliveryShipmentStatusMapping::documented_statuses();
foreach ( array( 'MOVING', 'AT_PICKUP_POINT', 'AT_THE_PICK_UP_POINT', 'RECEIVED', 'UTILIZATION', 'UTILIZED', 'WRITTEN_OFF', 'LOOKING_FOR' ) as $status ) {
	oz_return_assert( in_array( $status, $documented, true ), 'Ozon documented return status missing from catalog: ' . $status );
}
oz_return_assert( DeliveryStatus::RETURNING_TO_SENDER === OzonDeliveryShipmentStatusMapping::universal( 'AT_PICKUP_POINT' ) && DeliveryStatus::RETURNED_TO_SENDER === OzonDeliveryShipmentStatusMapping::universal( 'RECEIVED' ), 'Ozon return statuses must map to returning/returned universal defaults.' );

echo "Ozon Delivery return smoke passed.\n";

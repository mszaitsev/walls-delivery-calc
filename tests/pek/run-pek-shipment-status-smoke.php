<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Shipments\Pek\PekStatusMapping;

function pek_status_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$mapping = new PekStatusMapping();
$cases = array(
	'Аннулировано до приемки груза' => DeliveryStatus::CANCELLED,
	'Ожидается передача груза от отправителя' => DeliveryStatus::CREATED_IN_CARRIER,
	'В пути на терминал' => DeliveryStatus::IN_TRANSIT,
	'Прибыл частично' => DeliveryStatus::IN_TRANSIT,
	'Выполняется адресная доставка' => DeliveryStatus::HANDED_TO_COURIER,
	'Выдан получателю' => DeliveryStatus::DELIVERED,
	'Выдан ( мест 1 из 1 )' => DeliveryStatus::DELIVERED,
	'Отправлен на возврат' => DeliveryStatus::RETURNING_TO_SENDER,
	'Возвращен отправителю' => DeliveryStatus::RETURNED_TO_SENDER,
	'Утилизирован' => DeliveryStatus::REJECTED,
	'Новый странный статус' => DeliveryStatus::UNKNOWN,
);
foreach ( $cases as $external => $expected ) {
	pek_status_assert( $mapping->map( $external ) === $expected, 'Unexpected PEK status mapping for ' . $external );
}
pek_status_assert( DeliveryStatus::READY_FOR_PICKUP === $mapping->map( 'Прибыл', DeliveryType::PICKUP ), 'Arrived pickup must be ready_for_pickup.' );
pek_status_assert( DeliveryStatus::IN_TRANSIT === $mapping->map( 'Прибыл', DeliveryType::COURIER ), 'Arrived courier must remain in_transit.' );

$service = file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Pek/PekShipmentStatusService.php' ) ?: '';
pek_status_assert( str_contains( $service, 'cargo_status' ), 'Expanded /cargos/status/ must be primary.' );
pek_status_assert( str_contains( $service, 'cargo_basic_status' ), 'Basic status fallback must exist.' );
pek_status_assert( str_contains( $service, 'pek_cargos_status_services_sum' ), 'Actual cost must use services.sum source detail.' );
pek_status_assert( str_contains( $service, 'MoneyParser::numeric_to_kopecks' ), 'Actual cost must use strict decimal parser.' );
pek_status_assert( ! str_contains( $service, 'round( (float) $sum * 100' ), 'Actual cost must not use float multiplication.' );

echo "PEK shipment status smoke passed.\n";

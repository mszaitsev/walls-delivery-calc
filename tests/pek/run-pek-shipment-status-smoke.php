<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Shipments\Application\ShipmentActualCost;
use WallsShop\WDC\Shipments\Pek\PekShipmentStatusResponseNormalizer;
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
pek_status_assert( DeliveryStatus::IN_TRANSIT === $mapping->map( 'РАЗГРУЖАЕТСЯ. ОЖИДАЙТЕ ОПОВЕЩЕНИЯ', DeliveryType::PICKUP ), 'Uppercase Cyrillic status must normalize without mbstring dependency.' );
pek_status_assert( DeliveryStatus::RETURNED_TO_SENDER === $mapping->map( 'ВОЗВРАЩЁН ОТПРАВИТЕЛЮ', DeliveryType::PICKUP ), 'Ё/Е status normalization must be stable.' );

$service = file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Pek/PekShipmentStatusService.php' ) ?: '';
$normalizer_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Pek/PekShipmentStatusResponseNormalizer.php' ) ?: '';
pek_status_assert( str_contains( $service, 'cargo_status' ), 'Expanded /cargos/status/ must be primary.' );
pek_status_assert( str_contains( $service, 'cargo_basic_status' ), 'Basic status fallback must exist.' );
pek_status_assert( str_contains( $normalizer_source, 'pek_cargos_status_services_sum' ), 'Actual cost must use services.sum source detail.' );
pek_status_assert( str_contains( $normalizer_source, 'MoneyParser::numeric_to_kopecks' ), 'Actual cost must use strict decimal parser.' );
pek_status_assert( ! str_contains( $normalizer_source . $service, 'round( (float) $sum * 100' ), 'Actual cost must not use float multiplication.' );

$normalizer = new PekShipmentStatusResponseNormalizer();
$valid = array(
	'cargos' => array(
		array(
			'cargo' => array(
				'code' => 'PEK-777',
				'cargoBarCode' => 'BAR-777',
				'positionBarCodes' => array( 'POS-1', 'POS-2' ),
			),
			'info' => array(
				'cargoStatus' => 'Прибыл',
				'cargoStatusId' => '42',
				'takeOnStockDateTime' => '',
			),
			'receiver' => array(
				'receivingBySMSCode' => true,
				'receivingByDocument' => false,
			),
			'services' => array( 'sum' => '123.45' ),
		),
	),
);
$normalized = $normalizer->normalize( $valid, 'PEK-777', '2026-08-06 12:30:00' );
pek_status_assert( 'Прибыл' === $normalized['status_title'], 'Typed status normalizer must accept valid expanded status.' );
pek_status_assert( $normalized['actual_cost_candidate'] instanceof ShipmentActualCost, 'Typed status normalizer must build actual-cost candidate for strict services.sum.' );

$malformed_cases = array(
	'cargoStatus array' => array( 'info' => array( 'cargoStatus' => array() ) ),
	'cargoStatus bool' => array( 'info' => array( 'cargoStatus' => true ) ),
	'receivingBySMSCode string false' => array( 'receiver' => array( 'receivingBySMSCode' => 'false' ) ),
	'receivingByDocument integer' => array( 'receiver' => array( 'receivingByDocument' => 1 ) ),
	'date array' => array( 'info' => array( 'takeOnStockDateTime' => array() ) ),
	'impossible date' => array( 'info' => array( 'takeOnStockDateTime' => '2026-99-99 10:00:00' ) ),
	'cargo barcode array' => array( 'cargo' => array( 'cargoBarCode' => array() ) ),
	'mixed position barcode list' => array( 'cargo' => array( 'positionBarCodes' => array( 'POS-1', array() ) ) ),
);
foreach ( $malformed_cases as $label => $patch ) {
	$row = $valid['cargos'][0];
	foreach ( $patch as $section => $values ) {
		$row[ $section ] = array_merge( is_array( $row[ $section ] ?? null ) ? $row[ $section ] : array(), $values );
	}
	try {
		$normalizer->normalize( array( 'cargos' => array( $row ) ), 'PEK-777', '2026-08-06 12:30:00' );
		pek_status_assert( false, 'Malformed status must fail: ' . $label );
	} catch ( RuntimeException ) {
		pek_status_assert( true, 'Malformed status rejected: ' . $label );
	}
}
foreach ( array(
	'duplicate cargo' => array( 'cargos' => array( $valid['cargos'][0], $valid['cargos'][0] ) ),
	'mismatched cargo' => array( 'cargos' => array( array_merge( $valid['cargos'][0], array( 'cargo' => array( 'code' => 'OTHER' ) ) ) ) ),
	'associative cargos' => array( 'cargos' => array( 'x' => $valid['cargos'][0] ) ),
) as $label => $response ) {
	try {
		$normalizer->normalize( $response, 'PEK-777', '2026-08-06 12:30:00' );
		pek_status_assert( false, 'Malformed cargo selection must fail: ' . $label );
	} catch ( RuntimeException ) {
		pek_status_assert( true, 'Malformed cargo selection rejected: ' . $label );
	}
}

echo "PEK shipment status smoke passed.\n";

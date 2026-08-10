<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Shipments\Application\ShipmentActualCost;
use WallsShop\WDC\Shipments\Pek\PekShipmentStatusNormalizationException;
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
pek_status_assert( str_contains( $normalizer_source, 'createFromFormat' ) && str_contains( $normalizer_source, 'getLastErrors' ), 'Status dates must use strict format parsing.' );
pek_status_assert( str_contains( $normalizer_source, 'add_if_present' ) && str_contains( $normalizer_source, 'array_key_exists' ), 'Optional status fields must be presence-aware.' );
pek_status_assert( str_contains( $normalizer_source, "1 !== count( \$response['cargos'] )" ), 'Status response must require exactly one cargo row.' );
pek_status_assert( str_contains( $service, 'PekShipmentStatusResponseNormalizer $normalizer' ), 'Status normalizer must be a required DI dependency.' );

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
pek_status_assert( '42' === (string) ( $normalized['pek_cargo_status_id'] ?? '' ), 'Typed status normalizer must preserve positive digit-string cargoStatusId.' );
pek_status_assert( $normalized['actual_cost_candidate'] instanceof ShipmentActualCost, 'Typed status normalizer must build actual-cost candidate for strict services.sum.' );
pek_status_assert( true === $normalized['pek_receiving_by_sms_code'], 'Explicit true receiver flag must be preserved.' );
pek_status_assert( false === $normalized['pek_receiving_by_document'], 'Explicit false receiver flag must be preserved.' );

$positive_int = $valid;
$positive_int['cargos'][0]['info']['cargoStatusId'] = 8;
$positive_int_normalized = $normalizer->normalize( $positive_int, 'PEK-777', '2026-08-06 12:30:00' );
pek_status_assert( '8' === (string) ( $positive_int_normalized['pek_cargo_status_id'] ?? '' ), 'Typed status normalizer must preserve positive integer cargoStatusId.' );

$fresh_sentinel = $valid;
$fresh_sentinel['cargos'][0]['info']['cargoStatus'] = 'Ожидается передача груза от отправителя';
$fresh_sentinel['cargos'][0]['info']['cargoStatusId'] = -1;
$fresh_sentinel_normalized = $normalizer->normalize( $fresh_sentinel, 'PEK-777', '2026-08-06 12:30:00' );
pek_status_assert( 'Ожидается передача груза от отправителя' === $fresh_sentinel_normalized['status_title'], 'Fresh PEK cargoStatusId=-1 sentinel must not discard valid cargoStatus title.' );
pek_status_assert( array_key_exists( 'pek_cargo_status_id', $fresh_sentinel_normalized ) && null === $fresh_sentinel_normalized['pek_cargo_status_id'], 'Fresh PEK cargoStatusId=-1 sentinel must signal canonical status ID omission.' );

$cancelled_sentinel = $valid;
$cancelled_sentinel['cargos'][0]['info']['cargoStatus'] = 'Аннулировано до приемки груза';
$cancelled_sentinel['cargos'][0]['info']['cargoStatusId'] = '-1';
$cancelled_sentinel_normalized = $normalizer->normalize( $cancelled_sentinel, 'PEK-777', '2026-08-06 12:30:00' );
pek_status_assert( 'Аннулировано до приемки груза' === $cancelled_sentinel_normalized['status_title'], 'Cancelled PEK cargoStatusId="-1" sentinel must not discard valid cargoStatus title.' );
pek_status_assert( array_key_exists( 'pek_cargo_status_id', $cancelled_sentinel_normalized ) && null === $cancelled_sentinel_normalized['pek_cargo_status_id'], 'Cancelled PEK cargoStatusId="-1" sentinel must signal canonical status ID omission.' );
pek_status_assert( DeliveryStatus::CANCELLED === $mapping->map( $cancelled_sentinel_normalized['status_title'] ), 'Cancelled PEK status with string sentinel must map to universal CANCELLED.' );

$cancelled_negative_sentinel = $valid;
$cancelled_negative_sentinel['cargos'][0]['info']['cargoStatus'] = 'Аннулировано до приемки груза';
$cancelled_negative_sentinel['cargos'][0]['info']['cargoStatusId'] = -3;
$cancelled_negative_sentinel_normalized = $normalizer->normalize( $cancelled_negative_sentinel, 'PEK-777', '2026-08-06 12:30:00' );
pek_status_assert( 'Аннулировано до приемки груза' === $cancelled_negative_sentinel_normalized['status_title'], 'Cancelled PEK cargoStatusId=-3 sentinel must not discard valid cargoStatus title.' );
pek_status_assert( array_key_exists( 'pek_cargo_status_id', $cancelled_negative_sentinel_normalized ) && null === $cancelled_negative_sentinel_normalized['pek_cargo_status_id'], 'Cancelled PEK cargoStatusId=-3 sentinel must signal canonical status ID omission.' );
pek_status_assert( DeliveryStatus::CANCELLED === $mapping->map( $cancelled_negative_sentinel_normalized['status_title'] ), 'Cancelled PEK status with integer -3 sentinel must map to universal CANCELLED.' );

$cancelled_positive_id = $valid;
$cancelled_positive_id['cargos'][0]['info']['cargoStatus'] = 'Аннулировано до приемки груза';
$cancelled_positive_id['cargos'][0]['info']['cargoStatusId'] = 8;
$cancelled_positive_id_normalized = $normalizer->normalize( $cancelled_positive_id, 'PEK-777', '2026-08-06 12:30:00' );
pek_status_assert( '8' === (string) ( $cancelled_positive_id_normalized['pek_cargo_status_id'] ?? '' ), 'Cancelled PEK status with positive cargoStatusId must still persist positive ID normally.' );

$minimal = $valid;
unset( $minimal['cargos'][0]['receiver'], $minimal['cargos'][0]['services'], $minimal['cargos'][0]['cargo']['cargoBarCode'], $minimal['cargos'][0]['cargo']['positionBarCodes'], $minimal['cargos'][0]['info']['cargoStatusId'], $minimal['cargos'][0]['info']['takeOnStockDateTime'] );
$minimal_normalized = $normalizer->normalize( $minimal, 'PEK-777', '2026-08-06 12:30:00' );
foreach ( array( 'pek_receiving_by_sms_code', 'pek_receiving_by_document', 'actual_cost_candidate', 'pek_cargo_status_id', 'pek_take_on_stock_datetime', 'pek_cargo_barcode', 'pek_position_barcodes' ) as $absent_key ) {
	pek_status_assert( ! array_key_exists( $absent_key, $minimal_normalized ), 'Absent optional status field must not be synthesized: ' . $absent_key );
}

foreach ( array(
	'official deliveryPlanDate example' => array( 'deliveryPlanDate', 'pek_delivery_plan_date', '2021-08-19T00:00:00' ),
	'iso local' => '2026-08-06T12:30:00',
	'iso offset' => '2026-08-06T12:30:00+03:00',
	'mysql compatibility' => '2026-08-06 12:30:00',
) as $label => $date_case ) {
	$row = $valid['cargos'][0];
	$field = 'arrivalDateTime';
	$target = 'pek_arrival_datetime';
	$date = $date_case;
	if ( is_array( $date_case ) ) {
		$field = $date_case[0];
		$target = $date_case[1];
		$date = $date_case[2];
	}
	$row['info'][ $field ] = $date;
	$date_normalized = $normalizer->normalize( array( 'cargos' => array( $row ) ), 'PEK-777', '2026-08-06 12:30:00' );
	pek_status_assert( $date === $date_normalized[ $target ], 'Valid PEK date must round-trip: ' . $label );
}

$invalid_delivery_plan = $valid;
$invalid_delivery_plan['cargos'][0]['info']['deliveryPlanDate'] = 'tomorrow';
try {
	$normalizer->normalize( $invalid_delivery_plan, 'PEK-777', '2026-08-06 12:30:00' );
	pek_status_assert( false, 'Invalid deliveryPlanDate must fail with a safe diagnostic.' );
} catch ( PekShipmentStatusNormalizationException $e ) {
	$diagnostic = $e->diagnostic();
	pek_status_assert( 'deliveryPlanDate' === (string) ( $diagnostic['field'] ?? '' ), 'Invalid deliveryPlanDate diagnostic must expose the field.' );
	pek_status_assert( 'string' === (string) ( $diagnostic['value_type'] ?? '' ), 'Invalid deliveryPlanDate diagnostic must expose string value_type.' );
	pek_status_assert( 'tomorrow' === (string) ( $diagnostic['value'] ?? '' ), 'Invalid deliveryPlanDate diagnostic must expose bounded scalar value.' );
}

$invalid_delivery_plan_array = $valid;
$invalid_delivery_plan_array['cargos'][0]['info']['deliveryPlanDate'] = array( '2026-08-10' );
try {
	$normalizer->normalize( $invalid_delivery_plan_array, 'PEK-777', '2026-08-06 12:30:00' );
	pek_status_assert( false, 'Array deliveryPlanDate must fail with a safe diagnostic.' );
} catch ( PekShipmentStatusNormalizationException $e ) {
	$diagnostic = $e->diagnostic();
	pek_status_assert( 'deliveryPlanDate' === (string) ( $diagnostic['field'] ?? '' ), 'Array deliveryPlanDate diagnostic must expose the field.' );
	pek_status_assert( 'array' === (string) ( $diagnostic['value_type'] ?? '' ), 'Array deliveryPlanDate diagnostic must expose array value_type.' );
	pek_status_assert( ! array_key_exists( 'value', $diagnostic ), 'Array deliveryPlanDate diagnostic must not expose raw array content.' );
}

$malformed_cases = array(
	'cargoStatus array' => array( 'info' => array( 'cargoStatus' => array() ) ),
	'cargoStatus bool' => array( 'info' => array( 'cargoStatus' => true ) ),
	'receivingBySMSCode string false' => array( 'receiver' => array( 'receivingBySMSCode' => 'false' ) ),
	'receivingByDocument integer' => array( 'receiver' => array( 'receivingByDocument' => 1 ) ),
	'receivingByDocument null' => array( 'receiver' => array( 'receivingByDocument' => null ) ),
	'date array' => array( 'info' => array( 'takeOnStockDateTime' => array() ) ),
	'not-a-date' => array( 'info' => array( 'takeOnStockDateTime' => 'not-a-date' ) ),
	'tomorrow' => array( 'info' => array( 'takeOnStockDateTime' => 'tomorrow' ) ),
	'impossible day' => array( 'info' => array( 'takeOnStockDateTime' => '2026-02-30T10:00:00' ) ),
	'invalid month' => array( 'info' => array( 'takeOnStockDateTime' => '2026-13-01T10:00:00' ) ),
	'date bool' => array( 'info' => array( 'takeOnStockDateTime' => true ) ),
	'cargoStatusId bool' => array( 'info' => array( 'cargoStatusId' => false ) ),
	'cargoStatusId float' => array( 'info' => array( 'cargoStatusId' => 42.5 ) ),
	'cargoStatusId negative' => array( 'info' => array( 'cargoStatusId' => -2 ) ),
	'cargoStatusId negative string' => array( 'info' => array( 'cargoStatusId' => '-2' ) ),
	'cargoStatusId padded string sentinel' => array( 'info' => array( 'cargoStatusId' => ' -1 ' ) ),
	'cargoStatusId cancelled string -3' => array( 'info' => array( 'cargoStatus' => 'Аннулировано до приемки груза', 'cargoStatusId' => '-3' ) ),
	'cargoStatusId -3 in transit mismatch' => array( 'info' => array( 'cargoStatus' => 'В пути', 'cargoStatusId' => -3 ) ),
	'cargoStatusId -3 waiting mismatch' => array( 'info' => array( 'cargoStatus' => 'Ожидается передача груза от отправителя', 'cargoStatusId' => -3 ) ),
	'cargo barcode array' => array( 'cargo' => array( 'cargoBarCode' => array() ) ),
	'mixed position barcode list' => array( 'cargo' => array( 'positionBarCodes' => array( 'POS-1', array() ) ) ),
	'actual cost negative' => array( 'services' => array( 'sum' => '-1.00' ) ),
	'actual cost nonnumeric' => array( 'services' => array( 'sum' => '12 rub' ) ),
	'actual cost bool' => array( 'services' => array( 'sum' => true ) ),
	'actual cost array' => array( 'services' => array( 'sum' => array() ) ),
	'actual cost too many decimals' => array( 'services' => array( 'sum' => '123.456' ) ),
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
	'zero cargos' => array( 'cargos' => array() ),
	'duplicate cargo' => array( 'cargos' => array( $valid['cargos'][0], $valid['cargos'][0] ) ),
	'matching plus unrelated cargo' => array( 'cargos' => array( $valid['cargos'][0], array_merge( $valid['cargos'][0], array( 'cargo' => array( 'code' => 'OTHER' ) ) ) ) ),
	'mismatched cargo' => array( 'cargos' => array( array_merge( $valid['cargos'][0], array( 'cargo' => array( 'code' => 'OTHER' ) ) ) ) ),
	'malformed sibling cargo' => array( 'cargos' => array( $valid['cargos'][0], 'bad-row' ) ),
	'associative cargos' => array( 'cargos' => array( 'x' => $valid['cargos'][0] ) ),
) as $label => $response ) {
	try {
		$normalizer->normalize( $response, 'PEK-777', '2026-08-06 12:30:00' );
		pek_status_assert( false, 'Malformed cargo selection must fail: ' . $label );
	} catch ( RuntimeException ) {
		pek_status_assert( true, 'Malformed cargo selection rejected: ' . $label );
	}
}

foreach ( array( null, '', 0, 0.0, '0', '0.00', '0,00', '0.0' ) as $zero_sum ) {
	$row = $valid['cargos'][0];
	$row['services']['sum'] = $zero_sum;
	$zero_normalized = $normalizer->normalize( array( 'cargos' => array( $row ) ), 'PEK-777', '2026-08-06 12:30:00' );
	pek_status_assert( ! array_key_exists( 'actual_cost_candidate', $zero_normalized ), 'Missing/null/zero actual cost must not create candidate.' );
}
foreach ( array( 123, '123.45' ) as $positive_sum ) {
	$row = $valid['cargos'][0];
	$row['services']['sum'] = $positive_sum;
	$cost_normalized = $normalizer->normalize( array( 'cargos' => array( $row ) ), 'PEK-777', '2026-08-06 12:30:00' );
	pek_status_assert( $cost_normalized['actual_cost_candidate'] instanceof ShipmentActualCost, 'Positive actual cost must create candidate.' );
}

echo "PEK shipment status smoke passed.\n";

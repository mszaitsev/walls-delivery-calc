<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Common\MoneyParser;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Shipments\Application\ShipmentActualCost;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class PekShipmentStatusService {
	public function __construct(
		private PekApiClient $api,
		private PekStatusMapping $mapping,
		private OrderShipmentRepository $repository,
		private ShipmentActualCostService $actual_costs
	) {
	}

	/** @return array<string,mixed> */
	public function update( object $order ): array {
		$shipment = $this->repository->find_by_carrier( $order, PekSettings::CARRIER_KEY );
		$code = $this->cargo_code( $shipment );
		if ( '' === $code ) {
			return array( 'success' => false, 'message' => 'Не указан код груза ПЭК.' );
		}
		$status = $this->fetch( $code, (string) ( $shipment['delivery_type'] ?? '' ) );
		$shipment = array_merge( $shipment, $status, array( 'updated_at' => $this->now() ) );
		$this->repository->save_for_carrier( $order, PekSettings::CARRIER_KEY, $shipment );
		if ( $status['actual_cost_candidate'] instanceof ShipmentActualCost ) {
			$shipment = $this->actual_costs->apply_carrier_cost( $order, PekSettings::CARRIER_KEY, $status['actual_cost_candidate'] );
		}
		unset( $status['actual_cost_candidate'] );

		return array( 'success' => true, 'message' => 'Статус ПЭК обновлён.', 'shipment' => $shipment, 'status' => $status );
	}

	/** @return array<string,mixed> */
	public function fetch( string $cargo_code, string $delivery_type ): array {
		try {
			$response = $this->api->cargo_status( array( $cargo_code ) );
		} catch ( \Throwable ) {
			$response = $this->api->cargo_basic_status( array( $cargo_code ) );
		}
		$row = $this->single_cargo( $response, $cargo_code );
		$info = is_array( $row['info'] ?? null ) ? $row['info'] : array();
		$cargo = is_array( $row['cargo'] ?? null ) ? $row['cargo'] : array();
		$status_title = (string) ( $info['cargoStatus'] ?? $row['cargoStatus'] ?? '' );
		$universal = $this->mapping->map( $status_title, $delivery_type );
		$actual = $this->actual_cost_candidate( $row );

		return array(
			'status' => 'created',
			'status_title' => $status_title,
			'universal_status_code' => $universal,
			'universal_status_label' => DeliveryStatus::label( $universal ),
			'pek_cargo_status' => $status_title,
			'pek_cargo_status_id' => (string) ( $info['cargoStatusId'] ?? $row['cargoStatusId'] ?? '' ),
			'pek_take_on_stock_datetime' => (string) ( $info['takeOnStockDateTime'] ?? '' ),
			'pek_arrival_datetime' => (string) ( $info['arrivalDateTime'] ?? '' ),
			'pek_delivery_plan_date' => (string) ( $info['deliveryPlanDate'] ?? '' ),
			'pek_received_by_client_datetime' => (string) ( $info['receivedByClientDateTime'] ?? '' ),
			'pek_receiving_by_sms_code' => (bool) ( $row['receiver']['receivingBySMSCode'] ?? false ),
			'pek_receiving_by_document' => (bool) ( $row['receiver']['receivingByDocument'] ?? false ),
			'pek_cargo_barcode' => (string) ( $cargo['cargoBarCode'] ?? '' ),
			'pek_position_barcodes' => is_array( $cargo['positionBarCodes'] ?? null ) ? array_values( array_filter( $cargo['positionBarCodes'], 'is_string' ) ) : array(),
			'tracking_checked_at' => $this->now(),
			'actual_cost_candidate' => $actual,
		);
	}

	/** @param array<string,mixed> $shipment */
	public function cargo_code( array $shipment ): string {
		return trim( (string) ( $shipment['pek_cargo_code'] ?? $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
	}

	/** @param array<string,mixed> $response @return array<string,mixed> */
	private function single_cargo( array $response, string $cargo_code ): array {
		$matches = array();
		foreach ( is_array( $response['cargos'] ?? null ) ? $response['cargos'] : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cargo = is_array( $row['cargo'] ?? null ) ? $row['cargo'] : array();
			if ( $cargo_code === (string) ( $cargo['code'] ?? $row['cargoCode'] ?? '' ) ) {
				$matches[] = $row;
			}
		}
		if ( 1 !== count( $matches ) ) {
			throw new \RuntimeException( 'ПЭК не подтвердил единственный груз по указанному коду.' );
		}

		return $matches[0];
	}

	/** @param array<string,mixed> $row */
	private function actual_cost_candidate( array $row ): ?ShipmentActualCost {
		$sum = $row['services']['sum'] ?? null;
		if ( ! is_int( $sum ) && ! is_float( $sum ) && ! is_string( $sum ) ) {
			return null;
		}
		$value = trim( (string) $sum );
		if ( 1 !== preg_match( '/^\d+(?:[.,]\d{1,2})?$/', $value ) ) {
			return null;
		}
		$kopecks = MoneyParser::numeric_to_kopecks( $value );
		if ( null === $kopecks || $kopecks <= 0 ) {
			return null;
		}

		return new ShipmentActualCost( $kopecks, 'RUB', 'carrier', 'pek_cargos_status_services_sum', $this->now() );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}

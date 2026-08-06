<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Domain\Common\MoneyParser;
use WallsShop\WDC\Shipments\Application\ShipmentActualCost;

defined( 'ABSPATH' ) || exit;

final class PekShipmentStatusResponseNormalizer {
	/**
	 * @param array<string,mixed> $response
	 * @return array<string,mixed>
	 */
	public function normalize( array $response, string $cargo_code, string $checked_at ): array {
		$row = $this->single_cargo( $response, $cargo_code );
		$info = $this->optional_object( $row['info'] ?? array(), 'info' );
		$cargo = $this->optional_object( $row['cargo'] ?? array(), 'cargo' );
		$receiver = $this->optional_object( $row['receiver'] ?? array(), 'receiver' );
		$status = $this->required_string( $info['cargoStatus'] ?? $row['cargoStatus'] ?? null, 'cargoStatus' );

		return array(
			'status_title' => $status,
			'pek_cargo_status' => $status,
			'pek_cargo_status_id' => $this->optional_string_or_int( $info['cargoStatusId'] ?? $row['cargoStatusId'] ?? null, 'cargoStatusId' ),
			'pek_take_on_stock_datetime' => $this->optional_date_string( $info['takeOnStockDateTime'] ?? null, 'takeOnStockDateTime' ),
			'pek_arrival_datetime' => $this->optional_date_string( $info['arrivalDateTime'] ?? null, 'arrivalDateTime' ),
			'pek_delivery_plan_date' => $this->optional_date_string( $info['deliveryPlanDate'] ?? null, 'deliveryPlanDate' ),
			'pek_received_by_client_datetime' => $this->optional_date_string( $info['receivedByClientDateTime'] ?? null, 'receivedByClientDateTime' ),
			'pek_receiving_by_sms_code' => $this->optional_bool( $receiver['receivingBySMSCode'] ?? null, 'receivingBySMSCode' ),
			'pek_receiving_by_document' => $this->optional_bool( $receiver['receivingByDocument'] ?? null, 'receivingByDocument' ),
			'pek_cargo_barcode' => $this->optional_string( $cargo['cargoBarCode'] ?? null, 'cargoBarCode' ),
			'pek_position_barcodes' => $this->string_list( $cargo['positionBarCodes'] ?? array(), 'positionBarCodes' ),
			'tracking_checked_at' => $checked_at,
			'actual_cost_candidate' => $this->actual_cost_candidate( $row, $checked_at ),
		);
	}

	/** @param array<string,mixed> $response @return array<string,mixed> */
	private function single_cargo( array $response, string $cargo_code ): array {
		if ( ! array_key_exists( 'cargos', $response ) || ! is_array( $response['cargos'] ) || ! array_is_list( $response['cargos'] ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректный формат статуса груза.' );
		}
		$matches = array();
		foreach ( $response['cargos'] as $index => $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) ) {
				throw new \RuntimeException( 'ПЭК вернул некорректный формат статуса груза.' );
			}
			$cargo = $this->optional_object( $row['cargo'] ?? array(), 'cargos.' . $index . '.cargo' );
			$code = $row['cargoCode'] ?? $cargo['code'] ?? null;
			if ( ! is_string( $code ) || '' === trim( $code ) ) {
				throw new \RuntimeException( 'ПЭК вернул некорректный код груза в статусе.' );
			}
			if ( $cargo_code === trim( $code ) ) {
				$matches[] = $row;
			}
		}
		if ( 1 !== count( $matches ) ) {
			throw new \RuntimeException( 'ПЭК не подтвердил единственный груз по указанному коду.' );
		}

		return $matches[0];
	}

	/** @return array<string,mixed> */
	private function optional_object( mixed $value, string $field ): array {
		if ( null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректный объект статуса: ' . $field . '.' );
		}

		return $value;
	}

	private function required_string( mixed $value, string $field ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректное поле статуса: ' . $field . '.' );
		}

		return trim( $value );
	}

	private function optional_string( mixed $value, string $field ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}
		if ( ! is_string( $value ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректное поле статуса: ' . $field . '.' );
		}

		return trim( $value );
	}

	private function optional_string_or_int( mixed $value, string $field ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректное поле статуса: ' . $field . '.' );
		}

		return trim( (string) $value );
	}

	private function optional_bool( mixed $value, string $field ): bool {
		if ( null === $value ) {
			return false;
		}
		if ( ! is_bool( $value ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректное поле статуса: ' . $field . '.' );
		}

		return $value;
	}

	private function optional_date_string( mixed $value, string $field ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}
		if ( ! is_string( $value ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректную дату статуса: ' . $field . '.' );
		}
		$value = trim( $value );
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}/', $value ) ) {
			try {
				new \DateTimeImmutable( $value );
			} catch ( \Throwable ) {
				throw new \RuntimeException( 'ПЭК вернул некорректную дату статуса: ' . $field . '.' );
			}
		}

		return $value;
	}

	/** @return array<int,string> */
	private function string_list( mixed $value, string $field ): array {
		if ( null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректный список статуса: ' . $field . '.' );
		}
		$result = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === trim( $item ) ) {
				throw new \RuntimeException( 'ПЭК вернул некорректный список статуса: ' . $field . '.' );
			}
			$result[] = trim( $item );
		}

		return array_values( array_unique( $result ) );
	}

	/** @param array<string,mixed> $row */
	private function actual_cost_candidate( array $row, string $checked_at ): ?ShipmentActualCost {
		$services = $this->optional_object( $row['services'] ?? array(), 'services' );
		$sum = $services['sum'] ?? null;
		if ( null === $sum || '' === $sum ) {
			return null;
		}
		if ( is_bool( $sum ) || is_array( $sum ) || is_object( $sum ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректную стоимость услуг.' );
		}
		if ( ! is_int( $sum ) && ! is_float( $sum ) && ! is_string( $sum ) ) {
			return null;
		}
		if ( is_float( $sum ) && ! is_finite( $sum ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректную стоимость услуг.' );
		}
		$kopecks = MoneyParser::numeric_to_kopecks( (string) $sum );
		if ( null === $kopecks || $kopecks <= 0 ) {
			return null;
		}

		return new ShipmentActualCost( $kopecks, 'RUB', 'carrier', 'pek_cargos_status_services_sum', $checked_at );
	}
}

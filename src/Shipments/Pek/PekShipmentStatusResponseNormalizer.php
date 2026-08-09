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
		$info = $this->object_when_present( $row, 'info' );
		$cargo = $this->object_when_present( $row, 'cargo' );
		$receiver = $this->object_when_present( $row, 'receiver' );
		$status = $this->required_string( $info['cargoStatus'] ?? $row['cargoStatus'] ?? null, 'cargoStatus' );

		$result = array(
			'status_title' => $status,
			'pek_cargo_status' => $status,
			'tracking_checked_at' => $checked_at,
		);
		if ( array_key_exists( 'cargoStatusId', $info ) ) {
			$status_id = $this->optional_status_id( $info['cargoStatusId'], 'cargoStatusId' );
			if ( null !== $status_id ) {
				$result['pek_cargo_status_id'] = $status_id;
			} else {
				$result['pek_cargo_status_id'] = null;
			}
		}
		foreach ( array(
			'takeOnStockDateTime' => 'pek_take_on_stock_datetime',
			'arrivalDateTime' => 'pek_arrival_datetime',
			'deliveryPlanDate' => 'pek_delivery_plan_date',
			'receivedByClientDateTime' => 'pek_received_by_client_datetime',
		) as $source_key => $target_key ) {
			$this->add_if_present( $result, $target_key, $info, $source_key, fn( mixed $value ): string => $this->optional_date_string( $value, $source_key ) );
		}
		$this->add_if_present( $result, 'pek_receiving_by_sms_code', $receiver, 'receivingBySMSCode', fn( mixed $value ): bool => $this->optional_bool( $value, 'receivingBySMSCode' ) );
		$this->add_if_present( $result, 'pek_receiving_by_document', $receiver, 'receivingByDocument', fn( mixed $value ): bool => $this->optional_bool( $value, 'receivingByDocument' ) );
		$this->add_if_present( $result, 'pek_cargo_barcode', $cargo, 'cargoBarCode', fn( mixed $value ): string => $this->optional_string( $value, 'cargoBarCode' ) );
		$this->add_if_present( $result, 'pek_position_barcodes', $cargo, 'positionBarCodes', fn( mixed $value ): array => $this->string_list( $value, 'positionBarCodes' ) );
		$candidate = $this->actual_cost_candidate( $row, $checked_at );
		if ( $candidate instanceof ShipmentActualCost ) {
			$result['actual_cost_candidate'] = $candidate;
		}

		return $result;
	}

	/** @param array<string,mixed> $response @return array<string,mixed> */
	private function single_cargo( array $response, string $cargo_code ): array {
		if ( ! array_key_exists( 'cargos', $response ) || ! is_array( $response['cargos'] ) || ! array_is_list( $response['cargos'] ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректный формат статуса груза.' );
		}
		if ( 1 !== count( $response['cargos'] ) ) {
			throw new \RuntimeException( 'ПЭК не подтвердил единственный груз по указанному коду.' );
		}
		$row = $response['cargos'][0];
		if ( ! is_array( $row ) || array_is_list( $row ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректный формат статуса груза.' );
		}
		$cargo = $this->object_when_present( $row, 'cargo' );
		$code = $row['cargoCode'] ?? $cargo['code'] ?? null;
		if ( ! is_string( $code ) || '' === trim( $code ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректный код груза в статусе.' );
		}
		if ( $cargo_code !== trim( $code ) ) {
			throw new \RuntimeException( 'ПЭК не подтвердил единственный груз по указанному коду.' );
		}

		return $row;
	}

	/** @return array<string,mixed> */
	private function object_when_present( array $object, string $key ): array {
		if ( ! array_key_exists( $key, $object ) ) {
			return array();
		}
		$value = $object[ $key ];
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректный объект статуса: ' . $key . '.' );
		}

		return $value;
	}

	/**
	 * @param array<string,mixed> $result
	 * @param array<string,mixed> $source
	 */
	private function add_if_present( array &$result, string $target_key, array $source, string $source_key, callable $normalizer ): void {
		if ( array_key_exists( $source_key, $source ) ) {
			$result[ $target_key ] = $normalizer( $source[ $source_key ] );
		}
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
		if ( '' === trim( $value ) || strlen( $value ) > 128 ) {
			throw new \RuntimeException( 'ПЭК вернул некорректное поле статуса: ' . $field . '.' );
		}

		return trim( $value );
	}

	private function optional_status_id( mixed $value, string $field ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( -1 === $value || '-1' === $value ) {
			return null;
		}
		if ( is_int( $value ) && $value > 0 ) {
			return (string) $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d+$/', trim( $value ) ) && (int) trim( $value ) > 0 ) {
			return trim( $value );
		}

		throw $this->invalid_status_field( $field, $value );
	}

	private function invalid_status_field( string $field, mixed $value ): PekShipmentStatusNormalizationException {
		$diagnostic = array(
			'field' => $field,
			'value_type' => get_debug_type( $value ),
		);
		if ( ( is_int( $value ) || is_string( $value ) ) && strlen( (string) $value ) <= 32 ) {
			$diagnostic['value'] = (string) $value;
		}

		return new PekShipmentStatusNormalizationException( 'ПЭК вернул некорректное поле статуса: ' . $field . '.', $diagnostic );
	}

	private function optional_bool( mixed $value, string $field ): bool {
		if ( null === $value ) {
			throw new \RuntimeException( 'ПЭК вернул некорректное поле статуса: ' . $field . '.' );
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
		foreach ( array( 'Y-m-d\\TH:i:s', 'Y-m-d\\TH:i:sP', 'Y-m-d\\TH:i:s.uP', 'Y-m-d H:i:s' ) as $format ) {
			$parsed = \DateTimeImmutable::createFromFormat( '!' . $format, $value );
			$errors = \DateTimeImmutable::getLastErrors();
			$valid = $parsed instanceof \DateTimeImmutable
				&& ( false === $errors || ( 0 === (int) $errors['warning_count'] && 0 === (int) $errors['error_count'] ) )
				&& $parsed->format( $format ) === $value;
			if ( $valid ) {
				return $value;
			}
		}

		throw new \RuntimeException( 'ПЭК вернул некорректную дату статуса: ' . $field . '.' );
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
			if ( ! is_string( $item ) || '' === trim( $item ) || strlen( $item ) > 128 ) {
				throw new \RuntimeException( 'ПЭК вернул некорректный список статуса: ' . $field . '.' );
			}
			$result[] = trim( $item );
		}

		return array_values( array_unique( $result ) );
	}

	/** @param array<string,mixed> $row */
	private function actual_cost_candidate( array $row, string $checked_at ): ?ShipmentActualCost {
		if ( ! array_key_exists( 'services', $row ) ) {
			return null;
		}
		$services = $this->object_when_present( $row, 'services' );
		if ( ! array_key_exists( 'sum', $services ) ) {
			return null;
		}
		$sum = $services['sum'];
		if ( null === $sum || '' === $sum ) {
			return null;
		}
		if ( 0 === $sum || '0' === $sum || '0.00' === $sum || '0,00' === $sum ) {
			return null;
		}
		if ( is_bool( $sum ) || is_array( $sum ) || is_object( $sum ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректную стоимость услуг.' );
		}
		if ( is_string( $sum ) && 1 !== preg_match( '/^\d+(?:[.,]\d{1,2})?$/', trim( $sum ) ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректную стоимость услуг.' );
		}
		if ( is_int( $sum ) && $sum < 0 ) {
			throw new \RuntimeException( 'ПЭК вернул некорректную стоимость услуг.' );
		}
		if ( is_float( $sum ) && ! is_finite( $sum ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректную стоимость услуг.' );
		}
		if ( is_float( $sum ) && $sum < 0 ) {
			throw new \RuntimeException( 'ПЭК вернул некорректную стоимость услуг.' );
		}
		if ( ! is_int( $sum ) && ! is_float( $sum ) && ! is_string( $sum ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректную стоимость услуг.' );
		}
		$kopecks = MoneyParser::numeric_to_kopecks( (string) $sum );
		if ( null === $kopecks || $kopecks < 0 ) {
			throw new \RuntimeException( 'ПЭК вернул некорректную стоимость услуг.' );
		}
		if ( 0 === $kopecks ) {
			return null;
		}

		return new ShipmentActualCost( $kopecks, 'RUB', 'carrier', 'pek_cargos_status_services_sum', $checked_at );
	}
}

final class PekShipmentStatusNormalizationException extends \RuntimeException {
	/** @param array<string,mixed> $diagnostic */
	public function __construct( string $message, private array $diagnostic ) {
		parent::__construct( $message );
	}

	/** @return array<string,mixed> */
	public function diagnostic(): array {
		return $this->diagnostic;
	}
}
